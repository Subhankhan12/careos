<?php

namespace Modules\Pharmacy\Support;

use Modules\Pharmacy\Contracts\MedicationSafetyProvider;

/**
 * The result of a medication-safety check through the {@see MedicationSafetyProvider}
 * seam — a (possibly empty) list of advisory {@see SafetyAlert}s a CERTIFIED PARTNER engine returned.
 *
 * `none()` is the fence-clean default: CareOS asserts NOTHING about medication safety. A homemade
 * interaction / dose / contraindication / duplicate-therapy checker is medical-device territory and a
 * permanent non-goal (see the null-object). In PHARMACY.G1 every check returns `none()`.
 */
final class SafetyResult
{
    /**
     * @param  list<SafetyAlert>  $alerts
     */
    public function __construct(public readonly array $alerts = []) {}

    /**
     * No findings — CareOS makes no safety assertion (the human / a certified partner owns that judgment).
     */
    public static function none(): self
    {
        return new self([]);
    }

    public function hasAlerts(): bool
    {
        return $this->alerts !== [];
    }
}
