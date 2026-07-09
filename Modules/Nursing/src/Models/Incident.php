<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Scheduling\Models\Resource;

/**
 * Factual incident report. Severity is selected by the reporter; CareOS never
 * assesses severity, advises action, or escalates based on clinical judgment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $visit_id
 * @property string|null $patient_id
 * @property string $reported_by_resource_id
 * @property Carbon $occurred_at
 * @property string $category
 * @property string $description
 * @property string $severity
 * @property string $status
 */
class Incident extends Model
{
    use BelongsToTenant, HasUlids;

    public const CATEGORY_FALL = 'fall';

    public const CATEGORY_MEDICATION = 'medication';

    public const CATEGORY_BEHAVIOUR = 'behaviour';

    public const CATEGORY_SAFETY = 'safety';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_FALL,
        self::CATEGORY_MEDICATION,
        self::CATEGORY_BEHAVIOUR,
        self::CATEGORY_SAFETY,
        self::CATEGORY_OTHER,
    ];

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_INVESTIGATING,
        self::STATUS_CLOSED,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'patient_id',
        'reported_by_resource_id',
        'occurred_at',
        'category',
        'description',
        'severity',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected static function booted(): void
    {
        static::saving(function (Incident $incident): void {
            $incident->assertTenantReferences();
            $incident->assertEnums();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
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

    public function reporterResource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'reported_by_resource_id');
    }

    private function assertTenantReferences(): void
    {
        if ($this->visit_id !== null) {
            $visit = Visit::query()->whereKey($this->visit_id)->first();
            if ($visit === null) {
                throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
            }

            if ($this->patient_id !== null && $visit->patient_id !== $this->patient_id) {
                throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
            }
        }

        if ($this->patient_id !== null && ! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! Resource::query()->whereKey($this->reported_by_resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('reported_by_resource_id', (string) $this->reported_by_resource_id);
        }
    }

    private function assertEnums(): void
    {
        if (! in_array($this->category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Incident category is not valid.');
        }

        if (! in_array($this->severity, self::SEVERITIES, true)) {
            throw new InvalidArgumentException('Incident severity is not valid.');
        }

        if (! in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Incident status is not valid.');
        }
    }
}
