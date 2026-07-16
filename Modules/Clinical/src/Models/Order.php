<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A structured clinical order tracked through a status lifecycle. The system
 * records what is ordered and resulted; it NEVER interprets a result.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string|null $encounter_id
 * @property string $orderable_item_id
 * @property int $ordered_by
 * @property Carbon $ordered_at
 * @property string $priority
 * @property string|null $clinical_note
 * @property string $status
 * @property string|null $cancelled_reason
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
class Order extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESULTED = 'resulted';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_ROUTINE = 'routine';

    public const PRIORITY_URGENT = 'urgent';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'encounter_id',
        'orderable_item_id',
        'ordered_by',
        'ordered_at',
        'priority',
        'clinical_note',
        'status',
        'cancelled_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_ORDERED,
        'priority' => self::PRIORITY_ROUTINE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<OrderableItem, $this>
     */
    public function orderableItem(): BelongsTo
    {
        return $this->belongsTo(OrderableItem::class);
    }

    /**
     * @return HasMany<OrderResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(OrderResult::class);
    }

    protected function auditResourceType(): string
    {
        return 'order';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
