<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Dental\Exceptions\DentalException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A point-in-time periodontal charting session — a full or partial 6-point probing of a
 * patient's teeth (DENTAL.G6). The exam header records WHO charted, WHEN, and an optional
 * note; the raw per-site numbers live in {@see PerioMeasurement}.
 *
 * APPEND-ONLY at model AND DB-trigger level (a re-exam is a NEW exam; corrections are new
 * records, never edits — the same discipline as tooth_records / vitals). Historical exams
 * are never destroyed; a patient's perio history is the ordered sequence of exams.
 *
 * ELECTRIC FENCE (record-not-judge): there is NO staging, grade, severity, risk, or
 * classification anywhere — an exam records raw measurements; the dentist interprets them.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property int $examined_by
 * @property Carbon $exam_date
 * @property string|null $note
 */
class PerioExam extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'examined_by',
        'exam_date',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['exam_date' => 'date'];
    }

    /**
     * @return HasMany<PerioMeasurement, $this>
     */
    public function measurements(): HasMany
    {
        return $this->hasMany(PerioMeasurement::class);
    }

    protected static function booted(): void
    {
        // Append-only: a re-exam is a new exam, never an edit (mirrors tooth_records / the
        // clinical ledgers). DB triggers enforce this too; this is defence in depth.
        static::updating(function (): void {
            throw new DentalException('perio_exams are append-only: a re-exam is a new exam, not an edit.');
        });
        static::deleting(function (): void {
            throw new DentalException('perio_exams are append-only: they cannot be deleted.');
        });
    }

    protected function auditResourceType(): string
    {
        return 'perio_exams';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
