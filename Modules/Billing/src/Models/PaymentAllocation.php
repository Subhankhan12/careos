<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Tenant-owned append-only allocation of a payment against an invoice.
 *
 * A normal allocation carries a POSITIVE amount_minor. De-allocation never
 * deletes: a reversal is a new row whose amount_minor is the exact NEGATIVE of
 * the allocation it reverses (`reverses_allocation_id`). Applied amount is the
 * net SUM, always exact.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_id
 * @property string $invoice_id
 * @property int $amount_minor
 * @property string|null $reverses_allocation_id
 * @property string|null $reason
 * @property int $allocated_by
 * @property Carbon $allocated_at
 */
class PaymentAllocation extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'amount_minor',
        'reverses_allocation_id',
        'reason',
        'allocated_by',
        'allocated_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (PaymentAllocation $allocation) => $allocation->assertTenantReferences());
        static::updating(function (): void {
            throw new LogicException('payment_allocations are append-only: they cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('payment_allocations are append-only: they cannot be deleted; reverse instead.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'allocated_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    public function reversesAllocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_allocation_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_allocation_id !== null;
    }

    private function assertTenantReferences(): void
    {
        if (! Payment::query()->whereKey($this->payment_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('payment_id', (string) $this->payment_id);
        }

        if (! Invoice::query()->whereKey($this->invoice_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('invoice_id', (string) $this->invoice_id);
        }

        if ($this->reverses_allocation_id !== null && ! self::query()->whereKey($this->reverses_allocation_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('reverses_allocation_id', (string) $this->reverses_allocation_id);
        }
    }
}
