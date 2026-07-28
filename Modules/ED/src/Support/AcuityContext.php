<?php

namespace Modules\ED\Support;

use Modules\ED\Contracts\TriageAcuityProvider;
use Modules\ED\Services\NullTriageAcuityProvider;

/**
 * The input a CERTIFIED, LICENSED triage-acuity engine would read through the {@see TriageAcuityProvider}
 * seam — the presenting complaint + whatever raw observations a partner would consume. It carries only
 * facts a human already recorded; CareOS itself derives NOTHING from it.
 *
 * This mirrors Pharmacy's `SafetyContext` (the input to `MedicationSafetyProvider`). It exists so the seam
 * has a real, typed surface for a future partner — NOT so CareOS can compute an acuity. In ED.G1 the only
 * bound implementation ({@see NullTriageAcuityProvider}) ignores it and returns
 * {@see AcuityResult::none()}.
 */
final class AcuityContext
{
    /**
     * @param  string  $chiefComplaint  the presenting complaint the arrival nurse recorded (free text)
     * @param  array<string, mixed>  $observations  raw observations a partner would read (empty in G1 — no consumer)
     */
    public function __construct(
        public readonly string $chiefComplaint = '',
        public readonly array $observations = [],
    ) {}
}
