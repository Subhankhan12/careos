<?php

namespace Modules\Clinical\Services;

use Modules\Clinical\Contracts\LabConnectivity;
use Modules\Clinical\Models\Order;
use RuntimeException;

/**
 * The ONLY LabConnectivity implementation: transmission is a no-op (the order is
 * worked MANUALLY), and there is no live result ingestion. No HL7/FHIR client,
 * no network call — real connectivity is deferred partner work.
 */
class ManualLabConnectivity implements LabConnectivity
{
    public function transmit(Order $order): void
    {
        // No-op: a manual order is fulfilled by the practice; nothing is sent
        // electronically. Real transmission is a deferred partner integration.
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingestResult(array $payload): void
    {
        throw new RuntimeException('Automated result ingestion is not available; results are entered manually.');
    }
}
