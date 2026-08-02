<?php

namespace Modules\Radiology\Services;

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
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Exceptions\RadiologyBillingException;
use Modules\Radiology\Models\RadiologyExam;
use Modules\Radiology\Models\RadiologyOrder;
use Modules\Radiology\Models\RadiologyOrderCharge;

/**
 * Radiology billing (RAD.G5) — an imaging order accrues its exam fee through the EXISTING billing engine,
 * reconciling-to-the-unit. The LAST buildable Phase-4 gate. STRICTLY ORCHESTRATION — it adds NO
 * pricing/charge/VAT/line-total math (the LAB.G6 / ED.G6 / surgery-G5 pattern). An imaging exam is a
 * tenant-authored `TariffItem` (integer minor units, NO licensed pricing, keyed by the RAD.G1 exam's code); an
 * imaging-order charge is `ChargeCaptureService::captureManual` (the engine resolves + SNAPSHOTS the fee and
 * computes the line total = quantity × its price); an outpatient invoice is the existing
 * `validateForPatientPeriod` → `createDraftFromCharges` → `issue` flow; an inpatient/ED patient's imaging
 * charges instead join the stay/episode's discharge invoice via the existing `BedBillingService::invoiceStay`
 * (same gather-by-patient+period — no radiology code needed), so the whole episode bills as ONE reconciling
 * invoice.
 *
 * FENCE: a price is a RATE (financial/administrative), never a clinical verdict — the exam fee is a plain
 * `TariffItem`, NOT driven by the report/finding or any modality-severity (the report is a clinical record; the
 * fee is a tariff — kept separate).
 */
class RadiologyBillingService
{
    public const CATALOG_KEY = 'radiology';

    public function __construct(
        private readonly ChargeCaptureService $charges,
        private readonly ChargeValidator $validator,
        private readonly IssueService $issuer,
        private readonly TenantContext $tenantContext,
    ) {}

    /** Get-or-create the tenant's radiology tariff catalog (effective-dated, valid from well in the past). */
    public function catalog(): TariffCatalog
    {
        return TariffCatalog::query()->firstOrCreate(
            ['key' => self::CATALOG_KEY, 'version' => 1],
            [
                'name' => 'Radiology exams',
                'valid_from' => Carbon::create(2020, 1, 1)->toDateString(),
                'status' => TariffCatalog::STATUS_ACTIVE,
            ],
        );
    }

    /**
     * Price an IMAGING EXAM — a tenant-authored `TariffItem` keyed by the RAD.G1 exam's code. Gated
     * `billing.manage`. Re-pricing updates the tariff item; PAST charges are unaffected (they snapshotted the
     * fee at capture). NO licensed pricing. This fee is a plain tariff — NOT driven by the report (the fence).
     */
    public function priceExam(User $actor, RadiologyExam $exam, int $priceMinor): TariffItem
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertPositive($priceMinor);

        $orderable = OrderableItem::query()->findOrFail($exam->orderable_item_id);

        return $this->authorTariff((string) $orderable->code, (string) $orderable->name, $priceMinor);
    }

    /**
     * Capture an imaging order's exam charge through the EXISTING engine — one charge (1×) for the order's exam
     * code. Gated `billing.manage`; tenant fail-closed. IDEMPOTENT: an imaging order is billed once (the
     * `radiology_order_charges` link). The engine resolves the tariff by code, SNAPSHOTS the fee, and computes
     * the line total — NO money math here. The service date is the exam's order date.
     */
    public function chargeOrder(User $actor, RadiologyOrder $radiologyOrder): Charge
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($radiologyOrder->tenant_id, 'radiology_order_id', $radiologyOrder->id);

        // Idempotent: return the existing charge if the imaging order is already billed.
        $existing = RadiologyOrderCharge::query()->where('radiology_order_id', $radiologyOrder->id)->first();
        if ($existing !== null) {
            return Charge::query()->findOrFail($existing->charge_id);
        }

        $order = Order::query()->with('orderableItem')->findOrFail($radiologyOrder->order_id);
        $code = $order->orderableItem?->code;
        if ($code === null || $code === '') {
            throw RadiologyBillingException::examNotBillable($radiologyOrder->id);
        }

        $patient = Patient::query()->findOrFail($radiologyOrder->patient_id);
        $branch = Branch::query()->firstOrFail(); // charges are branch-attributed (the lab/surgery precedent)

        $charge = $this->charges->captureManual($patient, $branch, $order->ordered_at, $code, 1, $actor);
        RadiologyOrderCharge::query()->create(['radiology_order_id' => $radiologyOrder->id, 'charge_id' => $charge->id]);

        return $charge;
    }

    /**
     * Assemble an OUTPATIENT patient's validated, uninvoiced charges (INCLUDING the imaging charge) on the
     * order's service day into an invoice via the EXISTING flow — validate → gather → draft → issue (gapless
     * number + PDF). NO new invoice logic; it reconciles-to-the-unit. For an INPATIENT/ED patient the imaging
     * charges instead join the stay/episode's discharge invoice via the existing `BedBillingService::invoiceStay`
     * (same gather-by-patient+period) — do NOT call this for an admitted patient.
     */
    public function invoiceOrder(User $actor, RadiologyOrder $radiologyOrder): Invoice
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($radiologyOrder->tenant_id, 'radiology_order_id', $radiologyOrder->id);

        $order = Order::query()->findOrFail($radiologyOrder->order_id);
        $patient = Patient::query()->findOrFail($radiologyOrder->patient_id);
        $serviceDate = $order->ordered_at;
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
            throw RadiologyBillingException::nothingToInvoice($patient->id);
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
     * The radiology catalog's tariff items (for the pricing surface).
     *
     * @return Collection<int, TariffItem>
     */
    public function catalogTariffs(): Collection
    {
        return TariffItem::query()->where('tariff_catalog_id', $this->catalog()->id)->orderBy('code')->get();
    }

    private function authorTariff(string $code, string $description, int $priceMinor): TariffItem
    {
        return TariffItem::query()->updateOrCreate(
            ['tariff_catalog_id' => $this->catalog()->id, 'code' => $code],
            [
                'description' => $description,
                'unit_price_minor' => $priceMinor,
                'vat_rate_bp' => 0,
                'unit' => 'exam',
                'requires_service_documentation' => false,
                'active' => true,
            ],
        );
    }

    private function assertPositive(int $priceMinor): void
    {
        if ($priceMinor <= 0) {
            throw RadiologyBillingException::nonPositivePrice();
        }
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
