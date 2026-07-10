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
 * Tenant-owned append-only refund row referencing a payment. A refund is never a
 * negative payment and never an edit of the original payment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_id
 * @property int $amount_minor
 * @property string $reason
 * @property int $refunded_by
 * @property Carbon $refunded_at
 */
class Refund extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'payment_id',
        'amount_minor',
        'reason',
        'refunded_by',
        'refunded_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Refund $refund) => $refund->assertTenantReferences());
        static::updating(function (): void {
            throw new LogicException('refunds are append-only: they cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('refunds are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    private function assertTenantReferences(): void
    {
        if (! Payment::query()->whereKey($this->payment_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('payment_id', (string) $this->payment_id);
        }
    }
}
