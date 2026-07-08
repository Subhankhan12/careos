<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Tenant-owned resource consumption row for an appointment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $appointment_id
 * @property string $resource_id
 */
class AppointmentResource extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'appointment_id',
        'resource_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (AppointmentResource $appointmentResource): void {
            $appointmentResource->assertTenantReferences();
        });

        static::updating(function (AppointmentResource $appointmentResource): void {
            if ($appointmentResource->isDirty(['appointment_id', 'resource_id'])) {
                $appointmentResource->assertTenantReferences();
            }
        });
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    private function assertTenantReferences(): void
    {
        if (! Appointment::whereKey($this->appointment_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('appointment_id', (string) $this->appointment_id);
        }

        if (! Resource::whereKey($this->resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('resource_id', (string) $this->resource_id);
        }
    }
}
