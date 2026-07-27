<?php

namespace Modules\Pharmacy\Services;

use Modules\Pharmacy\Contracts\MedicationSafetyProvider;
use Modules\Pharmacy\Support\SafetyContext;
use Modules\Pharmacy\Support\SafetyResult;

/**
 * The ONLY {@see MedicationSafetyProvider} implementation CareOS ships — a null-object that performs NO
 * medication-safety checking and returns {@see SafetyResult::none()} for every call. It is the exact
 * analogue of Clinical's `ManualLabConnectivity` (a no-op behind the `LabConnectivity` seam).
 *
 * WHY IT IS EMPTY (and stays empty): drug–drug interaction, allergy-class contraindication,
 * dose-range/max-dose, and duplicate-therapy checking each COMPUTE a clinical-safety JUDGMENT — the
 * electric fence refuses computed clinical judgments, and this is regulated MEDICAL-DEVICE territory that
 * requires a certified partner engine over a licensed drug database. A homemade checker is a PERMANENT
 * non-goal. This null-object therefore ASSERTS NOTHING about safety — returning `none()` means "CareOS
 * makes no safety claim", not "these medications are safe"; a human (and, when licensed, a certified
 * partner bound in place of this class) owns that judgment.
 */
class NullMedicationSafetyProvider implements MedicationSafetyProvider
{
    public function checkOrder(SafetyContext $context): SafetyResult
    {
        return SafetyResult::none();
    }

    public function checkAdministration(SafetyContext $context): SafetyResult
    {
        return SafetyResult::none();
    }
}
