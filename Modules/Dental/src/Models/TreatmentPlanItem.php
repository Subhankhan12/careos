<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A planned procedure within a phase: the dental_procedure (catalog item), the tooth/surface,
 * and the ESTIMATED fee. `estimated_fee_minor` is a SNAPSHOT at proposal (integer minor units,
 * from the G3 tariff fee) — null while draft (the estimate reads the live fee for display).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $treatment_plan_id
 * @property string $treatment_plan_phase_id
 * @property string $dental_procedure_id
 * @property string|null $tooth
 * @property string|null $surface
 * @property int|null $estimated_fee_minor
 * @property int $sequence
 */
class TreatmentPlanItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'treatment_plan_id',
        'treatment_plan_phase_id',
        'dental_procedure_id',
        'tooth',
        'surface',
        'estimated_fee_minor',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_fee_minor' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DentalProcedure, $this>
     */
    public function dentalProcedure(): BelongsTo
    {
        return $this->belongsTo(DentalProcedure::class);
    }

    /**
     * @return BelongsTo<TreatmentPlan, $this>
     */
    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
    }
}
