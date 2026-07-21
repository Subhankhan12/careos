<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Clinical\Models\Document;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Support\ToothNotation;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A dental image/scan — the dental METADATA over a file stored through the EXISTING clinical
 * document storage (DENTAL.G8). `document` is the private-disk asset; this row records the image
 * type, the tooth/region, and who captured it. The dentist's interpretation lives in the
 * append-only {@see DentalImageReading} records.
 *
 * APPEND-ONLY (immutable): a captured image is never edited (UPDATE/DELETE throw at model AND
 * DB-trigger level).
 *
 * ELECTRIC FENCE: the system stores and displays the image — it NEVER analyses it. There is no
 * ai/finding/detected/overlay/confidence field, and no code path computes anything about the
 * pixels. `image_type` is a plain, tenant-meaningful label; `assertValid()` is pure data-entry
 * validation (a known type, a valid FDI id) — it never interprets the image.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $document_id
 * @property string $image_type
 * @property string|null $tooth FDI id it relates to (optional)
 * @property string|null $region free-text region (optional)
 * @property Carbon $captured_at
 * @property int $uploaded_by
 */
class DentalImage extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    /** Plain, tenant-meaningful image types — a label, never an interpretation. */
    public const TYPES = ['bitewing', 'periapical', 'panoramic', 'photo', 'scan'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'document_id',
        'image_type',
        'tooth',
        'region',
        'captured_at',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasMany<DentalImageReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(DentalImageReading::class);
    }

    protected static function booted(): void
    {
        static::creating(function (DentalImage $image): void {
            $image->assertValid();
        });

        // Immutable: a captured image is never edited (DB triggers enforce this too).
        static::updating(function (): void {
            throw new DentalException('dental_images are immutable: a captured image cannot be edited.');
        });
        static::deleting(function (): void {
            throw new DentalException('dental_images are immutable: they cannot be deleted.');
        });
    }

    /**
     * DETERMINISTIC data-entry validation — NOT interpretation. The image type must be a known
     * label; a tooth (if given) must be a valid FDI id. Nothing here analyses the image.
     */
    private function assertValid(): void
    {
        if (! in_array((string) $this->image_type, self::TYPES, true)) {
            throw new DentalException("Invalid dental image type [{$this->image_type}].");
        }

        if ($this->tooth !== null && ! ToothNotation::isValid((string) $this->tooth)) {
            throw new DentalException("Invalid FDI tooth id [{$this->tooth}].");
        }
    }

    protected function auditResourceType(): string
    {
        return 'dental_images';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
