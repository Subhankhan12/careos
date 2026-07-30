<?php

namespace Modules\Clinical\Contracts;

use Modules\Clinical\Models\Order;
use Modules\Clinical\Services\ManualLabConnectivity;

/**
 * THE LAB-CONNECTIVITY SEAM — the clean integration point for electronic lab transmission + automated result
 * ingestion (HL7/FHIR/analyzer). This is DELIBERATELY an interface with only a Manual (no-op) implementation
 * ({@see ManualLabConnectivity}) — EXACTLY like Pharmacy's `MedicationSafetyProvider`
 * → `Null*` and ED's `TriageAcuityProvider` → `Null*`. It is FORMALIZED here (LAB.G1,
 * docs/HOSPITAL-PHASE3-LAB-MAP.md §2.1/§3); the lab vertical CONSUMES it (it is already bound in
 * `ClinicalServiceProvider` and already wired into `OrderService::place()`, where `transmit` is called).
 *
 * THE TWO RESULT PATHS:
 *  - **MANUAL (built today):** a human enters the result via `OrderService::recordResult` → an append-only
 *    `OrderResult` with `source = OrderResult::SOURCE_MANUAL`. Fully built; the fence holds (raw value only).
 *  - **IMPORTED (partner-gated, LAB.G7 — SEAM-STUBBED, NOT built):** a CERTIFIED HL7/analyzer partner
 *    implements `ingestResult()` → resolves the target `Order` and appends an `OrderResult` with
 *    `source = OrderResult::SOURCE_IMPORTED`, through the SAME append-only path — recording the value, NEVER
 *    interpreting it (the P0P.G11 discipline: "never interpret a result even when auto-ingested"). The system
 *    computes NO abnormal/high/low/critical flag on manual OR imported results (the electric fence).
 *
 * A homemade HL7/FHIR/analyzer client is partner-and-market work and is NOT built here (DEFERRED.md). Real
 * connectivity is bound in place of the manual no-op when a certified partner is signed — WITHOUT touching any
 * consumer. No live client exists in the codebase.
 */
interface LabConnectivity
{
    /**
     * Send a placed order to a lab electronically (the OUTBOUND path). No-op in the manual implementation;
     * a certified partner sends the order. Already called by `OrderService::place()`.
     */
    public function transmit(Order $order): void;

    /**
     * Ingest an incoming electronic result payload (the INBOUND/IMPORTED path). Throws in the manual
     * implementation (results are entered manually today). A certified partner appends an `OrderResult`
     * `source=imported` — recording the value, NEVER interpreting it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingestResult(array $payload): void;
}
