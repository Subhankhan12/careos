<?php

namespace Modules\Dental\Services;

use Illuminate\Support\Carbon;
use Modules\Billing\Models\Charge;
use Modules\Billing\Services\ChargeCaptureService;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Models\DentalProcedureCharge;
use Modules\Dental\Support\ToothNotation;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Charge a dental procedure — a THIN wrapper that reuses the EXISTING billing engine and
 * adds NO pricing/charge/VAT/line-total math of its own. The procedure's fee resolves +
 * snapshots through {@see ChargeCaptureService::captureManual()} (the tested tariff path,
 * D-F1/D-F2), so the resulting `charge` flows into the existing invoice → reconciliation →
 * dunning → PDF pipeline unchanged.
 *
 * The light tooth link (DENTAL.G3): when a tooth-scoped procedure is charged, tie the
 * resulting charge to the odontogram tooth/surface. The full perform-a-procedure workflow
 * is DENTAL.G4. Gated (inside ChargeCaptureService) on `billing.manage`.
 */
class DentalChargeService
{
    public function __construct(
        private readonly ChargeCaptureService $charges,
        private readonly TenantContext $tenantContext,
    ) {}

    public function capture(
        User $actor,
        Patient $patient,
        Branch $branch,
        DentalProcedure $procedure,
        ?string $tooth = null,
        ?string $surface = null,
        int $quantity = 1,
    ): Charge {
        $this->assertSameTenant($procedure);

        if ($tooth !== null && ! ToothNotation::isValid($tooth)) {
            throw new DentalException("Invalid FDI tooth id [{$tooth}].");
        }

        // REUSE the existing engine: resolve by the procedure's tariff code, snapshot the
        // fee, create the charge. No money math lives here — the engine owns all of it.
        $charge = $this->charges->captureManual($patient, $branch, Carbon::now(), $procedure->tariffItem->code, $quantity, $actor);

        // Light tooth link for a tooth-scoped procedure (e.g. a filling on tooth 16).
        if ($procedure->tooth_scoped && $tooth !== null) {
            DentalProcedureCharge::query()->create([
                'charge_id' => $charge->id,
                'dental_procedure_id' => $procedure->id,
                'tooth' => $tooth,
                'surface' => $surface,
            ]);
        }

        return $charge;
    }

    private function assertSameTenant(DentalProcedure $procedure): void
    {
        if ($procedure->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('dental_procedure_id', (string) $procedure->id);
        }
    }
}
