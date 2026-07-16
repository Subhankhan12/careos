<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A recurring appointment series: the RRULE + booking template. Individual
 * occurrences are ordinary appointments linked by series_id.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $service_id
 * @property string $branch_id
 * @property list<string> $resource_ids
 * @property string $rrule
 * @property string $timezone
 * @property string $start_time
 * @property int $duration_minutes
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string $status
 * @property string|null $created_by
 */
class AppointmentSeries extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'service_id',
        'branch_id',
        'resource_ids',
        'rrule',
        'timezone',
        'start_time',
        'duration_minutes',
        'starts_on',
        'ends_on',
        'status',
        'created_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resource_ids' => 'array',
            'duration_minutes' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'series_id');
    }
}
