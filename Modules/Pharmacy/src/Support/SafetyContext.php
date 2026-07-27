<?php

namespace Modules\Pharmacy\Support;

use Modules\Pharmacy\Contracts\MedicationSafetyProvider;

/**
 * The input to a medication-safety check through the {@see MedicationSafetyProvider}
 * seam: the patient plus the set of medications a partner engine would evaluate against each other and the
 * patient's record (allergies, conditions). CareOS assembles the RECORD; the partner draws the judgment.
 *
 * A plain immutable value object (not a model) so the seam is stable for the future order (G2) and eMAR
 * (G3) call sites without coupling to any table.
 */
final class SafetyContext
{
    /**
     * @param  list<MedicationReference>  $medications
     */
    public function __construct(
        public readonly string $patientId,
        public readonly array $medications = [],
    ) {}
}
