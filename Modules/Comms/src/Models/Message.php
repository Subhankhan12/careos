<?php

namespace Modules\Comms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;

/**
 * Tenant-owned APPEND-ONLY thread message. What was communicated to a patient
 * (or internally about care) is evidence: it is never edited or deleted.
 * Corrections are new messages. Guarded at model and DB-trigger levels.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $thread_id
 * @property string $author_type
 * @property int|null $author_staff_user_id
 * @property string|null $author_patient_id
 * @property string $body
 * @property bool $ai_assisted
 * @property Carbon $sent_at
 */
class Message extends Model
{
    use BelongsToTenant, HasUlids;

    public const AUTHOR_STAFF = 'staff';

    public const AUTHOR_PATIENT = 'patient';

    public const AUTHOR_SYSTEM = 'system';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'thread_id',
        'author_type',
        'author_staff_user_id',
        'author_patient_id',
        'body',
        'ai_assisted',
        'sent_at',
    ];

    protected $attributes = [
        'ai_assisted' => false,
    ];

    protected static function booted(): void
    {
        static::creating(fn (Message $message) => $message->assertConsistent());
        static::updating(function (): void {
            throw new LogicException('messages are append-only: corrections are new messages, never edits.');
        });
        static::deleting(function (): void {
            throw new LogicException('messages are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_assisted' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function staffAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_staff_user_id');
    }

    public function patientAuthor(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'author_patient_id');
    }

    private function assertConsistent(): void
    {
        if (! Thread::query()->whereKey($this->thread_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('thread_id', (string) $this->thread_id);
        }

        $authorShapeValid = match ($this->author_type) {
            self::AUTHOR_STAFF => $this->author_staff_user_id !== null && $this->author_patient_id === null,
            self::AUTHOR_PATIENT => $this->author_patient_id !== null && $this->author_staff_user_id === null,
            self::AUTHOR_SYSTEM => $this->author_staff_user_id === null && $this->author_patient_id === null,
            default => throw new InvalidArgumentException('Unsupported message author type.'),
        };

        if (! $authorShapeValid) {
            throw new InvalidArgumentException('Message author references must match the author type.');
        }

        if (trim($this->body) === '') {
            throw new InvalidArgumentException('Messages require a body.');
        }
    }
}
