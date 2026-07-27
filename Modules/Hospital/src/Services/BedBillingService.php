<?php

namespace Modules\Hospital\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\IssueService;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\BedDayAccrual;
use Modules\Hospital\Models\Stay;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Bed-to-billing (HOSPITAL.G6) — an inpatient stay accrues bed-day + service charges through the
 * EXISTING billing engine, and discharge assembles them into an invoice that reconciles-to-the-unit.
 * This is STRICTLY ORCHESTRATION — it adds NO pricing/charge/VAT/line-total math. The engine prices
 * everything: a bed-day is a tenant-authored `TariffItem`, accrual is `ChargeCaptureService::captureManual`
 * (the engine resolves + snapshots the fee and computes the line total), and the discharge invoice is the
 * existing `ChargeValidator::validateForPatientPeriod` → `IssueService::createDraftFromCharges` → `issue`
 * flow (gapless number + PDF). Reconciliation is the existing ReconciliationEngine (I4 handles N charges →
 * 1 invoice natively). Integer minor units; fee snapshotted at capture.
 *
 * FENCE: billing is financial/administrative — a per-diem is a RATE, not a clinical acuity.
 */
class BedBillingService
{
    public const CATALOG_KEY = 'hospital';

    /**
     * A GENERIC starter per-diem template — the tenant's own codes (NO licensed code set), placeholder
     * rates in integer minor units the tenant edits. One rate per bed type.
     *
     * @var list<array{type: string, code: string, name: string, rate: int}>
     */
    public const STARTER = [
        ['type' => Bed::TYPE_GENERAL, 'code' => 'BED-DAY-GENERAL', 'name' => 'Bed-day (general ward)', 'rate' => 50000],
        ['type' => Bed::TYPE_ICU, 'code' => 'BED-DAY-ICU', 'name' => 'Bed-day (ICU)', 'rate' => 200000],
        ['type' => Bed::TYPE_ISOLATION, 'code' => 'BED-DAY-ISOLATION', 'name' => 'Bed-day (isolation)', 'rate' => 80000],
    ];

    public function __construct(
        private readonly ChargeCaptureService $charges,
        private readonly ChargeValidator $validator,
        private readonly IssueService $issuer,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Get-or-create the tenant's per-diem tariff catalog (the existing effective-dated Billing catalog;
     * valid from well in the past + open-ended so any service date resolves).
     */
    public function catalog(): TariffCatalog
    {
        return TariffCatalog::query()->firstOrCreate(
            ['key' => self::CATALOG_KEY, 'version' => 1],
            [
                'name' => 'Inpatient per-diem',
                'valid_from' => Carbon::create(2020, 1, 1)->toDateString(),
                'status' => TariffCatalog::STATUS_ACTIVE,
            ],
        );
    }

    /**
     * Seed the generic starter per-diem rates for the current tenant. Idempotent by code; gated
     * `billing.manage`. NO licensed code set — the tenant edits these like any other tariff item.
     */
    public function seedStarter(User $actor): int
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $catalog = $this->catalog();
        $created = 0;

