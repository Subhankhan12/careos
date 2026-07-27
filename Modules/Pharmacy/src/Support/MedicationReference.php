<?php

namespace Modules\Pharmacy\Support;

/**
 * A lightweight reference to one medication in a {@see SafetyContext} — the identity (+ optional
 * dose/route) a partner drug-safety engine would need to evaluate. Decoupled from any DB model so the
 * seam is stable across later gates (orders in PHARMACY.G2, administration in PHARMACY.G3).
 *
 * The `code`/`name` are the TENANT'S OWN formulary identity — CareOS bundles no licensed drug identifiers
 * (RxNorm / ATC / NDC). A certified partner maps them to its knowledge base on its side of the seam.
 */
final class MedicationReference
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $dose = null,
        public readonly ?string $route = null,
    ) {}
}
