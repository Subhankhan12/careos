<?php

namespace Modules\Clinical\Services;

use Modules\Clinical\Contracts\LabConnectivity;
use Modules\Clinical\Models\Order;
use RuntimeException;

/**
 * The ONLY {@see LabConnectivity} implementation CareOS ships — the MANUAL no-op behind the seam
 * (LAB.G1-formalized, docs/HOSPITAL-PHASE3-LAB-MAP.md §3): transmission is a no-op (the order is worked
 * MANUALLY by the lab), and there is no live result ingestion (results are entered manually via
 * `OrderService::recordResult` → an `OrderResult` `source=manual`). No HL7/FHIR client, no network call — the
 * DEFINING LIS value (the analyzer/HL7 feed) is PARTNER-GATED (LAB.G7, SEAM-STUBBED, NOT built).
 *
 * It is the exact analogue of Pharmacy's `NullMedicationSafetyProvider` / ED's `NullTriageAcuityProvider`.
 * When a certified HL7/analyzer partner is signed, its implementation is bound in place of this class WITHOUT
 * touching any consumer; its `ingestResult` appends an `OrderResult` `source=imported`, recording the value
 * and NEVER interpreting it (the P0P.G11 discipline). The electric fence holds on both paths: no abnormal/
 * critical flag is ever computed.
 */
class ManualLabConnectivity implements LabConnectivity
{
    public function transmit(Order $order): void
    {
        // No-op: a manual order is fulfilled by the lab; nothing is sent electronically. Real transmission is
        // a certified-partner integration (LAB.G7, partner-gated) bound in place of this class later.
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingestResult(array $payload): void
    {
        // Manual today: there is no automated feed. A certified partner's implementation appends an
        // OrderResult source=imported (never interpreted) when the seam is filled (LAB.G7).
        throw new RuntimeException('Automated result ingestion is not available; results are entered manually.');
    }
}
