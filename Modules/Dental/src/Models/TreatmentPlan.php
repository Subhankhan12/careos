<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A patient's DENTIST-AUTHORED dental treatment plan: proposed procedures in phases, with a
 * fee-schedule ESTIMATE, that the patient accepts and works through. It is an estimate + an
 * agreement, NOT billing — accepting it posts no charge (the charge happens when the procedure
 * is performed, G4). ELECTRIC FENCE: no auto-suggestion / severity / AI-recommended treatment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property int $created_by
 * @property string|null $title
 * @property string $status
 * @property Carbon|null $accepted_at
 */
class TreatmentPlan extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DECLINED = 'declined';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'created_by',
        'title',
        'status',
        'accepted_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /**
     * @return HasMany<TreatmentPlanPhase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(TreatmentPlanPhase::class);
    }

    /**
     * @return HasMany<TreatmentPlanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TreatmentPlanItem::class);
    }

    protected function auditResourceType(): string
    {
        return 'treatment_plans';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
