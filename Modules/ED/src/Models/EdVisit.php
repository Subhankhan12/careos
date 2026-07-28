<?php

namespace Modules\ED\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\ED\Exceptions\EdVisitException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;

/**
 * An EMERGENCY-DEPARTMENT presentation / visit (ED.G1) — a NET-NEW patient-FLOW entity
 * (docs/HOSPITAL-PHASE6-ED-MAP.md §2.1). It is deliberately NEITHER a Clinical `Encounter` (a single-sitting,
 * one-open-per-practitioner visit — the ED presentation has an arrival→triage→treatment→disposition flow) NOR
 * an inpatient `Stay` (an inpatient episode with a bed — MOST ED visits discharge home and never become one).
 * ED clinical documentation will REUSE `Encounter` later (G4); the visit itself is its own flow entity — the
 * Bed / Stay / SurgicalCase "own flow-entity above a reused primitive" discipline.
 *
 * This is the mutable CURRENT state (the current flow `status`, the disposition set at the end); the immutable
 * arrival + transition history is {@see EdVisitEvent} (append-only). The lifecycle is a LEGAL-ONLY state
 * machine (arrived → triaged → in_treatment → awaiting_disposition → dispositioned; left_without_being_seen
 * from the pre-treatment states). Tenant + branch scoped; patient-scoped read-logged ({@see LogsReads}).
 *
 * ELECTRIC FENCE: an ED visit + its flow state are OPERATIONAL FACTS. `arrival_mode` and `disposition` are
 * human-recorded operational classifications (route / outcome — the `Stay::admission_type` /
 * `discharge_disposition` precedent), never a computed acuity. There is deliberately NO acuity / triage /
 * priority / severity / risk / score column: the triage nurse ASSIGNS the acuity as a recorded fact in a
 * separate triage record (ED.G2); the system computes it NEVER (a computed triage acuity is the fence line —
 * map §3 — a certified-partner / non-goal).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $branch_id
 * @property Carbon $arrived_at
 * @property string $arrival_mode
 * @property string $chief_complaint
 * @property string $status
 * @property string|null $disposition
 * @property string|null $stay_id
 * @property Carbon|null $dispositioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient|null $patient
 * @property-read Branch|null $branch
 * @property-read Collection<int, EdVisitEvent> $events
 * @property-read Collection<int, EdTriage> $triages
 * @property-read EdTriage|null $latestTriage
 */
class EdVisit extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    // The ED-visit flow lifecycle. `status` moves ONLY through the legal-only transition machine (the service
    // forceFills it); an ED patient arrives, is triaged, is treated, awaits a disposition, and is
    // dispositioned — or leaves without being seen from a pre-treatment state.
    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_IN_TREATMENT = 'in_treatment';

    public const STATUS_AWAITING_DISPOSITION = 'awaiting_disposition';

    public const STATUS_DISPOSITIONED = 'dispositioned';

    public const STATUS_LEFT_WITHOUT_BEING_SEEN = 'left_without_being_seen';

    public const STATUSES = [
        self::STATUS_ARRIVED, self::STATUS_TRIAGED, self::STATUS_IN_TREATMENT,
        self::STATUS_AWAITING_DISPOSITION, self::STATUS_DISPOSITIONED,
        self::STATUS_LEFT_WITHOUT_BEING_SEEN,
    ];

    // The LEGAL-ONLY lifecycle. dispositioned + left_without_being_seen are terminal; a patient may leave
    // without being seen only from a pre-treatment state (arrived / triaged). An illegal move throws.
    public const TRANSITIONS = [
        self::STATUS_ARRIVED => [self::STATUS_TRIAGED, self::STATUS_LEFT_WITHOUT_BEING_SEEN],
        self::STATUS_TRIAGED => [self::STATUS_IN_TREATMENT, self::STATUS_LEFT_WITHOUT_BEING_SEEN],
        self::STATUS_IN_TREATMENT => [self::STATUS_AWAITING_DISPOSITION],
        self::STATUS_AWAITING_DISPOSITION => [self::STATUS_DISPOSITIONED],
        self::STATUS_DISPOSITIONED => [],
        self::STATUS_LEFT_WITHOUT_BEING_SEEN => [],
    ];

    // Arrival ROUTE — an operational classification a human records at arrival (not a clinical acuity).
    public const ARRIVAL_WALK_IN = 'walk_in';

    public const ARRIVAL_AMBULANCE = 'ambulance';

    public const ARRIVAL_REFERRAL = 'referral';

    public const ARRIVAL_MODES = [self::ARRIVAL_WALK_IN, self::ARRIVAL_AMBULANCE, self::ARRIVAL_REFERRAL];

    // Disposition OUTCOME — the operational route out of the ED, recorded at the end (the detail of the
    // ED→ADT admit handoff is ED.G5). A recorded fact, like Stay::discharge_disposition — never computed.
    public const DISPOSITION_ADMIT = 'admit';

    public const DISPOSITION_DISCHARGE = 'discharge';

    public const DISPOSITION_TRANSFER = 'transfer';

    public const DISPOSITIONS = [self::DISPOSITION_ADMIT, self::DISPOSITION_DISCHARGE, self::DISPOSITION_TRANSFER];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'branch_id',
        'arrived_at',
        'arrival_mode',
        'chief_complaint',
        // `status`, `disposition`, `dispositioned_at` stay out of fillable — they move only through the
        // legal-only transition machine (forceFill in the service).
    ];

    protected $attributes = [
        'status' => self::STATUS_ARRIVED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'dispositioned_at' => 'datetime',
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    protected static function booted(): void
    {
        static::saving(function (EdVisit $visit): void {
            if (trim((string) $visit->chief_complaint) === '') {
                throw EdVisitException::chiefComplaintRequired();
            }
        });

        static::creating(function (EdVisit $visit): void {
            if (! in_array($visit->arrival_mode, self::ARRIVAL_MODES, true)) {
                throw EdVisitException::invalidArrivalMode((string) $visit->arrival_mode);
            }
            $visit->assertReferencesWithinTenant();
        });

        static::updating(function (EdVisit $visit): void {
            if ($visit->isDirty(['patient_id', 'branch_id'])) {
                $visit->assertReferencesWithinTenant();
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The append-only flow history (one immutable row for arrival + each transition). */
    public function events(): HasMany
    {
        return $this->hasMany(EdVisitEvent::class);
    }

    /** The append-only triage records (ED.G2), newest first when read. */
    public function triages(): HasMany
    {
        return $this->hasMany(EdTriage::class);
    }

    /**
     * The MOST RECENT triage — the source of the board's RECORDED acuity (the nurse's assigned value, a fact).
     * A convenience read; it computes no acuity.
     */
    public function latestTriage(): HasOne
    {
        return $this->hasOne(EdTriage::class)->latestOfMany('triaged_at');
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    /**
     * patient + branch are tenant-scoped by BelongsToTenant, so one owned by another tenant is invisible here
     * and rejected as a cross-tenant link.
     */
    private function assertReferencesWithinTenant(): void
    {
        $checks = [
            'patient_id' => fn (): bool => Patient::whereKey($this->patient_id)->exists(),
            'branch_id' => fn (): bool => Branch::whereKey($this->branch_id)->exists(),
        ];

        foreach ($checks as $attribute => $exists) {
            if (! empty($this->{$attribute}) && ! $exists()) {
                throw CrossTenantReferenceException::forAttribute($attribute, (string) $this->{$attribute});
            }
        }
    }
}