        foreach (self::STARTER as $starter) {
            $item = TariffItem::query()->firstOrCreate(
                ['tariff_catalog_id' => $catalog->id, 'code' => $starter['code']],
                [
                    'description' => $starter['name'],
                    'unit_price_minor' => $starter['rate'],
                    'vat_rate_bp' => 0,
                    'unit' => 'bed-day',
                    'requires_service_documentation' => false,
                    'active' => true,
                ],
            );
            if ($item->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    public function bedDayCode(string $bedType): string
    {
        return 'BED-DAY-'.strtoupper($bedType);
    }

    /**
     * Accrue one bed-day Charge per calendar day the stay has occupied (from admission to `upTo`, or to
     * discharge/now), at the current bed's per-diem rate — through the EXISTING ChargeCaptureService.
     * IDEMPOTENT: the (stay, service_date) ledger unique key means a re-run never double-charges (the
     * nursing:materialize-visits discipline). Returns the number of new bed-days accrued.
     */
    public function accrueBedDays(User $actor, Stay $stay, ?Carbon $upTo = null): int
    {
        // An admitted (or since-discharged) stay always carries its current bed; resolve the per-diem
        // code from that bed's type. Fail closed if the bed is somehow gone rather than mis-price it.
        $bed = Bed::query()->findOrFail($stay->current_bed_id);
        $code = $this->bedDayCode($bed->bed_type);
        $patient = Patient::query()->findOrFail($stay->patient_id);
        $branch = Branch::query()->findOrFail($stay->branch_id);

        $day = $stay->admitted_at->copy()->startOfDay();
        $to = ($upTo ?? $stay->discharged_at ?? Carbon::now())->copy()->startOfDay();
        $accrued = 0;

        for (; $day->lessThanOrEqualTo($to); $day->addDay()) {
            $date = $day->toDateString();

            // Fast idempotency check; the unique key is the hard guard (a concurrent race rolls back).
            if (BedDayAccrual::query()->where('stay_id', $stay->id)->where('service_date', $date)->exists()) {
                continue;
            }

            DB::transaction(function () use ($actor, $patient, $branch, $date, $code, $stay): void {
                $charge = $this->charges->captureManual($patient, $branch, $date, $code, 1, $actor);
                BedDayAccrual::query()->create([
                    'stay_id' => $stay->id,
                    'service_date' => $date,
                    'charge_id' => $charge->id,
                ]);
            });
            $accrued++;
        }

        return $accrued;
    }

    /**
     * Assemble the stay's accrued charges (bed-days + any services in the stay window) into an invoice via
     * the EXISTING flow — validate the period, gather the validated/uninvoiced charges, draft, and issue
     * (gapless number + PDF). NO new invoice logic; the invoice reconciles-to-the-unit (ReconciliationEngine).
     */
    public function invoiceStay(User $actor, Stay $stay): Invoice
    {
        Gate::forUser($actor)->authorize('billing.manage');
        if ($stay->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('stay_id', (string) $stay->id);
        }

        $to = $stay->discharged_at ?? Carbon::now();
        $this->accrueBedDays($actor, $stay, $to->copy()); // ensure the final bed-days are captured

        $from = $stay->admitted_at->copy()->startOfDay();
        // Validate the stay's charges for the period (draft -> validated), then gather them.
        $this->validator->validateForPatientPeriod($this->patient($stay), $from, $to, $actor);

        $charges = Charge::query()
            ->where('patient_id', $stay->patient_id)
            ->where('status', Charge::STATUS_VALIDATED)
            ->whereNull('invoice_id')
            ->whereBetween('service_date', [$from->toDateString(), $to->copy()->startOfDay()->toDateString()])
            ->orderBy('service_date')
            ->orderBy('id')
            ->get();

        if ($charges->isEmpty()) {
            throw AdmissionException::nothingToInvoice($stay->id);
        }

        $draft = $this->issuer->createDraftFromCharges(
            $this->patient($stay),
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
     * The invoice(s) a stay's charges landed on — a pure READ for the closed-episode view (HOSPITAL.G7),
     * traversing the bed-day ledger → charges → invoice. NO billing math. Since a stay's bed-day + service
     * charges assemble onto one invoice, the bed-day ledger is the reliable stay→invoice bridge.
     *
     * @return Collection<int, Invoice>
     */
    public function invoicesForStay(Stay $stay): Collection
    {
        $chargeIds = BedDayAccrual::query()->where('stay_id', $stay->id)->pluck('charge_id');
        if ($chargeIds->isEmpty()) {
            return new Collection;
        }

        $invoiceIds = Charge::query()
            ->whereIn('id', $chargeIds)
            ->whereNotNull('invoice_id')
            ->pluck('invoice_id')
            ->unique()
            ->values();

        if ($invoiceIds->isEmpty()) {
            return new Collection;
        }

        return Invoice::query()->whereIn('id', $invoiceIds)->orderBy('issue_date')->orderBy('id')->get();
    }

    private function patient(Stay $stay): Patient
    {
        return Patient::query()->findOrFail($stay->patient_id);
    }
}
