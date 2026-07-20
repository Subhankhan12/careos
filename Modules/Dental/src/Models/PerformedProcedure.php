<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Dental\Exceptions\DentalException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A procedure the dentist ACTUALLY performed — the clinical fact. APPEND-ONLY at model
 * and DB-trigger level (a completed procedure is a record; a correction is a NEW record +
 * `reason`, never an edit — same posture as tooth_records / clinical notes / order results).
 *
 * ELECTRIC FENCE: it records what was DONE (procedure, tooth/surface, when, by whom), not
 * a judgment — no severity/score/grade/recommendation. It always carries the resulting
 * billing `charge_id` (the perform workflow writes clinical + charge + tooth-state together).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $dental_procedure_id
 * @property string $charge_id
 * @property string|null $tooth
 * @property string|null $surface
 * @property int $performed_by
 * @property Carbon $performed_at
 * @property string|null $note
 * @property string|null $reason
 * @property string $status
 */
class PerformedProcedure extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_COMPLETED = 'completed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'dental_procedure_id',
        'charge_id',
        'tooth',
        'surface',
        'performed_by',
        'performed_at',
        'note',
        'reason',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_COMPLETED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['performed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DentalException('performed_procedures are append-only: a correction is a new record, not an edit.');
        });
        static::deleting(function (): void {
            throw new DentalException('performed_procedures are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<DentalProcedure, $this>
     */
    public function dentalProcedure(): BelongsTo
    {
        return $this->belongsTo(DentalProcedure::class);
    }

    protected function auditResourceType(): string
    {
        return 'performed_procedures';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
