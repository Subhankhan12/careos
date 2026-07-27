<?php

namespace Modules\Pharmacy\Support;

use Modules\Pharmacy\Contracts\MedicationSafetyProvider;

/**
 * A single medication-safety finding RETURNED BY A CERTIFIED PARTNER engine through the
 * {@see MedicationSafetyProvider} seam. It is the shape a real
 * drug-interaction / dose / contraindication engine would populate — CareOS NEVER constructs one itself.
 *
 * ADVISORY + human-owned by design: when a partner is wired, an alert is SURFACED to the pharmacist /
 * prescriber, never auto-blocking or auto-acting. There is deliberately no computed severity/score/risk
 * field — any partner priority is the PARTNER'S own value, not a CareOS judgment. In PHARMACY.G1 the only
 * provider is the null-object, which returns zero alerts, so no SafetyAlert is ever produced by CareOS.
 */
final class SafetyAlert
{
    public function __construct(
        public readonly string $code,    // the partner engine's finding code (e.g. an interaction id)
        public readonly string $message, // the partner's human-readable advisory text
        public readonly string $source,  // which certified partner produced it (provenance)
    ) {}
}
