<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Dental\Exceptions\DentalException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The DENTIST'S written reading of a dental image (DENTAL.G8) — free text they authored. APPEND-ONLY
 * at model AND DB-trigger level: a change/correction is a NEW reading + a reason, never an edit.
 *
 * ELECTRIC FENCE: `reading` is the dentist's own interpretation — the system stores it, never
 * generates it. There is NO AI/CV analysis of the image and no detected/finding/overlay/confidence
 * field. `assertValid()` only checks the reading is non-empty (data entry, not interpretation).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dental_image_id
 * @property string $patient_id
 * @property string $reading the dentist's own written interpretation
 * @property string|null $reason why this supersedes a prior reading (a correction)
 * @property int $read_by
 * @property Carbon $read_at
 */
class DentalImageReading extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'dental_image_id',
        'patient_id',
        'reading',
        'reason',
        'read_by',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<DentalImage, $this>
     */
    public function dentalImage(): BelongsTo
    {
        return $this->belongsTo(DentalImage::class);
    }

    protected static function booted(): void
    {
        static::creating(function (DentalImageReading $reading): void {
            if (trim((string) $reading->reading) === '') {
                throw new DentalException('A reading needs text — it is your written interpretation.');
            }
        });

        // Append-only: a correction is a new reading + reason, never an edit.
        static::updating(function (): void {
            throw new DentalException('dental_image_readings are append-only: a correction is a new reading, not an edit.');
        });
        static::deleting(function (): void {
            throw new DentalException('dental_image_readings are append-only: they cannot be deleted.');
        });
    }
}
