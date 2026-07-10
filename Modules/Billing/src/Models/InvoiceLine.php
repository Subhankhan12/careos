<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Self-contained invoice line copied from charge or original invoice line snapshots.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string|null $charge_id
 * @property string|null $original_invoice_line_id
 * @property string $code
 * @property string $description
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $vat_rate_bp
 * @property int $line_total_minor
 * @property int $line_vat_minor
 */
class InvoiceLine extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'invoice_id',
        'charge_id',
        'original_invoice_line_id',
        'code',
        'description',
        'quantity',
        'unit_price_minor',
        'vat_rate_bp',
        'line_total_minor',
        'line_vat_minor',
    ];

    protected static function booted(): void
    {
        static::creating(fn (InvoiceLine $line) => $line->assertTenantReferences());

        static::updating(function (InvoiceLine $line): void {
            if ($line->belongsToFrozenInvoice()) {
                throw new LogicException('Issued invoice lines are immutable.');
            }

            if ($line->isDirty(['invoice_id', 'charge_id', 'original_invoice_line_id'])) {
                $line->assertTenantReferences();
            }
        });

        static::deleting(function (InvoiceLine $line): void {
            if ($line->belongsToFrozenInvoice()) {
                throw new LogicException('Issued invoice lines cannot be deleted.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'vat_rate_bp' => 'integer',
            'line_total_minor' => 'integer',
            'line_vat_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function originalInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_line_id');
    }

    private function belongsToFrozenInvoice(): bool
    {
        $invoice = Invoice::query()->whereKey($this->invoice_id)->first();

        return $invoice instanceof Invoice && Invoice::isFrozenStatus($invoice->status);
    }

    private function assertTenantReferences(): void
    {
        if (! Invoice::query()->whereKey($this->invoice_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('invoice_id', (string) $this->invoice_id);
        }

        if ($this->charge_id !== null && ! Charge::query()->whereKey($this->charge_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('charge_id', (string) $this->charge_id);
        }

        if ($this->original_invoice_line_id !== null && ! self::query()->whereKey($this->original_invoice_line_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('original_invoice_line_id', (string) $this->original_invoice_line_id);
        }
    }
}
