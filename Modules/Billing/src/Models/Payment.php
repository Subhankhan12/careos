<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Tenant-owned append-only money-received record. Never edited or deleted:
 * refunds are separate rows and corrections are new rows.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $patient_id
 * @property string|null $payer_reference
 * @property string $method
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $received_on
 * @property string|null $reference
 * @property int $recorded_by
 */
class Payment extends Model
{
    use BelongsToTenant, HasUlids;

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_CARD = 'card';

    public const METHOD_CASH = 'cash';

    public const METHOD_OTHER = 'other';

    public const METHODS = [
        self::METHOD_BANK_TRANSFER,
        self::METHOD_CARD,
        self::METHOD_CASH,
        self::METHOD_OTHER,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'payer_reference',
        'method',
        'amount_minor',
        'currency',
        'received_on',
        'reference',
        'recorded_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Payment $payment) => $payment->assertTenantReferences());
        static::updating(function (): void {
            throw new LogicException('payments are append-only: they cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('payments are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'amount_minor' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    private function assertTenantReferences(): void
    {
        if ($this->patient_id !== null && ! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }
    }
}
