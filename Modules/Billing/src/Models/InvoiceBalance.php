<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Mutable invoice balance/status projection for payments and credit-note effects.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $status
 * @property int $open_balance_minor
 */
class InvoiceBalance extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'invoice_id',
        'status',
        'open_balance_minor',
    ];

    protected $attributes = [
        'status' => Invoice::STATUS_DRAFT,
        'open_balance_minor' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(fn (InvoiceBalance $balance) => $balance->assertTenantReferences());
        static::updating(function (InvoiceBalance $balance): void {
            if ($balance->isDirty('invoice_id')) {
                $balance->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'open_balance_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    private function assertTenantReferences(): void
    {
        if (! Invoice::query()->whereKey($this->invoice_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('invoice_id', (string) $this->invoice_id);
        }
    }
}
