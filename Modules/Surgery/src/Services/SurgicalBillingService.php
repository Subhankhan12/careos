<?php

namespace Modules\Surgery\Services;

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
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\SurgicalBillingException;
use Modules\Surgery\Models\CaseItemUsage;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseCharge;
use Modules\Surgery\Models\SurgicalItem;

/**
 * Surgical billing (SURGERY.G5) — a surgical case accrues charges (procedure + theatre-time +
 * consumables/implants) through the EXISTING billing engine, reconciling-to-the-unit. STRICTLY ORCHESTRATION
 * — it adds NO pricing/charge/VAT/line-total math. Each billable thing is a tenant-authored `TariffItem`
 * (integer minor units); a case charge is `ChargeCaptureService::captureManual` (the engine resolves +
 * SNAPSHOTS the fee and computes the line total = quantity × its price); the invoice is the existing
 * `validateForPatientPeriod` → `createDraftFromCharges` → `issue` flow; reconciliation is the existing
 * `ReconciliationEngine`. The pharmacy G5 / bed-day (HOSPITAL.G6) pattern.
 *
 * FENCE: a price is a RATE (financial/administrative), never a clinical/appropriateness verdict.
 */
class SurgicalBillingService
{
    public const CATALOG_KEY = 'surgery';

    public const THEATRE_TIME_CODE = 'THEATRE-TIME';

    public function __construct(
        private readonly ChargeCaptureService $charges,
        private readonly ChargeValidator $validator,
        private readonly IssueService $issuer,
        private readonly TenantContext $tenantContext,
    ) {}

    /** Get-or-create the tenant's surgery tariff catalog (effective-dated, valid from well in the past). */
    public function catalog(): TariffCatalog
    {
        return TariffCatalog::query()->firstOrCreate(
            ['key' => self::CATALOG_KEY, 'version' => 1],
            [
                'name' => 'Surgical services',
                'valid_from' => Carbon::create(2020, 1, 1)->toDateString(),
                'status' => TariffCatalog::STATUS_ACTIVE,
            ],
        );
    }

    /**
     * Price a surgical item (consumable / implant) — a TENANT-AUTHORED `TariffItem` in the surgery catalog,
     * linked from the item. Gated `billing.manage`. Re-pricing updates the tariff item; PAST charges are
     * unaffected (they snapshotted the fee at capture). NO licensed pricing.
     */
    public function priceItem(User $actor, SurgicalItem $item, int $priceMinor, ?string $unit = null): TariffItem
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($item->tenant_id, 'surgical_item_id', $item->id);
        $this->assertPositive($priceMinor);

        $tariffItem = $this->authorTariff($item->code, $item->name, $priceMinor, $unit ?? $item->unit);
        $item->forceFill(['tariff_item_id' => $tariffItem->id])->save();

