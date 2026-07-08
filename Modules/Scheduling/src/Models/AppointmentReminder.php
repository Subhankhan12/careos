<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Tenant-owned reminder delivery ledger for an appointment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $appointment_id
 * @property string $type
 * @property string $channel
 * @property string $status
 * @property Carbon $scheduled_for
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 */
class AppointmentReminder extends Model
{
    use BelongsToTenant, HasUlids;

    public const CHANNEL_EMAIL = 'email';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'appointment_id',
        'type',
        'channel',
        'status',
        'scheduled_for',
        'sent_at',
        'failed_at',
        'failure_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected static function booted(): void
    {
        static::creating(function (AppointmentReminder $reminder): void {
            $reminder->assertAppointmentWithinTenant();
        });

        static::updating(function (AppointmentReminder $reminder): void {
            if ($reminder->isDirty('appointment_id')) {
                $reminder->assertAppointmentWithinTenant();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    private function assertAppointmentWithinTenant(): void
    {
        if (! Appointment::whereKey($this->appointment_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('appointment_id', (string) $this->appointment_id);
        }
    }
}
