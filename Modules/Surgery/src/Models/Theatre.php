<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Surgery\Exceptions\TheatreException;

/**
 * An operating THEATRE / OR room (SURGERY.G1) — a Surgery-OWNED entity (docs/HOSPITAL-PHASE5-SURGERY-MAP.md
 * §2.1: NOT forced into Scheduling's `Resource`), tenant + branch scoped. `type` is a plain, tenant-meaningful
 * label (general / cardiac / …).
 *
 * ELECTRIC FENCE: an operational record — no acuity/priority/risk/score/utilization-grade field; theatre
 * utilization is a FACT the UI derives, never a stored judgment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $branch_id
 * @property string $name
 * @property string|null $type
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch|null $branch
 */
class Theatre extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id',
        'name',
        'type',
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
        static::saving(function (Theatre $theatre): void {
            if (trim((string) $theatre->name) === '') {
                throw TheatreException::nameRequired();
            }
        });

        static::creating(function (Theatre $theatre): void {
            $theatre->assertBranchWithinTenant();
        });

        static::updating(function (Theatre $theatre): void {
            if ($theatre->isDirty('branch_id')) {
                $theatre->assertBranchWithinTenant();
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    private function assertBranchWithinTenant(): void
    {
        if (! empty($this->branch_id) && ! Branch::whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }
    }
}
