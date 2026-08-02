<?php

namespace Modules\Radiology\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clinical\Models\Encounter;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * The RADIOLOGY-SIDE link (RAD.G4) tying a RAD.G3 {@see ImagingStudy} to the Clinical {@see Encounter} the
 * radiologist's report is authored on (the `EdVisitEncounter` / `WardRound` / `SurgicalCaseEncounter`
 * precedent). Clinical's `Encounter`/`ClinicalNote` are REUSED UNMODIFIED (their schema + sign-and-lock +
 * one-open-per-practitioner invariants untouched); this link keeps the association Radiology-side. The report
 * IS the sign-and-lock `ClinicalNote`(s) on the Encounter.
 *
 * THE FENCE: the report is the radiologist's AUTHORED prose — there is NO computed image finding/CAD/
 * abnormality/confidence column here (AI radiology is a hard non-goal).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $imaging_study_id
 * @property string $encounter_id
 * @property-read ImagingStudy|null $study
 * @property-read Encounter|null $encounter
 */
class ImagingStudyReport extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'imaging_study_id',
        'encounter_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (ImagingStudyReport $link): void {
            // Both the study and the encounter are tenant-scoped (BelongsToTenant), so a reference to another
            // tenant's row is invisible here and rejected — fail-closed.
            if (! empty($link->imaging_study_id) && ! ImagingStudy::whereKey($link->imaging_study_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('imaging_study_id', (string) $link->imaging_study_id);
            }
            if (! empty($link->encounter_id) && ! Encounter::whereKey($link->encounter_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $link->encounter_id);
            }
        });
    }

    public function study(): BelongsTo
    {
        return $this->belongsTo(ImagingStudy::class, 'imaging_study_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
