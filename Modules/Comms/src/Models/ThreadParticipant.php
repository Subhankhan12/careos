<?php

namespace Modules\Comms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Tenant-owned thread membership: exactly one of staff_user_id / patient_id.
 * A patient may only ever join a PATIENT thread, and only their own — internal
 * threads never carry a patient participant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $thread_id
 * @property string $participant_type
 * @property int|null $staff_user_id
 * @property string|null $patient_id
 * @property Carbon $added_at
 * @property Carbon|null $removed_at
 */
class ThreadParticipant extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_STAFF = 'staff';

    public const TYPE_PATIENT = 'patient';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'thread_id',
        'participant_type',
        'staff_user_id',
        'patient_id',
        'added_at',
        'removed_at',
        'last_read_message_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (ThreadParticipant $participant) => $participant->assertConsistent());
        static::updating(function (ThreadParticipant $participant): void {
            if ($participant->isDirty(['thread_id', 'participant_type', 'staff_user_id', 'patient_id'])) {
                $participant->assertConsistent();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }

    private function assertConsistent(): void
    {
        if (($this->staff_user_id === null) === ($this->patient_id === null)) {
            throw new InvalidArgumentException('A thread participant is exactly one of staff or patient.');
        }

        $thread = Thread::query()->whereKey($this->thread_id)->first();
        if (! $thread instanceof Thread) {
            throw CrossTenantReferenceException::forAttribute('thread_id', (string) $this->thread_id);
        }

        if ($this->patient_id !== null) {
            // A patient can NEVER be added to an internal thread — this is how
            // internal clinical discussion stays internal.
            if (! $thread->isPatientThread()) {
                throw new InvalidArgumentException('A patient can never be added to an internal thread.');
            }

            if ($thread->patient_id !== $this->patient_id) {
                throw new InvalidArgumentException('Only the thread patient may participate in a patient thread.');
            }

            if (! Patient::query()->whereKey($this->patient_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
            }

            if ($this->participant_type !== self::TYPE_PATIENT) {
                throw new InvalidArgumentException('Patient participants must carry the patient participant type.');
            }
        }

        if ($this->staff_user_id !== null) {
            $tenantId = app(TenantContext::class)->id();

            if (! User::query()->whereKey($this->staff_user_id)->where('tenant_id', $tenantId)->exists()) {
                throw CrossTenantReferenceException::forAttribute('staff_user_id', (string) $this->staff_user_id);
            }

            if ($this->participant_type !== self::TYPE_STAFF) {
                throw new InvalidArgumentException('Staff participants must carry the staff participant type.');
            }
        }
    }
}
