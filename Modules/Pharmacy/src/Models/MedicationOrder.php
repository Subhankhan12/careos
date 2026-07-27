<?php

namespace Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\Pharmacy\Exceptions\MedicationOrderException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A medication ORDER (PHARMACY.G2) — a NET-NEW prescribing entity (the generic clinical `Order` lacks
 * dose/route/frequency/PRN, so it is NOT forced; the map §2.2). The MUTABLE current state (status + the
 * clinician's med-specific fields); the immutable history is {@see MedicationOrderEvent} (append-only). The
 * legal-only lifecycle (active → held → discontinued/completed) is a clinician-driven state machine.
 *
 * `stay_id` is a SOFT nullable reference to a Phase-1 inpatient stay (no FK/relation — Pharmacy stays
 * arch-independent of Hospital; null for outpatient). Patient-scoped read-logged ({@see LogsReads}).
 *
 * ELECTRIC FENCE (record-not-judge): every field is what the CLINICIAN ordered — the system records it. It
 * computes NO dose, suggests NO med, ranks NO alternative, and stores NO safety verdict/severity/score/risk.
 * Medication-safety judgment lives ONLY behind the certified-partner seam (called advisorily at placement).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property int $prescribed_by
 * @property string $formulary_item_id
 * @property string|null $stay_id
 * @property string $dose_amount
 * @property string $dose_unit
 * @property string $route
 * @property string $frequency
 * @property Carbon $starts_at
 * @property Carbon|null $stops_at
 * @property bool $prn
 * @property string|null $prn_reason
 * @property string|null $note
 * @property string $status
 * @property string|null $status_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FormularyItem|null $formularyItem
 * @property-read Patient|null $patient
 */
class MedicationOrder extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_HELD = 'held';

    public const STATUS_DISCONTINUED = 'discontinued';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_HELD, self::STATUS_DISCONTINUED, self::STATUS_COMPLETED];

    // The legal-only lifecycle. Discontinued/completed are terminal; a held order can resume or be stopped.
    public const TRANSITIONS = [
        self::STATUS_ACTIVE => [self::STATUS_HELD, self::STATUS_DISCONTINUED, self::STATUS_COMPLETED],
        self::STATUS_HELD => [self::STATUS_ACTIVE, self::STATUS_DISCONTINUED, self::STATUS_COMPLETED],
        self::STATUS_DISCONTINUED => [],
        self::STATUS_COMPLETED => [],
    ];

    // A plain, closed set of administration routes the clinician selects — an operational type, never a judgment.
    public const ROUTE_PO = 'PO';

    public const ROUTE_IV = 'IV';

    public const ROUTE_IM = 'IM';

    public const ROUTE_SC = 'SC';

    public const ROUTE_TOPICAL = 'topical';

    public const ROUTE_INHALED = 'inhaled';

    public const ROUTE_OTHER = 'other';

    public const ROUTES = [
        self::ROUTE_PO, self::ROUTE_IV, self::ROUTE_IM, self::ROUTE_SC,
        self::ROUTE_TOPICAL, self::ROUTE_INHALED, self::ROUTE_OTHER,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'prescribed_by',
        'formulary_item_id',
        'stay_id',
        'dose_amount',
        'dose_unit',
        'route',
        'frequency',
        'starts_at',
        'stops_at',
        'prn',
        'prn_reason',
        'note',
        'status',
        'status_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'prn' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (MedicationOrder $order): void {
            if (! in_array($order->route, self::ROUTES, true)) {
                throw MedicationOrderException::invalidRoute((string) $order->route);
            }
            if (! in_array($order->status, self::STATUSES, true)) {
                throw MedicationOrderException::invalidStatus((string) $order->status);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'stops_at' => 'datetime',
            'prn' => 'boolean',
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function formularyItem(): BelongsTo
    {
        return $this->belongsTo(FormularyItem::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MedicationOrderEvent::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
