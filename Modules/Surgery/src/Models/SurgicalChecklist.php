<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The per-case WHO Surgical Safety Checklist CONTAINER (SURGERY.G3) — one per case, tying the append-only item
 * confirmations ({@see SurgicalChecklistItem}) to the G1/G2 {@see SurgicalCase}. Patient read-logged
 * ({@see LogsReads}). A RECORD of what the team confirmed — it does NOT gate the case (the crux fence line).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $surgical_case_id
 * @property string $patient_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient|null $patient
 * @property-read SurgicalCase|null $surgicalCase
 * @property-read Collection<int, SurgicalChecklistItem> $items
 */
class SurgicalChecklist extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'surgical_case_id',
        'patient_id',
    ];

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SurgicalChecklistItem::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
