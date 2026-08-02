<?php

namespace Modules\Lab\Exceptions;

use RuntimeException;

/**
 * Domain errors for manual lab result entry (LAB.G4) — a specimen that can no longer be resulted (terminal) and
 * the append-only guard on the result-link overlay. Operational record-keeping — no computed judgment. The raw
 * value + the displayed reference range are FACTS; this module never computes an abnormal/critical verdict.
 */
class LabResultException extends RuntimeException
{
    public static function specimenNotResultable(string $status): self
    {
        return new self("A specimen in status {$status} cannot be resulted.");
    }

    public static function appendOnly(): self
    {
        return new self('lab_results are append-only: UPDATE and DELETE are forbidden (a correction is a new result).');
    }
}
