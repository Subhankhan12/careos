<?php

namespace Modules\Comms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Telehealth session METADATA only (D-G1): room reference, parties, and
 * timestamps. No media, no recording, no transcript — the room is NOT the
 * clinical record (D-G3); documentation happens in a SOAP note like any other
 * encounter. ELECTRIC FENCE: no AI listens to the call, ever.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $appointment_id
 * @property string|null $encounter_id
 * @property string $patient_id
 * @property string $practitioner_id
 * @property string $provider
 * @property string $room_reference
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 */
class TelehealthSession extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_CREATED = 'created';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'appointment_id',
        'encounter_id',
        'patient_id',
        'practitioner_id',
        'provider',
        'room_reference',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_CREATED,
    ];

    protected static function booted(): void
    {
        static::creating(function (TelehealthSession $session): void {
            if (! Patient::query()->whereKey($session->patient_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('patient_id', (string) $session->patient_id);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TelehealthParticipant::class, 'session_id');
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
