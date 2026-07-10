<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Clinical\Models\Encounter;
use Modules\Nursing\Models\Visit;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Tenant-owned billable event with tariff price snapshots.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string|null $encounter_id
 * @property string|null $visit_id
 * @property string $branch_id
 * @property Carbon $service_date
 * @property string $tariff_catalog_id
 * @property string $tariff_item_id
 * @property string $code
 * @property string $description
 * @property int $unit_price_minor
 * @property int $vat_rate_bp
 * @property int $quantity
 * @property int $line_total_minor
 * @property string $status
 * @property string|null $invoice_id
 * @property string|null $cancelled_reason
 * @property int $created_by
 */
class Charge extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_INVOICED = 'invoiced';

    public const STATUS_CANCELLED = 'cancelled';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'encounter_id',
        'visit_id',
        'branch_id',
        'service_date',
        'tariff_catalog_id',
        'tariff_item_id',
        'code',
        'description',
        'unit_price_minor',
        'vat_rate_bp',
        'quantity',
        'line_total_minor',
        'status',
        'invoice_id',
        'cancelled_reason',
        'created_by',
    ];

    protected $attributes = [
        'quantity' => 1,
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::creating(fn (Charge $charge) => $charge->assertChargeIsConsistent());

        static::updating(function (Charge $charge): void {
            if ($charge->isDirty([
                'patient_id',
                'encounter_id',
                'visit_id',
                'branch_id',
                'tariff_catalog_id',
                'tariff_item_id',
                'created_by',
            ])) {
                $charge->assertChargeIsConsistent();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'unit_price_minor' => 'integer',
            'vat_rate_bp' => 'integer',
            'quantity' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function tariffCatalog(): BelongsTo
    {
        return $this->belongsTo(TariffCatalog::class);
    }

    public function tariffItem(): BelongsTo
    {
        return $this->belongsTo(TariffItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(ChargeViolation::class);
    }

    public function isManual(): bool
    {
        return $this->encounter_id === null && $this->visit_id === null;
    }

    private function assertChargeIsConsistent(): void
    {
        if ($this->encounter_id !== null && $this->visit_id !== null) {
            throw new InvalidArgumentException('A charge may reference an encounter, a visit, or neither for manual capture; never both.');
        }

        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! Branch::query()->whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }

        if (! TariffCatalog::query()->whereKey($this->tariff_catalog_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('tariff_catalog_id', (string) $this->tariff_catalog_id);
        }

        $tariffItem = TariffItem::query()->whereKey($this->tariff_item_id)->first();
        if (! $tariffItem instanceof TariffItem) {
            throw CrossTenantReferenceException::forAttribute('tariff_item_id', (string) $this->tariff_item_id);
        }

        if ($tariffItem->tariff_catalog_id !== $this->tariff_catalog_id) {
            throw new InvalidArgumentException('Charge tariff item must belong to the snapshotted tariff catalog.');
        }

        if ($this->encounter_id !== null) {
            $encounter = Encounter::query()->whereKey($this->encounter_id)->first();

            if (! $encounter instanceof Encounter) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $this->encounter_id);
            }

            if ($encounter->patient_id !== $this->patient_id || $encounter->branch_id !== $this->branch_id) {
                throw new InvalidArgumentException('Encounter-sourced charges must match the encounter patient and branch.');
            }
        }

        if ($this->visit_id !== null) {
            $visit = Visit::query()->whereKey($this->visit_id)->first();

            if (! $visit instanceof Visit) {
                throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
            }

            if ($visit->patient_id !== $this->patient_id || $visit->branch_id !== $this->branch_id) {
                throw new InvalidArgumentException('Visit-sourced charges must match the visit patient and branch.');
            }
        }

        $currentTenantId = app(TenantContext::class)->id();
        if (! User::query()->whereKey($this->created_by)->where('tenant_id', $currentTenantId)->exists()) {
            throw CrossTenantReferenceException::forAttribute('created_by', (string) $this->created_by);
        }
    }
}
