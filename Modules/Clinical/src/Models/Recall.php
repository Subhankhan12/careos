<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $rule_id
 * @property Carbon $due_on
 * @property string $status
 */
class Recall extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_DUE = 'due';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DISMISSED = 'dismissed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'rule_id',
        'due_on',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_DUE,
    ];

    protected static function booted(): void
    {
        static::creating(fn (Recall $recall) => $recall->assertTenantReferences());
        static::updating(function (Recall $recall): void {
            if ($recall->isDirty(['patient_id', 'rule_id'])) {
                $recall->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RecallRule::class, 'rule_id');
    }

    protected function auditResourceType(): string
    {
        return 'recall';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    private function assertTenantReferences(): void
    {
        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! RecallRule::query()->whereKey($this->rule_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('rule_id', (string) $this->rule_id);
        }
    }
}
