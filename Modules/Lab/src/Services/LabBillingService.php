<?php

namespace Modules\Lab\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\IssueService;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Lab\Exceptions\LabBillingException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabOrderCharge;
use Modules\Lab\Models\LabTest;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Lab billing (LAB.G6) — a lab order accrues its test fee through the EXISTING billing engine, reconciling-to-
 * the-unit. The FINAL buildable Phase-3 gate. STRICTLY ORCHESTRATION — it adds NO pricing/charge/VAT/line-total
 * math. A lab test is a tenant-authored `TariffItem` (integer minor units, NO licensed pricing, keyed by the
 * LAB.G1 catalog item's code); a lab-order charge is `ChargeCaptureService::captureManual` (the engine resolves
 * + SNAPSHOTS the fee and computes the line total = quantity × its price); an outpatient invoice is the existing
 * `validateForPatientPeriod` → `createDraftFromCharges` → `issue` flow; an inpatient/ED patient's lab charges
 * instead join the stay/episode's discharge invoice via the existing `BedBillingService::invoiceStay` (same
 * gather-by-patient+period — no lab code needed), so the whole episode bills as ONE reconciling invoice. The
 * ED-G6 / surgery-G5 / bed-day (HOSPITAL.G6) pattern.
 *
 * FENCE: a price is a RATE (financial/administrative), never a clinical verdict — the lab fee is a plain
 * `TariffItem`, NOT driven by the result value or any computed abnormality (the result is a clinical record;
 * the fee is a tariff — kept separate).
 */
class LabBillingService
{
    public const CATALOG_KEY = 'lab';

    public function __construct(
        private readonly ChargeCaptureService $charges,
        private readonly ChargeValidator $validator,
        private readonly IssueService $issuer,
        private readonly TenantContext $tenantContext,
    ) {}

    /** Get-or-create the tenant's lab tariff catalog (effective-dated, valid from well in the past). */
    public function catalog(): TariffCatalog
    {
        return TariffCatalog::query()->firstOrCreate(
            ['key' => self::CATALOG_KEY, 'version' => 1],
            [
                'name' => 'Laboratory tests',
                'valid_from' => Carbon::create(2020, 1, 1)->toDateString(),
                'status' => TariffCatalog::STATUS_ACTIVE,
            ],
        );
    }

    /**
     * Price a LAB TEST — a tenant-authored `TariffItem` keyed by the LAB.G1 catalog item's code. Gated
     * `billing.manage`. Re-pricing updates the tariff item; PAST charges are unaffected (they snapshotted the
     * fee at capture). NO licensed pricing. This fee is a plain tariff — NOT driven by the result (the fence).
     */
    public function priceTest(User $actor, LabTest $labTest, int $priceMinor): TariffItem
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertPositive($priceMinor);

        $orderable = OrderableItem::query()->findOrFail($labTest->orderable_item_id);

        return $this->authorTariff((string) $orderable->code, (string) $orderable->name, $priceMinor);
    }

    /**
     * Capture a lab order's test charge through the EXISTING engine — one charge (1×) for the order's test code.
     * Gated `billing.manage`; tenant fail-closed. IDEMPOTENT: a lab order is billed once (the `lab_order_charges`
     * link). The engine resolves the tariff by code, SNAPSHOTS the fee, and computes the line total — NO money
     * math here. The service date is the resulted-time (a fact) when resulted, else the order date.
     */
    public function chargeOrder(User $actor, LabOrder $labOrder): Charge
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($labOrder->tenant_id, 'lab_order_id', $labOrder->id);

        // Idempotent: return the existing charge if the lab order is already billed.
        $existing = LabOrderCharge::query()->where('lab_order_id', $labOrder->id)->first();
        if ($existing !== null) {
            return Charge::query()->findOrFail($existing->charge_id);
        }

        $order = Order::query()->with(['orderableItem', 'results'])->findOrFail($labOrder->order_id);
        $code = $order->orderableItem?->code;
        if ($code === null || $code === '') {
            throw LabBillingException::testNotBillable($labOrder->id);
        }

        $patient = Patient::query()->findOrFail($labOrder->patient_id);
        $branch = Branch::query()->firstOrFail(); // charges are branch-attributed (the surgery precedent)

        $charge = $this->charges->captureManual($patient, $branch, $this->serviceDate($order), $code, 1, $actor);
        LabOrderCharge::query()->create(['lab_order_id' => $labOrder->id, 'charge_id' => $charge->id]);

        return $charge;
    }

    /**
     * Assemble an OUTPATIENT patient's validated, uninvoiced charges (INCLUDING the lab charge) on the lab
     * order's service day into an invoice via the EXISTING flow — validate → gather → draft → issue (gapless
     * number + PDF). NO new invoice logic; it reconciles-to-the-unit. For an INPATIENT/ED patient the lab
     * charges instead join the stay/episode's discharge invoice via the existing `BedBillingService::invoiceStay`
     * (same gather-by-patient+period) — do NOT call this for an admitted patient.
     */
    public function invoiceOrder(User $actor, LabOrder $labOrder): Invoice
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($labOrder->tenant_id, 'lab_order_id', $labOrder->id);

        $order = Order::query()->with('results')->findOrFail($labOrder->order_id);
        $patient = Patient::query()->findOrFail($labOrder->patient_id);
        $serviceDate = $this->serviceDate($order);
        $from = $serviceDate->copy()->startOfDay();
        $to = $serviceDate->copy()->endOfDay();

        $this->validator->validateForPatientPeriod($patient, $from, $to, $actor);

        $charges = Charge::query()
            ->where('patient_id', $patient->id)
            ->where('status', Charge::STATUS_VALIDATED)
            ->whereNull('invoice_id')
            ->whereBetween('service_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('service_date')
            ->orderBy('id')
            ->get();

        if ($charges->isEmpty()) {
            throw LabBillingException::nothingToInvoice($patient->id);
        }

        $draft = $this->issuer->createDraftFromCharges(
            $patient,
            $charges->all(),
            $actor,
            Invoice::PAYER_SELF_PAY,
            null,
            Carbon::now(),
            Carbon::now()->addDays(30),
        );

        return $this->issuer->issue($draft, $actor);
    }

    /**
     * The lab catalog's tariff items (for the pricing surface).
     *
     * @return Collection<int, TariffItem>
     */
    public function catalogTariffs(): Collection
    {
        return TariffItem::query()->where('tariff_catalog_id', $this->catalog()->id)->orderBy('code')->get();
    }

    /** The billing service date: the resulted-time (a fact) when resulted, else the order date. */
    private function serviceDate(Order $order): Carbon
    {
        $latest = $order->results->max('entered_at');

        return $latest instanceof Carbon ? $latest : $order->ordered_at;
    }

    private function authorTariff(string $code, string $description, int $priceMinor): TariffItem
    {
        return TariffItem::query()->updateOrCreate(
            ['tariff_catalog_id' => $this->catalog()->id, 'code' => $code],
            [
                'description' => $description,
                'unit_price_minor' => $priceMinor,
                'vat_rate_bp' => 0,
                'unit' => 'test',
                'requires_service_documentation' => false,
                'active' => true,
            ],
        );
    }

    private function assertPositive(int $priceMinor): void
    {
        if ($priceMinor <= 0) {
            throw LabBillingException::nonPositivePrice();
        }
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
