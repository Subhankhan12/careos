<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Surgical STOCK (SURGERY.G4) — the current on-hand per surgical item (MIRRORS pharmacy `MedicationStock`;
 * the Bed-status analogue). `on_hand` is the MUTABLE current state, mutated ONLY under a FOR UPDATE row lock
 * ({@see lockOnHand}) so concurrent uses cannot oversell; the immutable ledger is {@see SurgicalStockMovement}.
 *
 * ELECTRIC FENCE: `reorder_threshold` is a plain configured NUMBER; {@see isBelowThreshold()} is a factual
 * `on_hand <= reorder_threshold` comparison, NEVER a graded/severity/alert judgment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $surgical_item_id
 * @property string|null $location
 * @property int $on_hand
 * @property string $unit
 * @property int|null $reorder_threshold
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SurgicalItem|null $surgicalItem
 */
class SurgicalItemStock extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'surgical_item_id',
        'location',
        'on_hand',
        'unit',
        'reorder_threshold',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reorder_threshold' => 'integer',
        ];
    }

    /**
     * Lock a single stock row FOR UPDATE and return its authoritative current on-hand — the `BedService` lock
     * idiom applied to stock, so N racing uses serialise and exactly one can consume the last unit. The
     * tenant-scoped WHERE means a cross-tenant id is invisible and rejected.
     */
    public static function lockOnHand(string $tenantId, string $stockId): int
    {
        $rows = DB::select('select on_hand from surgical_item_stocks where tenant_id = ? and id = ? for update', [$tenantId, $stockId]);

        if ($rows === []) {
            throw CrossTenantReferenceException::forAttribute('surgical_item_stock_id', $stockId);
        }

        return (int) $rows[0]->on_hand;
    }

    /** A factual below-threshold check — a plain quantity comparison, never a graded alert. */
    public function isBelowThreshold(): bool
    {
        return $this->reorder_threshold !== null && $this->on_hand <= $this->reorder_threshold;
    }

    public function surgicalItem(): BelongsTo
    {
        return $this->belongsTo(SurgicalItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SurgicalStockMovement::class);
    }
}
