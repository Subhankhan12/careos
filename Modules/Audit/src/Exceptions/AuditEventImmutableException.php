<?php

namespace Modules\Audit\Exceptions;

use RuntimeException;

/**
 * Thrown by the AuditEvent model when code attempts to update or delete an
 * audit record. The DB triggers are the authoritative guard; this is the
 * Eloquent-layer belt to fail fast in application code.
 */
class AuditEventImmutableException extends RuntimeException
{
    public static function make(): self
    {
        return new self('audit_events is append-only: records cannot be updated or deleted.');
    }
}
