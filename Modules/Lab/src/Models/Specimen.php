<?php

namespace Modules\Lab\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * A SPECIMEN (LAB.G3) — the one genuine net-new lab entity (docs/HOSPITAL-PHASE3-LAB-MAP.md §2.2). Collected
 * from the patient against a LAB.G2 {@see LabOrder} (which links the reused Clinical `Order` underneath),
 * accessioned with a unique-per-tenant identifier, and tracked through a legal-only state machine. The mutable
 * CURRENT state; the immutable state history is {@see SpecimenEvent} (append-only). Tenant + patient scoped;
 * patient read-logged.
 *
 * ELECTRIC FENCE (operational): the `status` (where the specimen is in the lab workflow) + the
 * `accession_number` (its identifier) are FACTS — never a computed priority/urgency/routing judgment. There is
 * deliberately NO computed-priority/urgency/score/rank column; the STAT flag is the clinician-recorded flag on
 * the LAB.G2 order, not computed here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $lab_order_id
 * @property string $accession_number
 * @property string|null $specimen_type
 * @property string|null $container_type
 * @property string|null $collection_note
 * @property string $status
 * @property int $collected_by
 * @property Carbon $collected_at
 * @property-read Patient|null $patient
 * @property-read LabOrder|null $labOrder
 * @property-read Collection<int, SpecimenEvent> $events
 */
class Specimen extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    // The legal-only specimen lifecycle. `status` moves ONLY through the transition machine (forceFill in the
    // service). collected → in_lab → resulted; a specimen may be rejected from a pre-result state (with a reason).
    public const STATUS_COLLECTED = 'collected';

    public const STATUS_IN_LAB = 'in_lab';

    public const STATUS_RESULTED = 'resulted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_COLLECTED, self::STATUS_IN_LAB, self::STATUS_RESULTED, self::STATUS_REJECTED];

    // The legal moves. resulted + rejected are terminal; rejection is allowed only pre-result.
    public const TRANSITIONS = [
        self::STATUS_COLLECTED => [self::STATUS_IN_LAB, self::STATUS_REJECTED],
        self::STATUS_IN_LAB => [self::STATUS_RESULTED, self::STATUS_REJECTED],
        self::STATUS_RESULTED => [],
        self::STATUS_REJECTED => [],
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'lab_order_id',
        'accession_number',
        'specimen_type',
        'container_type',
        'collection_note',
        'collected_by',
        'collected_at',
        // `status` stays out of fillable — it moves only through the legal-only transition machine.
    ];

    protected $attributes = [
        'status' => self::STATUS_COLLECTED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['collected_at' => 'datetime'];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    protected static function booted(): void
    {
        static::creating(function (Specimen $specimen): void {
            $specimen->assertReferencesWithinTenant();
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class);
    }

    /**
     * The append-only state history (one immutable row for collection + each transition).
     *
     * @return HasMany<SpecimenEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SpecimenEvent::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    /**
     * patient + lab order are tenant-scoped by BelongsToTenant, so one owned by another tenant is invisible
     * here and rejected as a cross-tenant link.
     */
    private function assertReferencesWithinTenant(): void
    {
        $checks = [
            'patient_id' => fn (): bool => Patient::whereKey($this->patient_id)->exists(),
            'lab_order_id' => fn (): bool => LabOrder::whereKey($this->lab_order_id)->exists(),
        ];

        foreach ($checks as $attribute => $exists) {
            if (! empty($this->{$attribute}) && ! $exists()) {
                throw CrossTenantReferenceException::forAttribute($attribute, (string) $this->{$attribute});
            }
        }
    }
}
