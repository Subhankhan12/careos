<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Surgery\Exceptions\SurgicalInventoryException;

/**
 * An APPEND-ONLY surgical stock ledger entry (SURGERY.G4) — one immutable row per stock change (received /
 * used / adjusted) with the signed `quantity_change` + `resulting_on_hand` (MIRRORS pharmacy `StockMovement`).
 * Model guards + DB triggers; a correction is a NEW movement, never an edit.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $surgical_item_stock_id
 * @property string $type
 * @property int $quantity_change
 * @property int $resulting_on_hand
 * @property string|null $reason
 * @property string|null $case_item_usage_id
 * @property int $performed_by
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SurgicalItemStock|null $stock
 */
class SurgicalStockMovement extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_RECEIVED = 'received';

    public const TYPE_USED = 'used';

    public const TYPE_ADJUSTED = 'adjusted';

    public const TYPES = [self::TYPE_RECEIVED, self::TYPE_USED, self::TYPE_ADJUSTED];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'surgical_item_stock_id',
        'type',
        'quantity_change',
        'resulting_on_hand',
        'reason',
        'case_item_usage_id',
        'performed_by',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (SurgicalStockMovement $movement): void {
            if (! in_array($movement->type, self::TYPES, true)) {
                throw SurgicalInventoryException::invalidMovementType((string) $movement->type);
            }
        });

        // Append-only: a stock movement is an immutable ledger entry (belt for the DB triggers).
        static::updating(fn () => throw SurgicalInventoryException::appendOnly());
        static::deleting(fn () => throw SurgicalInventoryException::appendOnly());
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
        return $this->belongsTo(SurgicalItemStock::class, 'surgical_item_stock_id');
    }
}
