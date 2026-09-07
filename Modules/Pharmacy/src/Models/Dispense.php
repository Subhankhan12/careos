<?php

namespace Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Pharmacy\Exceptions\DispensingException;
use Modules\Pharmacy\Services\PharmacyBillingService;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A DISPENSING event (PHARMACY.G4) — one APPEND-ONLY row recording that a pharmacist dispensed a quantity
 * against a G2 {@see MedicationOrder}, decrementing stock. Model guards + DB triggers (the
 * `medication_order_events` recipe). Patient-scoped read-logged ({@see LogsReads}); `stay_id` is a SOFT
 * nullable inpatient ref (no relation). Operational fact — no safety judgment is computed on a dispense.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $medication_order_id
 * @property string $formulary_item_id
 * @property int $quantity
 * @property int $dispensed_by
 * @property Carbon $dispensed_at
 * @property string|null $stay_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MedicationOrder|null $order
 * @property-read FormularyItem|null $formularyItem
 */
class Dispense extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'medication_order_id',
        'formulary_item_id',
        'quantity',
        'dispensed_by',
        'dispensed_at',
        'stay_id',
    ];

    protected static function booted(): void
    {
        // Append-only: a dispense is an immutable operational event (belt for the DB triggers).
        static::updating(fn () => throw DispensingException::appendOnly());
        static::deleting(fn () => throw DispensingException::appendOnly());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'dispensed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class, 'medication_order_id');
    }

    public function formularyItem(): BelongsTo
    {
        return $this->belongsTo(FormularyItem::class);
    }

    /**
     * The billing charge accrued for this dispense, if one was — the link is created by
     * {@see PharmacyBillingService::chargeForDispense()}.
     */
    public function charge(): HasOne
    {
        return $this->hasOne(DispenseCharge::class);
    }

    /**
     * Dispenses that carry NO billing charge (QA-FIX.5b, P5-C2 / P5-M4).
     *
     * Phase 5 found that a `pharmacy_technician` — who deliberately lacks `billing.manage` — produces a
     * dispense with no charge, and that NOTHING in the product could find those rows afterwards, so the
     * "reconcilable later" the code promised was unbacked. This scope is what makes them findable.
     *
     * It is a FACT, not a verdict: a dispense may legitimately be uncharged (an unpriced medication),
     * and this scope does not distinguish why. It answers "which dispenses have no charge", nothing more.
     */
    public function scopeUncharged(Builder $query): Builder
    {
        return $query->whereDoesntHave('charge');
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