        return $tariffItem;
    }

    /** Price a surgical PROCEDURE — a tenant-authored `TariffItem` (its own code). Gated `billing.manage`. */
    public function priceProcedure(User $actor, string $code, string $name, int $priceMinor): TariffItem
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertPositive($priceMinor);

        return $this->authorTariff($code, $name, $priceMinor, 'procedure');
    }

    /** Price THEATRE TIME — a tenant-authored `TariffItem` (per theatre-minute / block). Gated `billing.manage`. */
    public function priceTheatreTime(User $actor, int $priceMinor, ?string $unit = null): TariffItem
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertPositive($priceMinor);

        return $this->authorTariff(self::THEATRE_TIME_CODE, 'Theatre time', $priceMinor, $unit ?? 'theatre-minute');
    }

    /**
     * Capture a case's charges through the EXISTING engine — the procedure (1×), theatre-time (×minutes), and
     * one charge per PRICED consumable/implant used (×total used, from the G4 usages). Gated `billing.manage`;
     * tenant fail-closed. IDEMPOTENT: a case is billed once (the `surgical_case_charges` link). The engine
     * resolves each tariff by code, SNAPSHOTS the fee, and computes the line total — NO money math here.
     *
     * @return Collection<int, Charge>
     */
    public function chargeCase(User $actor, SurgicalCase $case, ?string $procedureCode = null, ?int $theatreMinutes = null): Collection
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);

        // Idempotent: return the existing charges if the case is already billed.
        $existing = SurgicalCaseCharge::query()->where('surgical_case_id', $case->id)->pluck('charge_id');
        if ($existing->isNotEmpty()) {
            return Charge::query()->whereIn('id', $existing->all())->get();
        }

        $patient = Patient::query()->findOrFail($case->patient_id);
        $branch = Branch::query()->firstOrFail(); // charges are branch-attributed
        $serviceDate = $case->completed_at ?? $case->scheduled_at;

        /** @var Collection<int, Charge> $captured */
        $captured = new Collection;

        if ($procedureCode !== null && trim($procedureCode) !== '') {
            $captured->push($this->charges->captureManual($patient, $branch, $serviceDate, $procedureCode, 1, $actor));
        }
        if ($theatreMinutes !== null && $theatreMinutes > 0) {
            $captured->push($this->charges->captureManual($patient, $branch, $serviceDate, self::THEATRE_TIME_CODE, $theatreMinutes, $actor));
        }
        foreach ($this->pricedUsageTotals($case) as $code => $quantity) {
            $captured->push($this->charges->captureManual($patient, $branch, $serviceDate, $code, $quantity, $actor));
        }

        foreach ($captured as $charge) {
            SurgicalCaseCharge::query()->create(['surgical_case_id' => $case->id, 'charge_id' => $charge->id]);
        }

        return $captured;
    }

    /**
     * Assemble the patient's validated, uninvoiced charges (INCLUDING the surgical charges) on the case's
     * service day into an invoice via the EXISTING flow — validate → gather → draft → issue (gapless number +
     * PDF). NO new invoice logic; it reconciles-to-the-unit. For INPATIENT, the surgical charges instead join
     * the stay's discharge invoice via the existing HOSPITAL.G6 `invoiceStay` (same gather-by-patient+period).
     */
    public function invoiceCase(User $actor, SurgicalCase $case): Invoice
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);

        $patient = Patient::query()->findOrFail($case->patient_id);
        $serviceDate = ($case->completed_at ?? $case->scheduled_at);
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
            throw SurgicalBillingException::nothingToInvoice($patient->id);
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
     * The surgery catalog's tariff items (for the pricing surface — procedures + theatre-time).
     *
     * @return Collection<int, TariffItem>
     */
    public function catalogTariffs(): Collection
    {
        return TariffItem::query()->where('tariff_catalog_id', $this->catalog()->id)->orderBy('code')->get();
    }

    private function authorTariff(string $code, string $description, int $priceMinor, string $unit): TariffItem
    {
        return TariffItem::query()->updateOrCreate(
            ['tariff_catalog_id' => $this->catalog()->id, 'code' => $code],
            [
                'description' => $description,
                'unit_price_minor' => $priceMinor,
                'vat_rate_bp' => 0,
                'unit' => $unit,
                'requires_service_documentation' => false,
                'active' => true,
            ],
        );
    }

    /**
     * The total used quantity per PRICED item code for a case (from the G4 usages). Unpriced items do not bill.
     *
     * @return array<string, int>
     */
    private function pricedUsageTotals(SurgicalCase $case): array
    {
        $totals = [];
        foreach (CaseItemUsage::query()->where('surgical_case_id', $case->id)->with('surgicalItem')->get() as $usage) {
            $item = $usage->surgicalItem;
            if ($item === null || ! $item->isPriced()) {
                continue;
            }
            $totals[$item->code] = ($totals[$item->code] ?? 0) + $usage->quantity;
        }

        return $totals;
    }

    private function assertPositive(int $priceMinor): void
    {
        if ($priceMinor <= 0) {
            throw SurgicalBillingException::nonPositivePrice();
        }
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
