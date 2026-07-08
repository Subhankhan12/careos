<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Throwaway tenant-owned model used only by the isolation suite to exercise
 * {@see BelongsToTenant} against a real table (tenant_probes) created in the
 * test setup. Not part of the application.
 */
class TenantProbe extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_probes';

    public $timestamps = false;

    protected $guarded = [];
}
