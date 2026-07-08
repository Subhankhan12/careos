<?php

namespace Modules\Audit\Concerns;

use Modules\Audit\Facades\Audit;
use Modules\Audit\Models\AuditEvent;

/**
 * Marks a model's reads as auditable. Call {@see auditRead()} at the point a
 * sensitive record is disclosed to produce an audit_events row of action
 * 'read'. Patient models (Phase B) override auditPatientId() so the "who
 * accessed my record" report can filter by patient.
 */
trait LogsReads
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function auditRead(array $context = []): AuditEvent
    {
        return Audit::recordRead(
            $this->auditResourceType(),
            (string) $this->getKey(),
            $this->auditPatientId(),
            $context,
        );
    }

    protected function auditResourceType(): string
    {
        return $this->getTable();
    }

    protected function auditPatientId(): ?string
    {
        return null;
    }
}
