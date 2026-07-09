<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Resource;

/**
 * Human-review queue for ambiguous offline sync conflicts.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $visit_id
 * @property string $nurse_resource_id
 * @property string $action_type
 * @property array<string, mixed> $client_payload
 * @property array<string, mixed> $server_state
 * @property string $reason
 * @property string $status
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 */
class SyncConflict extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'nurse_resource_id',
        'action_type',
        'client_payload',
        'server_state',
        'reason',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected static function booted(): void
    {
        static::creating(fn (SyncConflict $conflict) => $conflict->assertTenantReferences());
        static::updating(function (SyncConflict $conflict): void {
            if ($conflict->isDirty(['visit_id', 'nurse_resource_id', 'resolved_by'])) {
                $conflict->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_payload' => 'array',
            'server_state' => 'array',
            'resolved_at' => 'datetime',
            'resolved_by' => 'integer',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function nurseResource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'nurse_resource_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    private function assertTenantReferences(): void
    {
        if ($this->visit_id !== null && ! Visit::query()->whereKey($this->visit_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
        }

        if (! Resource::query()->whereKey($this->nurse_resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('nurse_resource_id', (string) $this->nurse_resource_id);
        }

        if ($this->resolved_by !== null && ! User::query()
            ->whereKey($this->resolved_by)
            ->where('tenant_id', $this->tenant_id)
            ->exists()) {
            throw CrossTenantReferenceException::forAttribute('resolved_by', (string) $this->resolved_by);
        }
    }
}
