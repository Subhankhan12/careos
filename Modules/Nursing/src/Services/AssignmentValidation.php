<?php

namespace Modules\Nursing\Services;

/**
 * The result of an assignment validation. Cleanly separates BLOCKING violations
 * (which refuse the assignment, like a qualification miss) from NON-BLOCKING
 * warnings (advisories the dispatcher may proceed past, like a soft competency miss).
 */
class AssignmentValidation
{
    /**
     * @param  list<string>  $blocking  reason codes that REFUSE the assignment
     * @param  list<string>  $warnings  advisory codes that DO NOT block
     */
    public function __construct(
        public readonly array $blocking = [],
        public readonly array $warnings = [],
    ) {}

    public function passes(): bool
    {
        return $this->blocking === [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
