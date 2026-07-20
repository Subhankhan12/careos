<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Support\ToothNotation;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * One RAW periodontal measurement: a single site of a single tooth within a {@see PerioExam}.
 * The six sites are the standard 6-point probing positions around a tooth.
 *
 * ELECTRIC FENCE (record-not-judge — perio's core risk): every value is RAW as probed —
 * pocket depth (mm), recession (mm), bleeding-on-probing (true/false), and optional mobility
 * and furcation on their raw index scales. There is DELIBERATELY NO stage, grade, severity,
 * risk, classification, or auto-flag. The `assertValid()` check below is pure data-entry
 * validation (a real FDI tooth, a real site, a physically-plausible number) — it never grades
 * or judges the value. The dentist reads the numbers and interprets; this only records them.
 *
 * APPEND-ONLY at model AND DB-trigger level — a correction is a new measurement (a new exam),
 * never an edit.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $perio_exam_id
 * @property string $patient_id
 * @property string $tooth FDI two-digit id (e.g. "11", "36")
 * @property string $site one of PerioMeasurement::SITES
 * @property int|null $pocket_depth_mm raw probing depth in mm
 * @property int|null $recession_mm raw recession in mm (negative = gingival overgrowth)
 * @property bool $bleeding_on_probing raw BOP observation
 * @property int|null $mobility raw Miller mobility index (0–3), per tooth
 * @property int|null $furcation raw Glickman/Hamp furcation class (0–4)
 */
class PerioMeasurement extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * The standard six probing sites around a tooth (mesio-buccal → disto-lingual). These are
     * anatomical positions, not surfaces (perio probes 6 points; the odontogram charts 5
     * surfaces — different vocabularies for different records).
     *
     * @var list<string>
     */
    public const SITES = ['mesio_buccal', 'buccal', 'disto_buccal', 'mesio_lingual', 'lingual', 'disto_lingual'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'perio_exam_id',
        'patient_id',
        'tooth',
        'site',
        'pocket_depth_mm',
        'recession_mm',
        'bleeding_on_probing',
        'mobility',
        'furcation',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pocket_depth_mm' => 'integer',
            'recession_mm' => 'integer',
            'bleeding_on_probing' => 'boolean',
            'mobility' => 'integer',
            'furcation' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PerioExam, $this>
     */
    public function perioExam(): BelongsTo
    {
        return $this->belongsTo(PerioExam::class);
    }

    protected static function booted(): void
    {
        static::creating(function (PerioMeasurement $measurement): void {
            $measurement->assertValid();
        });

        // Append-only (defence in depth alongside the DB triggers).
        static::updating(function (): void {
            throw new DentalException('perio_measurements are append-only: a correction is a new measurement, not an edit.');
        });
        static::deleting(function (): void {
            throw new DentalException('perio_measurements are append-only: they cannot be deleted.');
        });
    }

    /**
     * DETERMINISTIC data-entry validation — NOT interpretation. A tooth must be a valid FDI id;
     * a site must be a real probing site; each numeric value (if given) must be a
     * physically-plausible measurement / a value on its raw index scale. Nothing here grades,
     * scores, stages, or flags — bounds only reject impossible input (e.g. a 200mm pocket),
     * exactly as the odontogram rejects an unknown surface.
     */
    private function assertValid(): void
    {
        if (! ToothNotation::isValid((string) $this->tooth)) {
            throw new DentalException("Invalid FDI tooth id [{$this->tooth}].");
        }

        if (! in_array((string) $this->site, self::SITES, true)) {
            throw new DentalException("Invalid perio site [{$this->site}].");
        }

        // Physical-plausibility / raw-scale bounds. These are data-entry guards, never grades.
        $this->assertInRange('pocket_depth_mm', $this->pocket_depth_mm, 0, 15);
        $this->assertInRange('recession_mm', $this->recession_mm, -15, 30);
        $this->assertInRange('mobility', $this->mobility, 0, 3);
        $this->assertInRange('furcation', $this->furcation, 0, 4);
    }

    private function assertInRange(string $field, ?int $value, int $min, int $max): void
    {
        if ($value !== null && ($value < $min || $value > $max)) {
            throw new DentalException("Measurement [{$field}] value [{$value}] is outside the recordable range.");
        }
    }
}
