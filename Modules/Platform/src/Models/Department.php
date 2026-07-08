<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * A department within a branch, belonging to a tenant.
 *
 * Tenant-owned: uses {@see BelongsToTenant}. In addition, the referenced branch
 * is guarded to the SAME tenant — a department may never point at a branch from
 * another tenant (enforced on create/update below and covered by tests).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $branch_id
 * @property string $name
 * @property string $code
 * @property bool $active
 */
class Department extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'active',
    ];

    protected $attributes = [
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Department $department): void {
            $department->assertBranchWithinTenant();
        });

        static::updating(function (Department $department): void {
            if ($department->isDirty('branch_id')) {
                $department->assertBranchWithinTenant();
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The branch lookup is tenant-scoped by BelongsToTenant, so a branch owned by
     * another tenant is invisible here and is rejected as a cross-tenant link.
     */
    private function assertBranchWithinTenant(): void
    {
        if (empty($this->branch_id)) {
            return;
        }

        if (! Branch::whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }
    }
}
