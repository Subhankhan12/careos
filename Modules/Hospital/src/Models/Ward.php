<?php

namespace Modules\Hospital\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;

/**
 * An inpatient ward / nursing unit (HOSPITAL.G1). A ward groups beds under a
 * branch, belonging to a tenant.
 *
 * Tenant-owned via {@see BelongsToTenant}; the referenced branch is guarded to the
 * SAME tenant (a ward may never point at another tenant's branch). This is a
 * Hospital-vertical domain model that references only the Platform foundation
 * (Branch) — the proven Nursing\Visit → Branch pattern — rather than repurposing
 * Platform's generic Department stub (see docs/HOSPITAL-PHASE1-ADT-MAP.md §2.1).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $branch_id
 * @property string $name
 * @property string $code
 * @property bool $active
 */
class Ward extends Model
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
        static::creating(function (Ward $ward): void {
            $ward->assertBranchWithinTenant();
        });

        static::updating(function (Ward $ward): void {
            if ($ward->isDirty('branch_id')) {
                $ward->assertBranchWithinTenant();
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
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
