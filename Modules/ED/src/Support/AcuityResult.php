<?php

namespace Modules\ED\Support;

use Modules\ED\Contracts\TriageAcuityProvider;

/**
 * The result of a triage-acuity check through the {@see TriageAcuityProvider} seam — an OPTIONAL advisory
 * acuity a CERTIFIED PARTNER engine returned, for a human to consider. It is NEVER auto-assigned and NEVER
 * auto-prioritises.
 *
 * `none()` is the fence-clean default: CareOS asserts NOTHING about acuity. Computing a triage acuity from
 * vitals/complaint is medical-device territory and a PERMANENT non-goal (see the null-object). In ED.G1 every
 * call returns `none()`; the triage nurse ASSIGNS the acuity (a recorded fact, ED.G2), the system computes it
 * never.
 */
final class AcuityResult
{
    /**
     * @param  string|null  $suggestedLevel  a partner's ADVISORY suggested level (null = no suggestion); human-owned
     * @param  string|null  $scale  the acuity scale the suggestion belongs to (ESI / Manchester / CTAS), if any
     */
    public function __construct(
        public readonly ?string $suggestedLevel = null,
        public readonly ?string $scale = null,
    ) {}

    /**
     * No suggestion — CareOS makes no acuity assertion (the triage nurse / a certified partner owns that
     * judgment). The ONLY result the shipped null-object ever returns.
     */
    public static function none(): self
    {
        return new self(null, null);
    }

    public function hasSuggestion(): bool
    {
        return $this->suggestedLevel !== null;
    }
}
