<?php

namespace Modules\Pharmacy\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Billing\Services\ChargeValidator;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Exceptions\PharmacyBillingException;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\DispenseCharge;
use Modules\Pharmacy\Models\FormularyItem;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Pharmacy billing (PHARMACY.G5) — a dispensed medication accrues a charge through the EXISTING billing
 * engine, reconciling-to-the-unit. STRICTLY ORCHESTRATION — it adds NO pricing/charge/VAT/line-total math.
 * A med's price is a tenant-authored `TariffItem` (integer minor units); the dispense charge is
 * `ChargeCaptureService::captureManual` (the engine resolves + SNAPSHOTS the fee and computes the line
 * total); the invoice is the existing `validateForPatientPeriod` → `createDraftFromCharges` → `issue` flow;
 * reconciliation is the existing `ReconciliationEngine`. The bed-day (HOSPITAL.G6) pattern.
 *
 * FENCE: a med price is a RATE (financial/administrative), never a safety/appropriateness verdict; there is
 * no cost-based substitution suggestion (substitution/safety is the certified-partner seam, not billing).
 */
class PharmacyBillingService
{
    public const CATALOG_KEY = 'pharmacy';

    public function __construct(
        private readonly ChargeCaptureService $charges,
        private readonly ChargeValidator $validator,
        private readonly IssueService $issuer,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Get-or-create the tenant's pharmacy tariff catalog (the existing effective-dated Billing catalog;
     * valid from well in the past + open-ended so any service date resolves).
     */
    public function catalog(): TariffCatalog
    {
        return TariffCatalog::query()->firstOrCreate(
            ['key' => self::CATALOG_KEY, 'version' => 1],
            [
                'name' => 'Pharmacy medications',
                'valid_from' => Carbon::create(2020, 1, 1)->toDateString(),
                'status' => TariffCatalog::STATUS_ACTIVE,
            ],
        );
    }

    /**
     * Set a formulary item's price — a TENANT-AUTHORED `TariffItem` in the pharmacy catalog (integer minor
     * units; NO licensed drug pricing), linked from the formulary item. Gated `billing.manage`. Re-pricing
     * updates the tariff item; PAST charges are unaffected (they snapshotted the fee at capture).
     */
    public function priceItem(User $actor, FormularyItem $item, int $priceMinor, ?string $unit = null): TariffItem
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($item, 'formulary_item_id', $item->id);

        if ($priceMinor <= 0) {
            throw PharmacyBillingException::nonPositivePrice();
        }

        $tariffItem = TariffItem::query()->updateOrCreate(
            ['tariff_catalog_id' => $this->catalog()->id, 'code' => $item->code],
            [
                'description' => $item->name,
                'unit_price_minor' => $priceMinor,
                'vat_rate_bp' => 0,
                'unit' => $unit ?? 'unit',
                'requires_service_documentation' => false,
                'active' => true,
            ],
        );

        $item->forceFill(['tariff_item_id' => $tariffItem->id])->save();

        return $tariffItem;
    }

    /**
     * Capture the Billing charge for a dispense (PHARMACY.G4→G5) through the EXISTING engine. Returns null if
     * the med is unpriced (no charge). IDEMPOTENT via the `dispense_charges` unique key (a dispense is charged
     * once). The engine resolves the tariff by code, snapshots the fee, and computes the line total —
     * quantity × the engine's price. NO money math here.
     */
    public function chargeForDispense(User $actor, Dispense $dispense): ?Charge
    {
        $item = FormularyItem::query()->find($dispense->formulary_item_id);
        if ($item === null || ! $item->isPriced()) {
            return null; // unpriced meds do not bill
        }

        // Idempotent: a dispense is charged once.
        $existing = DispenseCharge::query()->where('dispense_id', $dispense->id)->first();
        if ($existing !== null) {
            return Charge::query()->find($existing->charge_id);
        }

        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($dispense, 'dispense_id', $dispense->id);

        $patient = Patient::query()->findOrFail($dispense->patient_id);
        $branch = Branch::query()->firstOrFail(); // the tenant's pharmacy site (charges are branch-attributed)

        $charge = $this->charges->captureManual($patient, $branch, $dispense->dispensed_at, $item->code, $dispense->quantity, $actor);

        DispenseCharge::query()->create(['dispense_id' => $dispense->id, 'charge_id' => $charge->id]);

        return $charge;
    }

    /**
     * Assemble a patient's validated, uninvoiced charges (INCLUDING dispensed-med charges) in a period into
     * an invoice via the EXISTING flow — validate, gather, draft, issue (gapless number + PDF). NO new invoice
     * logic; the invoice reconciles-to-the-unit (ReconciliationEngine). For INPATIENT, pharmacy charges join
     * the stay's discharge invoice via the existing HOSPITAL.G6 `invoiceStay` (same gather-by-patient+period).
     */
    public function invoicePatient(User $actor, Patient $patient, Carbon $from, Carbon $to): Invoice
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $this->assertSameTenant($patient, 'patient_id', $patient->id);

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
            throw PharmacyBillingException::nothingToInvoice($patient->id);
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

    private function assertSameTenant(object $model, string $attribute, string $id): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
