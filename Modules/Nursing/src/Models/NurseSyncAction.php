<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Scheduling\Models\Resource;

/**
 * Server idempotency ledger for offline nurse outbox actions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $client_action_uuid
 * @property string|null $visit_id
 * @property string $nurse_resource_id
 * @property string $action_type
 * @property int $device_sequence
 * @property Carbon $device_timestamp
 * @property string $status
 * @property string $result_code
 * @property array<string, mixed> $client_payload
 * @property array<string, mixed>|null $result_payload
 */
class NurseSyncAction extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONFLICT = 'conflict';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'client_action_uuid',
        'visit_id',
        'nurse_resource_id',
        'action_type',
        'device_sequence',
        'device_timestamp',
        'status',
        'result_code',
        'client_payload',
        'result_payload',
    ];

    protected static function booted(): void
    {
        static::creating(fn (NurseSyncAction $action) => $action->assertTenantReferences());
        static::updating(function (NurseSyncAction $action): void {
            if ($action->isDirty(['visit_id', 'nurse_resource_id'])) {
                $action->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_sequence' => 'integer',
            'device_timestamp' => 'datetime',
            'client_payload' => 'array',
            'result_payload' => 'array',
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

    private function assertTenantReferences(): void
    {
        if ($this->visit_id !== null && ! Visit::query()->whereKey($this->visit_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
        }

        if (! Resource::query()->whereKey($this->nurse_resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('nurse_resource_id', (string) $this->nurse_resource_id);
        }
    }
}
