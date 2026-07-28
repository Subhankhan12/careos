<?php

namespace Modules\ED\Services;

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
use Modules\ED\Exceptions\EdBillingException;
use Modules\ED\Models\EdVisit;
use Modules\ED\Models\EdVisitCharge;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * ED billing (ED.G6) — an ED visit accrues charges (the attendance/facility fee + a charge per ED service)
 * through the EXISTING billing engine, reconciling-to-the-unit. STRICTLY ORCHESTRATION — it adds NO
 * pricing/charge/VAT/line-total math. Each billable thing is a tenant-authored `TariffItem` (integer minor
 * units, NO licensed pricing); a visit charge is `ChargeCaptureService::captureManual` (the engine resolves +
 * SNAPSHOTS the fee and computes the line total = quantity × its price); a discharged visit's invoice is the
 * existing `validateForPatientPeriod` → `createDraftFromCharges` → `issue` flow; an ADMITTED patient's ED
 * charges instead join the inpatient stay's discharge invoice via the existing HOSPITAL.G6 `invoiceStay`
 * (same gather-by-patient+period — no ED code needed), so the whole emergency→inpatient episode bills as ONE
 * reconciling invoice. The surgery-G5 / bed-day (HOSPITAL.G6) pattern.
 *
 * FENCE: a price is a RATE (financial/administrative), never a clinical/appropriateness verdict — the ED
 * attendance fee is a plain `TariffItem`, NOT driven by the recorded triage acuity (the acuity is a clinical
 * record; the fee is a tariff — kept separate).
 */
class EdBillingService
{
    public const CATALOG_KEY = 'ed';

    public const ATTENDANCE_CODE = 'ED-ATTENDANCE';

    public function __construct(
        private readonly ChargeCaptureService $charges,
        private readonly ChargeValidator $validator,
        private readonly IssueService $issuer,
        private readonly TenantContext $tenantContext,
    ) {}

    /** Get-or-create the tenant's ED tariff catalog (effective-dated, valid from well in the past). */
    public function catalog(): TariffCatalog
    {
        return TariffCatalog::query()->firstOrCreate(
            ['key' => self::CATALOG_KEY, 'version' => 1],
            [
                'name' => 'Emergency Department services',
                'valid_from' => Carbon::create(2020, 1, 1)->toDateString(),
                'status' => TariffCatalog::STATUS_ACTIVE,
            ],
        );
    }

    /**
     * Price the ED ATTENDANCE / facility fee — a tenant-authored `TariffItem` (a fixed code). Gated
     * `billing.manage`. Re-pricing updates the tariff item; PAST charges are unaffected (they snapshotted the
     * fee at capture). NO licensed pricing. This fee is a plain tariff — NOT driven by acuity (the fence).
     */
    public function priceAttendance(User $actor, int $priceMinor): TariffItem
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertPositive($priceMinor);

        return $this->authorTariff(self::ATTENDANCE_CODE, 'ED attendance', $priceMinor, 'attendance');
    }

    /** Price an ED SERVICE — a tenant-authored `TariffItem` (its own code). Gated `billing.manage`. */
    public function priceService(User $actor, string $code, string $name, int $priceMinor): TariffItem
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertPositive($priceMinor);

        return $this->authorTariff($code, $name, $priceMinor, 'service');
    }

    /**
     * Capture a visit's charges through the EXISTING engine — the attendance (1×, when priced+selected) + one
     * charge per selected ED service code (1×). Gated `billing.manage`; tenant fail-closed. IDEMPOTENT: a
     * visit is billed once (the `ed_visit_charges` link). The engine resolves each tariff by code, SNAPSHOTS
     * the fee, and computes the line total — NO money math here.
     *
     * @param  list<string>  $serviceCodes
     * @return Collection<int, Charge>
     */
    public function chargeVisit(User $actor, EdVisit $visit, bool $attendance = true, array $serviceCodes = []): Collection
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($visit->tenant_id, 'ed_visit_id', $visit->id);

        // Idempotent: return the existing charges if the visit is already billed.
        $existing = EdVisitCharge::query()->where('ed_visit_id', $visit->id)->pluck('charge_id');
        if ($existing->isNotEmpty()) {
            return Charge::query()->whereIn('id', $existing->all())->get();
        }

        $patient = Patient::query()->findOrFail($visit->patient_id);
        $branch = Branch::query()->findOrFail($visit->branch_id);
        $serviceDate = $visit->dispositioned_at ?? $visit->arrived_at;

        /** @var Collection<int, Charge> $captured */
        $captured = new Collection;

        if ($attendance) {
            $captured->push($this->charges->captureManual($patient, $branch, $serviceDate, self::ATTENDANCE_CODE, 1, $actor));
        }
        foreach ($serviceCodes as $code) {
            if (trim($code) === '') {
                continue;
            }
            $captured->push($this->charges->captureManual($patient, $branch, $serviceDate, $code, 1, $actor));
        }

        foreach ($captured as $charge) {
            EdVisitCharge::query()->create(['ed_visit_id' => $visit->id, 'charge_id' => $charge->id]);
        }

        return $captured;
    }

    /**
     * Assemble a DISCHARGED/transferred patient's validated, uninvoiced charges (INCLUDING the ED charges) on
     * the visit's service day into an invoice via the EXISTING flow — validate → gather → draft → issue
     * (gapless number + PDF). NO new invoice logic; it reconciles-to-the-unit. For an ADMITTED patient the ED
     * charges instead join the stay's discharge invoice via the existing HOSPITAL.G6 `invoiceStay` (same
     * gather-by-patient+period) — do NOT call this for an admitted visit.
     */
    public function invoiceVisit(User $actor, EdVisit $visit): Invoice
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($visit->tenant_id, 'ed_visit_id', $visit->id);

        $patient = Patient::query()->findOrFail($visit->patient_id);
        $serviceDate = $visit->dispositioned_at ?? $visit->arrived_at;
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
            throw EdBillingException::nothingToInvoice($patient->id);
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
     * The ED catalog's tariff items (for the pricing surface — the attendance + services).
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

    private function assertPositive(int $priceMinor): void
    {
        if ($priceMinor <= 0) {
            throw EdBillingException::nonPositivePrice();
        }
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
