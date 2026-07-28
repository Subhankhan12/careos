<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Surgery\Exceptions\SurgicalInventoryException;

/**
 * An IMPLANT PLACEMENT (SURGERY.G4) — the net-new lot/serial/UDI TRACEABILITY record: WHICH implant (lot /
 * serial / UDI) went into WHICH patient during a case, so a placed implant is traceable for device recalls (a
 * regulatory / patient-safety requirement). Indexed by lot + UDI for the recall lookup. APPEND-ONLY (model
 * guards + DB triggers); patient-scoped read-logged ({@see LogsReads}).
 *
 * ELECTRIC FENCE: RECORD-KEEPING (traceability). The system records the identifiers; it does NOT verify,
 * grade, or compute a device-safety / recall verdict — no verdict / safe / recall_status column.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $surgical_case_id
 * @property string $patient_id
 * @property string $surgical_item_id
 * @property string|null $case_item_usage_id
 * @property string $lot_number
 * @property string|null $serial_number
 * @property string|null $udi
 * @property int $placed_by
 * @property Carbon $placed_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient|null $patient
 * @property-read SurgicalItem|null $surgicalItem
 */
class ImplantPlacement extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'surgical_case_id',
        'patient_id',
        'surgical_item_id',
        'case_item_usage_id',
        'lot_number',
        'serial_number',
        'udi',
        'placed_by',
        'placed_at',
        'note',
    ];

    protected static function booted(): void
    {
        // Append-only: the placement record is preserved (belt for the DB triggers).
        static::updating(fn () => throw SurgicalInventoryException::appendOnly());
        static::deleting(fn () => throw SurgicalInventoryException::appendOnly());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function surgicalItem(): BelongsTo
    {
        return $this->belongsTo(SurgicalItem::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
