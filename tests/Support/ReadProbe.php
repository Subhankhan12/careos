<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Audit\Concerns\LogsReads;

/**
 * Throwaway model used to exercise the read-logging mechanism ({@see LogsReads})
 * without a real domain table. Phase B applies the same trait to patients.
 */
class ReadProbe extends Model
{
    use LogsReads;

    protected $keyType = 'string';

    public $incrementing = false;

    protected function auditResourceType(): string
    {
        return 'probe';
    }
}
