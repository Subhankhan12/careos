<?php

namespace Modules\Radiology\Exceptions;

use RuntimeException;

/**
 * Domain errors for the radiology report (RAD.G4) — the report itself is a REUSED sign-and-lock `ClinicalNote`;
 * this covers only the radiology-side composition guards (no draft to sign, no signed report to amend). The
 * report is the radiologist's AUTHORED prose — the system computes no image finding/CAD (a hard non-goal).
 */
class RadiologyReportException extends RuntimeException
{
    public static function noDraftToSign(): self
    {
        return new self('There is no draft radiology report to sign — author the report first.');
    }

    public static function alreadySigned(): self
    {
        return new self('The radiology report is already signed — amend it (a new version), do not edit in place.');
    }

    public static function noSignedReportToAmend(): self
    {
        return new self('There is no signed radiology report to amend.');
    }
}
