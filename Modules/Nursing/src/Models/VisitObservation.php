<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Scheduling\Models\Resource;

/**
 * Nurse-authored observation/note captured offline and synced later.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $visit_id
 * @property string $patient_id
 * @property string $nurse_resource_id
 * @property string $client_action_uuid
 * @property string $note_text
 * @property bool $flagged
 * @property string|null $flag_reason
 * @property Carbon $device_timestamp
 */
class VisitObservation extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'patient_id',
        'nurse_resource_id',
        'client_action_uuid',
        'note_text',
        'flagged',
        'flag_reason',
        'device_timestamp',
    ];

    protected static function booted(): void
    {
        static::creating(fn (VisitObservation $observation) => $observation->assertTenantReferences());
        static::updating(function (VisitObservation $observation): void {
            if ($observation->isDirty(['visit_id', 'patient_id', 'nurse_resource_id'])) {
                $observation->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'flagged' => 'boolean',
            'device_timestamp' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function nurseResource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'nurse_resource_id');
    }

    private function assertTenantReferences(): void
    {
        $visit = Visit::query()->whereKey($this->visit_id)->first();
        if ($visit === null) {
            throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
        }

        if ($visit->patient_id !== $this->patient_id) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! Resource::query()->whereKey($this->nurse_resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('nurse_resource_id', (string) $this->nurse_resource_id);
        }
    }
}
