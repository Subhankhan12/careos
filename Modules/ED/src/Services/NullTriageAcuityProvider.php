<?php

namespace Modules\ED\Services;

use Modules\ED\Contracts\TriageAcuityProvider;
use Modules\ED\Support\AcuityContext;
use Modules\ED\Support\AcuityResult;

/**
 * The ONLY {@see TriageAcuityProvider} implementation CareOS ships — a null-object that performs NO
 * acuity computation and returns {@see AcuityResult::none()} for every call. It is the exact analogue of
 * Pharmacy's `NullMedicationSafetyProvider` (a no-op behind the `MedicationSafetyProvider` seam).
 *
 * WHY IT IS EMPTY (and stays empty): computing a triage acuity from vitals + complaint IS performing triage
 * — the electric fence refuses computed clinical judgments ("no triage … Ever"), and this is regulated
 * MEDICAL-DEVICE territory that requires a certified partner engine. A homemade acuity computer is a PERMANENT
 * non-goal. This null-object therefore ASSERTS NOTHING: returning `none()` means "CareOS makes no acuity
 * claim", not "this patient is low acuity". The triage nurse ASSIGNS the acuity (a recorded fact, ED.G2); a
 * certified partner, bound in place of this class, may later return an ADVISORY suggestion — human-owned,
 * never auto-assigning or auto-prioritising.
 */
class NullTriageAcuityProvider implements TriageAcuityProvider
{
    public function suggestAcuity(AcuityContext $context): AcuityResult
    {
        return AcuityResult::none();
    }
}
