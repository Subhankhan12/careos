<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Scheduling\Models\Resource;

/**
 * A competency held by a nurse (practitioner resource). A competency is only
 * "held" if the grant is active AND not expired (mirrors credential-vault expiry).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $resource_id
 * @property string $competency_id
 * @property Carbon $granted_at
 * @property Carbon|null $expires_at
 * @property bool $active
 */
class NurseCompetency extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resource_id',
        'competency_id',
        'granted_at',
        'expires_at',
        'active',
    ];

    protected $attributes = [
        'active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(fn (NurseCompetency $grant) => $grant->assertValidReferences());
        static::updating(function (NurseCompetency $grant): void {
            if ($grant->isDirty(['resource_id', 'competency_id'])) {
                $grant->assertValidReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'date',
            'expires_at' => 'date',
            'active' => 'boolean',
        ];
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * A competency is only HELD if the grant is active and not past its expiry.
     * Expiry uses whole days: a grant is not-held from the day AFTER it expires.
     */
    public function scopeHeld(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where(function (Builder $inner): void {
                $inner
                    ->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', Carbon::today()->toDateString());
            });
    }

    private function assertValidReferences(): void
    {
        $resource = Resource::query()->whereKey($this->resource_id)->first();

        if ($resource === null) {
            throw CrossTenantReferenceException::forAttribute('resource_id', (string) $this->resource_id);
        }

        if ($resource->type !== Resource::TYPE_PRACTITIONER) {
            throw new InvalidArgumentException('Competencies may only be granted to practitioner resources.');
        }

        if (! Competency::query()->whereKey($this->competency_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('competency_id', (string) $this->competency_id);
        }
    }
}
