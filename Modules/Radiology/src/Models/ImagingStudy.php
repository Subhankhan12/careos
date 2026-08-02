<?php

namespace Modules\Radiology\Models;

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
 * An IMAGING STUDY (RAD.G3) — the one genuine net-new radiology entity (docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md
 * §2.2), the lab-`Specimen` analog. A record that an exam was (or will be) performed for a RAD.G2
 * {@see RadiologyOrder} (which links the reused Clinical `Order` underneath), accessioned with a
 * unique-per-tenant identifier, and tracked through a legal-only state machine. The mutable CURRENT state; the
 * immutable state history is {@see ImagingStudyEvent} (append-only). Tenant + patient scoped; patient
 * read-logged.
 *
 * **THE STUDY IS METADATA, NOT THE IMAGE.** The DICOM image (storage / a diagnostic viewer / PACS retrieval /
 * modality integration) is the PARTNER's — the SEAM-STUBBED RAD.G6, behind `ImagingConnectivity`, NOT built.
 * This model records that an exam happened + its state; it holds no image and no pixel data.
 *
 * ELECTRIC FENCE (operational): the `status` (where the study is in the workflow) + the `accession_number`
 * (its identifier) are FACTS — never a computed image finding/CAD/abnormality, never a computed priority. There
 * is deliberately NO computed-finding/priority/urgency/score column; a STAT flag is the clinician-recorded flag
 * on the RAD.G2 order, not computed here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $radiology_order_id
 * @property string $accession_number
 * @property string|null $modality
 * @property string $status
 * @property int|null $acquired_by
 * @property Carbon|null $acquired_at
 * @property-read Patient|null $patient
 * @property-read RadiologyOrder|null $radiologyOrder
 * @property-read Collection<int, ImagingStudyEvent> $events
 */
class ImagingStudy extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    // The legal-only study lifecycle. `status` moves ONLY through the transition machine (forceFill in the
    // service). ordered → acquired → reported; a study may be cancelled from a pre-report state (with a reason).
    public const STATUS_ORDERED = 'ordered';

    public const STATUS_ACQUIRED = 'acquired';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_ORDERED, self::STATUS_ACQUIRED, self::STATUS_REPORTED, self::STATUS_CANCELLED];

    // The legal moves. reported + cancelled are terminal; cancellation is allowed only pre-report.
    public const TRANSITIONS = [
        self::STATUS_ORDERED => [self::STATUS_ACQUIRED, self::STATUS_CANCELLED],
        self::STATUS_ACQUIRED => [self::STATUS_REPORTED, self::STATUS_CANCELLED],
        self::STATUS_REPORTED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'radiology_order_id',
        'accession_number',
        'modality',
        'acquired_by',
        'acquired_at',
        // `status` stays out of fillable — it moves only through the legal-only transition machine.
    ];

    protected $attributes = [
        'status' => self::STATUS_ORDERED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['acquired_at' => 'datetime'];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    protected static function booted(): void
    {
        static::creating(function (ImagingStudy $study): void {
            $study->assertReferencesWithinTenant();
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function radiologyOrder(): BelongsTo
    {
        return $this->belongsTo(RadiologyOrder::class);
    }

    /**
     * The append-only state history (one immutable row for the initial ordered + each transition).
     *
     * @return HasMany<ImagingStudyEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ImagingStudyEvent::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    /**
     * patient + imaging order are tenant-scoped by BelongsToTenant, so one owned by another tenant is invisible
     * here and rejected as a cross-tenant link.
     */
    private function assertReferencesWithinTenant(): void
    {
        $checks = [
            'patient_id' => fn (): bool => Patient::whereKey($this->patient_id)->exists(),
            'radiology_order_id' => fn (): bool => RadiologyOrder::whereKey($this->radiology_order_id)->exists(),
        ];

        foreach ($checks as $attribute => $exists) {
            if (! empty($this->{$attribute}) && ! $exists()) {
                throw CrossTenantReferenceException::forAttribute($attribute, (string) $this->{$attribute});
            }
        }
    }
}
