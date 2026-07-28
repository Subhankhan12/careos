<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Surgery\Exceptions\SurgicalInventoryException;

/**
 * An item USED/placed in a surgical case (SURGERY.G4) — one immutable row per usage (MIRRORS pharmacy
 * `Dispense`). Recording a usage DECREMENTS stock (a 'used' {@see SurgicalStockMovement}) ATOMICALLY.
 * APPEND-ONLY (model guards + DB triggers); patient-scoped read-logged ({@see LogsReads}).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $surgical_case_id
 * @property string $patient_id
 * @property string $surgical_item_id
 * @property int $quantity
 * @property int $used_by
 * @property Carbon $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SurgicalItem|null $surgicalItem
 */
class CaseItemUsage extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'surgical_case_id',
        'patient_id',
        'surgical_item_id',
        'quantity',
        'used_by',
        'used_at',
    ];

    protected static function booted(): void
    {
        // Append-only: an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw SurgicalInventoryException::appendOnly());
        static::deleting(fn () => throw SurgicalInventoryException::appendOnly());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'used_at' => 'datetime',
        ];
    }

    public function surgicalItem(): BelongsTo
    {
        return $this->belongsTo(SurgicalItem::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
