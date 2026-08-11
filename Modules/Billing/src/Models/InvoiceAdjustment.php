<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Billing\Services\AdjustmentService;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * BILLAR.P1 — a tenant-owned, APPEND-ONLY ledger movement that reduces an invoice's open balance:
 * a WRITE-OFF (bad debt) or a CONTRACTUAL adjustment (insurer-agreed rate reconciliation).
 *
 * Signed minor units, like {@see PaymentAllocation}: a movement is POSITIVE (reduces the open
 * balance); a correction is a REVERSAL row — the exact NEGATIVE of its target — never a mutation.
 * Never edited or deleted (ORM guard + DB triggers). Written only through the operator-gated
 * {@see AdjustmentService}; the billing agent never creates one.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $type
 * @property int $amount_minor
 * @property string|null $reverses_adjustment_id
 * @property string $reason
 * @property string|null $reference
 * @property int $created_by
 * @property Carbon $adjusted_at
 */
class InvoiceAdjustment extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_WRITE_OFF = 'write_off';

    public const TYPE_CONTRACTUAL = 'contractual';

    public const TYPES = [self::TYPE_WRITE_OFF, self::TYPE_CONTRACTUAL];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'invoice_id',
        'type',
        'amount_minor',
        'reverses_adjustment_id',
        'reason',
        'reference',
        'created_by',
        'adjusted_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (InvoiceAdjustment $adjustment) => $adjustment->assertTenantReferences());
        static::updating(function (): void {
            throw new LogicException('invoice_adjustments are append-only: they cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('invoice_adjustments are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'adjusted_at' => 'datetime',
        ];
    }

    /** A reversal row (the exact negative of the adjustment it reverses). */
    public function isReversal(): bool
    {
        return $this->reverses_adjustment_id !== null;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    private function assertTenantReferences(): void
    {
        if (! Invoice::query()->whereKey($this->invoice_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('invoice_id', (string) $this->invoice_id);
        }
    }
}
