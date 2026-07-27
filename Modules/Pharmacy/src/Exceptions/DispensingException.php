<?php

namespace Modules\Pharmacy\Exceptions;

use RuntimeException;

/**
 * Domain errors for dispensing + inventory (PHARMACY.G4): the stock guard (no overselling / no negative
 * on-hand), the factual order-state check (can't dispense against a discontinued order), an invalid movement
 * type, and append-only violations. Operational only — nothing clinical is judged.
 */
class DispensingException extends RuntimeException
{
    public static function insufficientStock(string $formularyItemId, int $requested, int $onHand): self
    {
        return new self("Insufficient stock for {$formularyItemId}: requested {$requested}, on hand {$onHand}.");
    }

    public static function noStock(string $formularyItemId): self
    {
        return new self("No stock record exists for formulary item {$formularyItemId}.");
    }

    public static function negativeOnHand(): self
    {
        return new self('A stock movement cannot take on-hand below zero.');
    }

    public static function orderNotActive(string $orderId, string $status): self
    {
        return new self("Cannot dispense against medication order {$orderId} (status: {$status}).");
    }

    public static function nonPositiveQuantity(): self
    {
        return new self('A dispense/receive quantity must be a positive number.');
    }

    public static function invalidMovementType(string $type): self
    {
        return new self("Unknown stock movement type: {$type}.");
    }

    public static function appendOnly(): self
    {
        return new self('This record is append-only: a correction is a new movement/dispense, never an edit.');
    }
}
