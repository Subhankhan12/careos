<?php

namespace Modules\Surgery\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * ALL-OR-NOTHING (QA-FIX.6a, P6-M10, D-208). This used to capture every charge first — each committing
     * in its OWN transaction inside `ChargeCaptureService::capture()` — and only then write the link rows.
     * A throw partway through (an unpriced consumable raises `TariffNotFoundForDateException`) therefore
     * left the earlier charges DURABLE and UNLINKED on the patient's account. Worse, those link rows are
     * also the idempotency key read at the top of this method, so the guard read empty afterwards and a
     * retry — the natural response to an error — re-captured everything that had already succeeded. The
     * mechanism meant to prevent double-billing was the one that permitted it.
     *
     * The unit of idempotency here is the CASE ("a case is billed once"), so the case's whole charge set is
     * the unit of atomicity: one transaction around every capture AND its link row, with each link written
     * beside its charge (the `BedBillingService::accrueBedDays()` pairing, whose unit is a bed-DAY and which
     * has always been transactional). A failure now leaves nothing behind, so a retry starts clean.
     *
     * The case row is locked FOR UPDATE first so two concurrent captures serialise rather than both reading
     * an empty idempotency guard and both capturing — the `lockResource` / `lockTheatre` idiom this codebase
     * already uses in {@see TheatreSchedulingService::bookSlot()}.
     *
     * @return Collection<int, Charge>
     */
    public function chargeCase(User $actor, SurgicalCase $case, ?string $procedureCode = null, ?int $theatreMinutes = null): Collection
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);

        return DB::transaction(function () use ($actor, $case, $procedureCode, $theatreMinutes): Collection {
            $this->lockCase($case);

            // Idempotent: return the existing charges if the case is already billed. Read INSIDE the lock,
            // so a concurrent capture cannot pass this check at the same moment.
            $existing = SurgicalCaseCharge::query()->where('surgical_case_id', $case->id)->pluck('charge_id');
            if ($existing->isNotEmpty()) {
                return Charge::query()->whereIn('id', $existing->all())->get();
            }

            $patient = Patient::query()->findOrFail($case->patient_id);
            $branch = Branch::query()->firstOrFail(); // charges are branch-attributed
            $serviceDate = $case->completed_at ?? $case->scheduled_at;

            /** @var Collection<int, Charge> $captured */
            $captured = new Collection;

            $capture = function (string $code, int $quantity) use ($actor, $case, $patient, $branch, $serviceDate, $captured): void {
                $charge = $this->charges->captureManual($patient, $branch, $serviceDate, $code, $quantity, $actor);
                // The link is written BESIDE its charge, never in a later pass: within this transaction no
                // charge can exist without the row that makes it findable and makes re-billing impossible.
                SurgicalCaseCharge::query()->create(['surgical_case_id' => $case->id, 'charge_id' => $charge->id]);
                $captured->push($charge);
            };

            if ($procedureCode !== null && trim($procedureCode) !== '') {
                $capture($procedureCode, 1);
            }
            if ($theatreMinutes !== null && $theatreMinutes > 0) {
                $capture(self::THEATRE_TIME_CODE, $theatreMinutes);
            }
            foreach ($this->pricedUsageTotals($case) as $code => $quantity) {
                $capture((string) $code, (int) $quantity);
            }

            return $captured;
        });
    }

    /**
     * Serialise concurrent captures for one case: a `SELECT … FOR UPDATE` on the case row, so N racing
     * callers queue on it and only the first finds the idempotency guard empty. Fails closed if the case is
     * not in the tenant (the `TheatreSchedulingService::lockTheatre` idiom).
     */
    private function lockCase(SurgicalCase $case): void
    {
        $rows = DB::select(
            'select id from surgical_cases where tenant_id = ? and id = ? for update',
            [(string) $this->tenantContext->id(), $case->id],
        );

        if ($rows === []) {
            throw CrossTenantReferenceException::forAttribute('surgical_case_id', (string) $case->id);
        }
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
