<?php

namespace Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Pharmacy\Exceptions\DispensingException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * An APPEND-ONLY stock ledger entry (PHARMACY.G4) — one immutable row per stock change (received /
 * dispensed / adjusted) with the signed `quantity_change` + `resulting_on_hand`. Model guards + DB triggers
 * (the `medication_order_events` recipe). A correction is a NEW movement, never an edit.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $medication_stock_id
 * @property string $type
 * @property int $quantity_change
 * @property int $resulting_on_hand
 * @property string|null $reason
 * @property string|null $dispense_id
 * @property int $performed_by
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MedicationStock|null $stock
 */
class StockMovement extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_RECEIVED = 'received';

    public const TYPE_DISPENSED = 'dispensed';

    public const TYPE_ADJUSTED = 'adjusted';

    public const TYPES = [self::TYPE_RECEIVED, self::TYPE_DISPENSED, self::TYPE_ADJUSTED];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'medication_stock_id',
        'type',
        'quantity_change',
        'resulting_on_hand',
        'reason',
        'dispense_id',
        'performed_by',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement): void {
            if (! in_array($movement->type, self::TYPES, true)) {
                throw DispensingException::invalidMovementType((string) $movement->type);
            }
        });

        // Append-only: a stock movement is an immutable ledger entry (belt for the DB triggers).
        static::updating(fn () => throw DispensingException::appendOnly());
        static::deleting(fn () => throw DispensingException::appendOnly());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'resulting_on_hand' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(MedicationStock::class, 'medication_stock_id');
    }
}
