<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Tenant-owned clinical document metadata. The file itself is private storage.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string|null $encounter_id
 * @property string $category
 * @property string $title
 * @property string $original_filename
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $uploaded_by
 * @property Carbon $uploaded_at
 * @property bool $shared_with_patient
 * @property Carbon|null $shared_at
 * @property Carbon|null $deleted_at
 */
class Document extends Model
{
    use BelongsToTenant, HasUlids, LogsReads, SoftDeletes;

    public const CATEGORY_LETTER = 'letter';

    public const CATEGORY_RESULT = 'result';

    public const CATEGORY_IMAGE = 'image';

    public const CATEGORY_CONSENT = 'consent';

    public const CATEGORY_OTHER = 'other';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'encounter_id',
        'category',
        'title',
        'original_filename',
        'storage_path',
        'mime_type',
        'size_bytes',
        'uploaded_by',
        'uploaded_at',
        'shared_with_patient',
        'shared_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'uploaded_at' => 'datetime',
            'shared_with_patient' => 'boolean',
            'shared_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Document $document) => $document->assertTenantReferences());
        static::updating(function (Document $document): void {
            if ($document->isDirty(['patient_id', 'encounter_id', 'uploaded_by'])) {
                $document->assertTenantReferences();
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_LETTER,
            self::CATEGORY_RESULT,
            self::CATEGORY_IMAGE,
            self::CATEGORY_CONSENT,
            self::CATEGORY_OTHER,
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

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function auditResourceType(): string
    {
        return 'document';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    private function assertTenantReferences(): void
    {
        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if ($this->encounter_id !== null) {
            $encounter = Encounter::query()->whereKey($this->encounter_id)->first();
            if ($encounter === null || $encounter->patient_id !== $this->patient_id) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $this->encounter_id);
            }
        }

        if (! User::query()->whereKey($this->uploaded_by)->where('tenant_id', $this->tenant_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('uploaded_by', (string) $this->uploaded_by);
        }
    }
}
