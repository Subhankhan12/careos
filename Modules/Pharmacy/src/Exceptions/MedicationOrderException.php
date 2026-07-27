<?php

namespace Modules\Pharmacy\Exceptions;

use RuntimeException;

/**
 * Domain errors for medication orders (PHARMACY.G2): an invalid route/status/event, an illegal lifecycle
 * transition, or an append-only violation on the event log. Operational validation only — nothing clinical
 * is judged, no dose is computed.
 */
class MedicationOrderException extends RuntimeException
{
    public static function invalidRoute(string $route): self
    {
        return new self("Unknown medication route: {$route}.");
    }

    public static function invalidStatus(string $status): self
    {
        return new self("Unknown medication-order status: {$status}.");
    }

    public static function invalidEventType(string $type): self
    {
        return new self("Unknown medication-order event type: {$type}.");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("A medication order cannot move from {$from} to {$to}.");
    }

    public static function appendOnly(): self
    {
        return new self('medication_order_events are append-only: a correction is a new event, never an edit.');
    }
}
