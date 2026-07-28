<?php

namespace Modules\ED\Exceptions;

use RuntimeException;

/**
 * Domain errors for the ED patient-flow entity (ED.G1). Illegal flow transitions, invalid enum values, the
 * append-only guard on the flow-event history, and the required-field guards.
 */
class EdVisitException extends RuntimeException
{
    public static function invalidArrivalMode(string $mode): self
    {
        return new self("Invalid ED arrival mode: {$mode}");
    }

    public static function chiefComplaintRequired(): self
    {
        return new self('An ED visit requires a presenting complaint (recorded at arrival).');
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Illegal ED-visit flow transition: {$from} -> {$to}");
    }

    public static function invalidDisposition(string $disposition): self
    {
        return new self("Invalid ED disposition: {$disposition}");
    }

    public static function dispositionRequired(): self
    {
        return new self('Moving an ED visit to dispositioned requires a disposition (admit / discharge / transfer).');
    }

    public static function dispositionNotAllowed(): self
    {
        return new self('A disposition may only be recorded on the transition to dispositioned.');
    }

    public static function invalidEventType(string $type): self
    {
        return new self("Invalid ED-visit flow-event type: {$type}");
    }

    public static function appendOnly(): self
    {
        return new self('ed_visit_events are append-only: UPDATE and DELETE are forbidden.');
    }

    public static function presentingComplaintRequired(): self
    {
        return new self('A triage record requires a presenting complaint.');
    }

    public static function invalidAcuityScale(string $scale): self
    {
        return new self("Invalid triage acuity scale: {$scale}");
    }

    public static function invalidAcuityLevel(string $level, string $scale): self
    {
        return new self("Invalid triage acuity level '{$level}' for scale {$scale}.");
    }

    public static function triageAppendOnly(): self
    {
        return new self('ed_triages are append-only: UPDATE and DELETE are forbidden.');
    }

    public static function noEncounter(string $visitId): self
    {
        return new self("ED visit {$visitId} has no treatment encounter yet — start one before charting vitals/orders.");
    }
}
