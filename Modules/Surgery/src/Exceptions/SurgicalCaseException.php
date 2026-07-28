<?php

namespace Modules\Surgery\Exceptions;

use RuntimeException;

/**
 * Domain errors for the surgical case (SURGERY.G1 + the G2 lifecycle). The lifecycle is a legal-only state
 * machine (`invalidTransition`); the event history is append-only (`appendOnly`); the ASA class is an
 * anesthetist-ASSIGNED value from a closed set (`invalidAsaClass` / `invalidMallampati`), never computed.
 */
class SurgicalCaseException extends RuntimeException
{
    public static function procedureRequired(): self
    {
        return new self('A surgical case must record a procedure description.');
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("A surgical case cannot move from {$from} to {$to}.");
    }

    public static function invalidEventType(string $type): self
    {
        return new self("Unknown surgical-case event type: {$type}.");
    }

    public static function appendOnly(): self
    {
        return new self('surgical_case_events are append-only: a correction is a new event, never an edit.');
    }

    public static function invalidAsaClass(string $value): self
    {
        return new self("Invalid ASA physical-status class: {$value}. It is assigned by the anesthetist (I–VI).");
    }

    public static function invalidMallampati(string $value): self
    {
        return new self("Invalid Mallampati class: {$value}. It is assigned by the anesthetist (I–IV).");
    }

    public static function invalidTeamRole(string $value): self
    {
        return new self("Unknown surgical-team role: {$value}.");
    }

    public static function invalidPhase(string $value): self
    {
        return new self("Unknown op-documentation phase: {$value}.");
    }
}
