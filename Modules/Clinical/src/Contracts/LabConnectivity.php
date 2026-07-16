<?php

namespace Modules\Clinical\Contracts;

use Modules\Clinical\Models\Order;

/**
 * The seam for electronic lab transmission + automated result ingestion
 * (HL7/FHIR). This is DELIBERATELY an interface with only a Manual (no-op)
 * implementation — real lab connectivity is partner-and-market work and is NOT
 * built here (see DEFERRED). No live client exists in the codebase.
 */
interface LabConnectivity
{
    /** Send a placed order to a lab electronically. */
    public function transmit(Order $order): void;

    /**
     * Ingest an incoming electronic result payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingestResult(array $payload): void;
}
