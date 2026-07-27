<?php

namespace Modules\Hospital\Exceptions;

use RuntimeException;

/**
 * Domain errors for the ADT workflow (HOSPITAL.G2): illegal state-machine transitions,
 * the one-active-stay guard, append-only violations, and invalid operational values.
 */
class AdmissionException extends RuntimeException
{
    public static function alreadyAdmitted(string $patientId): self
    {
        return new self("Patient {$patientId} already has an active admission.");
    }

    public static function notActive(string $stayId, string $status): self
    {
        return new self("Stay {$stayId} is not an active admission (status: {$status}).");
    }

    public static function sameBed(string $bedId): self
    {
        return new self("The patient is already in bed {$bedId}.");
    }

    public static function invalidAdmissionType(string $type): self
    {
        return new self("Unknown admission type: {$type}.");
    }

    public static function invalidDisposition(string $disposition): self
    {
        return new self("Unknown discharge disposition: {$disposition}.");
    }

    public static function invalidEventType(string $type): self
    {
        return new self("Unknown stay event type: {$type}.");
    }

    public static function noWardRound(string $stayId): self
    {
        return new self("Stay {$stayId} has no ward round yet — start a ward round before charting.");
    }

    public static function nothingToInvoice(string $stayId): self
    {
        return new self("Stay {$stayId} has no accrued charges to invoice.");
    }

    public static function dischargeSummaryFinalized(): self
    {
        return new self('A finalized discharge summary is immutable: it cannot be edited or deleted.');
    }

    public static function dischargeSummaryRequiresNarrative(): self
    {
        return new self('A discharge summary requires a narrative before it can be finalized.');
    }

    public static function dischargeSummaryMissing(string $stayId): self
    {
        return new self("Stay {$stayId} has no discharge summary to finalize — save a draft first.");
    }

    public static function appendOnly(): self
    {
        return new self('stay_events are append-only: a correction is a new event, never an edit.');
    }
}
