<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Services\SettingsService;

/**
 * Frozen legal invoice document after issue.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $payer_type
 * @property string|null $payer_name
 * @property string|null $number
 * @property string $series
 * @property string $status
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $vat_total_minor
 * @property int $total_minor
 * @property int $open_balance_minor
 * @property string|null $credit_note_for_invoice_id
 * @property string|null $pdf_path
 */
class Invoice extends Model
{
    use BelongsToTenant, HasUlids;

    public const PAYER_SELF_PAY = 'self_pay';

    public const PAYER_PRIVATE_INSURANCE = 'private_insurance';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_CANCELLED_BY_CREDIT_NOTE = 'cancelled_by_credit_note';

    public const SERIES_INVOICE = 'INV';

    public const SERIES_CREDIT_NOTE = 'CN';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'payer_type',
        'payer_name',
        'number',
        'series',
        'status',
        'issue_date',
        'due_date',
        'currency',
        'subtotal_minor',
        'vat_total_minor',
        'total_minor',
        'open_balance_minor',
        'credit_note_for_invoice_id',
        'pdf_path',
    ];

    protected $attributes = [
        'series' => self::SERIES_INVOICE,
        'status' => self::STATUS_DRAFT,
        'subtotal_minor' => 0,
        'vat_total_minor' => 0,
        'total_minor' => 0,
        'open_balance_minor' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            $invoice->currency = $invoice->currency ?: (string) app(SettingsService::class)->get('currency', 'EUR');
            $invoice->assertTenantReferences();
        });

        static::updating(function (Invoice $invoice): void {
            if (self::isFrozenStatus((string) $invoice->getOriginal('status'))) {
                throw new LogicException('Issued invoices are immutable.');
            }

            if ($invoice->isDirty(['patient_id', 'credit_note_for_invoice_id'])) {
                $invoice->assertTenantReferences();
            }
        });

        static::deleting(function (Invoice $invoice): void {
            if (self::isFrozenStatus($invoice->status)) {
                throw new LogicException('Issued invoices cannot be deleted.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal_minor' => 'integer',
            'vat_total_minor' => 'integer',
            'total_minor' => 'integer',
            'open_balance_minor' => 'integer',
        ];
    }

    public static function isFrozenStatus(string $status): bool
    {
        return in_array($status, [
            self::STATUS_ISSUED,
            self::STATUS_PAID,
            self::STATUS_PARTIALLY_PAID,
            self::STATUS_CANCELLED_BY_CREDIT_NOTE,
        ], true);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(InvoiceBalance::class);
    }

    public function creditNoteFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credit_note_for_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credit_note_for_invoice_id');
    }

    private function assertTenantReferences(): void
    {
        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if ($this->credit_note_for_invoice_id !== null && ! self::query()->whereKey($this->credit_note_for_invoice_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('credit_note_for_invoice_id', (string) $this->credit_note_for_invoice_id);
        }
    }
}
