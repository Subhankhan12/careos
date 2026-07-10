<?php

namespace Modules\Comms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Audit\Concerns\LogsReads;
use Modules\Clinical\Models\Encounter;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Tenant-owned secure messaging thread: patient <-> care team, or internal
 * staff-only. Internal threads never carry a patient participant so internal
 * clinical discussion stays internal.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subject
 * @property string $type
 * @property string|null $patient_id
 * @property string|null $encounter_id
 * @property string $status
 * @property int $created_by
 * @property int|null $assigned_to
 * @property Carbon|null $last_message_at
 */
class Thread extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const TYPE_PATIENT = 'patient';

    public const TYPE_INTERNAL = 'internal';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'subject',
        'type',
        'patient_id',
        'encounter_id',
        'status',
        'created_by',
        'assigned_to',
        'last_message_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected static function booted(): void
    {
        static::creating(fn (Thread $thread) => $thread->assertConsistent());
        static::updating(function (Thread $thread): void {
            if ($thread->isDirty(['type', 'patient_id', 'encounter_id'])) {
                $thread->assertConsistent();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ThreadParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isPatientThread(): bool
    {
        return $this->type === self::TYPE_PATIENT;
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    private function assertConsistent(): void
    {
        if (! in_array($this->type, [self::TYPE_PATIENT, self::TYPE_INTERNAL], true)) {
            throw new InvalidArgumentException('Unsupported thread type.');
        }

        if ($this->type === self::TYPE_PATIENT && $this->patient_id === null) {
            throw new InvalidArgumentException('A patient thread requires a patient.');
        }

        if ($this->type === self::TYPE_INTERNAL && $this->patient_id !== null) {
            throw new InvalidArgumentException('An internal thread cannot reference a patient.');
        }

        if ($this->patient_id !== null && ! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if ($this->encounter_id !== null && ! Encounter::query()->whereKey($this->encounter_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $this->encounter_id);
        }
    }
}
