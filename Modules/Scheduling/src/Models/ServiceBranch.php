<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;

/**
 * Tenant-owned branch availability link for a bookable service.
 *
 * No link rows for a service means it is available at all tenant branches.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $service_id
 * @property string $branch_id
 */
class ServiceBranch extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'service_branch';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'service_id',
        'branch_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (ServiceBranch $serviceBranch): void {
            $serviceBranch->assertReferencesWithinTenant();
        });

        static::updating(function (ServiceBranch $serviceBranch): void {
            if ($serviceBranch->isDirty('service_id') || $serviceBranch->isDirty('branch_id')) {
                $serviceBranch->assertReferencesWithinTenant();
            }
        });
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    private function assertReferencesWithinTenant(): void
    {
        if (! Service::whereKey($this->service_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('service_id', (string) $this->service_id);
        }

        if (! Branch::whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }
    }
}
