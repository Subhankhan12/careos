<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Tenant-owned structured SOAP clinical note.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $encounter_id
 * @property string $patient_id
 * @property string $author_id
 * @property string|null $subjective
 * @property string|null $objective
 * @property string|null $assessment
 * @property string|null $plan
 * @property string|null $template_id
 * @property string $status
 * @property Carbon|null $signed_at
 * @property int|null $signed_by
 * @property int $version
 * @property string|null $supersedes_id
 * @property string|null $amendment_reason
 */
class ClinicalNote extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SIGNED = 'signed';

    public const SECTION_SUBJECTIVE = 'subjective';

    public const SECTION_OBJECTIVE = 'objective';

    public const SECTION_ASSESSMENT = 'assessment';

    public const SECTION_PLAN = 'plan';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'encounter_id',
        'patient_id',
        'author_id',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'template_id',
        'status',
        'signed_at',
        'signed_by',
        'version',
        'supersedes_id',
        'amendment_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'version' => 1,
    ];

    protected static function booted(): void
    {
        static::creating(function (ClinicalNote $note): void {
            $note->assertTenantReferences();
        });

        static::updating(function (ClinicalNote $note): void {
            if ($note->getOriginal('status') === self::STATUS_SIGNED) {
                throw new LogicException('Signed clinical notes are immutable.');
            }

            if ($note->isDirty([
                'encounter_id',
                'patient_id',
                'author_id',
                'template_id',
                'signed_by',
                'supersedes_id',
            ])) {
                $note->assertTenantReferences();
            }
        });

        static::deleting(function (ClinicalNote $note): void {
            if ($note->status === self::STATUS_SIGNED) {
                throw new LogicException('Signed clinical notes cannot be deleted.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'author_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NoteTemplate::class, 'template_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    protected function auditResourceType(): string
    {
        return 'clinical_note';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    private function assertTenantReferences(): void
    {
        $encounter = Encounter::query()->whereKey($this->encounter_id)->first();
        if ($encounter === null) {
            throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $this->encounter_id);
        }

        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if ($encounter->patient_id !== $this->patient_id) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! StaffProfile::query()->whereKey($this->author_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('author_id', (string) $this->author_id);
        }

        if ($this->template_id !== null && ! NoteTemplate::query()->whereKey($this->template_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('template_id', (string) $this->template_id);
        }

        if ($this->supersedes_id !== null) {
            if (trim((string) $this->amendment_reason) === '') {
                throw new LogicException('Amendments require a reason.');
            }

            if (! self::query()->whereKey($this->supersedes_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('supersedes_id', (string) $this->supersedes_id);
            }
        }

        if ($this->signed_by === null) {
            return;
        }

        if (! User::query()->whereKey($this->signed_by)->where('tenant_id', $this->tenant_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('signed_by', (string) $this->signed_by);
        }
    }
}
