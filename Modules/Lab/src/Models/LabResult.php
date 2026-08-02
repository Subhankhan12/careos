<?php

namespace Modules\Lab\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clinical\Models\OrderResult;
use Modules\Lab\Exceptions\LabResultException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The thin lab-RESULT overlay (LAB.G4) linking the EXISTING Clinical {@see OrderResult} to the LAB.G3
 * {@see Specimen} that produced it — a lab result IS a Clinical `OrderResult` (REUSED via
 * `OrderService::recordResult`: append-only, raw, `source=manual`; its lifecycle UNTOUCHED). This overlay adds
 * ONLY the one lab fact Clinical's `OrderResult` doesn't carry: WHICH specimen produced the result. It is NOT a
 * parallel result entity — it holds NO value (the raw value lives on the reused `OrderResult`). **APPEND-ONLY**
 * (model guards + `SIGNAL '45000'` DB triggers, the `OrderResult` recipe): a correction is a NEW `OrderResult`,
 * never an edit. Patient-scoped.
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE3-LAB-MAP.md §4 — the sharpest in lab): there is deliberately NO
 * abnormal/high/low/critical/flag/grade/interpretation/delta column here (nor on `OrderResult`). The reference
 * range is DISPLAYED reference data (read from the LAB.G1 {@see LabTest} catalog at presentation) the clinician
 * reads beside the raw value — the system computes NO verdict, does NOT flag, does NOT delta-check.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $order_result_id
 * @property string $specimen_id
 * @property-read OrderResult|null $orderResult
 * @property-read Specimen|null $specimen
 */
class LabResult extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'order_result_id',
        'specimen_id',
    ];

    protected static function booted(): void
    {
        // Append-only: a lab-result link is an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw LabResultException::appendOnly());
        static::deleting(fn () => throw LabResultException::appendOnly());
    }

    public function orderResult(): BelongsTo
    {
        return $this->belongsTo(OrderResult::class);
    }

    public function specimen(): BelongsTo
    {
        return $this->belongsTo(Specimen::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
