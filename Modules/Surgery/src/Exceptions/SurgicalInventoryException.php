<?php

namespace Modules\Surgery\Exceptions;

use RuntimeException;

/**
 * Domain errors for surgical consumables + implant tracking (SURGERY.G4). Stock is concurrency-safe and never
 * negative (`insufficientStock` / `negativeOnHand`); the ledgers are append-only (`appendOnly`); an implant
 * placement needs a lot and an implant-flagged item (`notAnImplant` / `lotRequired`). All operational — no
 * device-safety judgment is ever raised.
 */
class SurgicalInventoryException extends RuntimeException
{
    public static function itemCodeRequired(): self
    {
        return new self('A surgical item must have a code and a name.');
    }

    public static function nonPositiveQuantity(): self
    {
        return new self('A stock quantity must be a positive number.');
    }

    public static function insufficientStock(string $itemId, int $requested, int $onHand): self
    {
        return new self("Insufficient stock for surgical item {$itemId}: requested {$requested}, on hand {$onHand}.");
    }

    public static function negativeOnHand(): self
    {
        return new self('On-hand stock cannot be negative.');
    }

    public static function noStock(string $itemId): self
    {
        return new self("No stock record exists for surgical item {$itemId}.");
    }

    public static function invalidMovementType(string $type): self
    {
        return new self("Unknown surgical stock-movement type: {$type}.");
    }

    public static function appendOnly(): self
    {
        return new self('surgical inventory ledgers are append-only: a correction is a new row, never an edit.');
    }

    public static function notAnImplant(string $itemId): self
    {
        return new self("Surgical item {$itemId} is not flagged as an implant; only implants carry lot/serial/UDI.");
    }

    public static function lotRequired(): self
    {
        return new self('An implant placement must record a lot number for traceability.');
    }
}
