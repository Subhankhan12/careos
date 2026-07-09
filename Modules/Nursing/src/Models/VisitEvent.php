<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Nursing\Exceptions\VisitEventImmutableException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Privacy posture: visit_events store exactly two point-in-time proof-of-visit
 * fixes per visit (check-in and check-out). There is no continuous location
 * tracking, no background location, and no route capture in this model.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $visit_id
 * @property string $type
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property string|null $location_source
 * @property string|null $manual_reason
 * @property string|null $distance_meters
 * @property int $recorded_by
 */
class VisitEvent extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_CHECK_IN = 'check_in';

    public const TYPE_CHECK_OUT = 'check_out';

    public const SOURCE_GPS = 'gps';

    public const SOURCE_MANUAL = 'manual';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (VisitEvent $event) => $event->assertTenantReferences());

        static::updating(function (): void {
            throw VisitEventImmutableException::make();
        });

        static::deleting(function (): void {
            throw VisitEventImmutableException::make();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'accuracy_meters' => 'decimal:2',
            'distance_meters' => 'decimal:2',
            'recorded_by' => 'integer',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    private function assertTenantReferences(): void
    {
        if (! Visit::query()->whereKey($this->visit_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
        }

        if (! User::query()
            ->whereKey($this->recorded_by)
            ->where('tenant_id', app(TenantContext::class)->id())
            ->exists()) {
            throw CrossTenantReferenceException::forAttribute('recorded_by', (string) $this->recorded_by);
        }
    }
}
