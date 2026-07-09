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
use Modules\Platform\Models\Branch;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string|null $encounter_id
 * @property string $direction
 * @property string|null $to_provider_name
 * @property string|null $from_provider_name
 * @property string|null $to_branch_id
 * @property string|null $specialty
 * @property string $reason
 * @property string $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $responded_at
 * @property string|null $notes
 */
class Referral extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_COMPLETED = 'completed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'encounter_id',
        'direction',
        'to_provider_name',
        'from_provider_name',
        'to_branch_id',
        'specialty',
        'reason',
        'status',
        'sent_at',
        'responded_at',
        'notes',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::creating(fn (Referral $referral) => $referral->assertTenantReferences());
        static::updating(function (Referral $referral): void {
            if ($referral->isDirty(['patient_id', 'encounter_id', 'to_branch_id'])) {
                $referral->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
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

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    protected function auditResourceType(): string
    {
        return 'referral';
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

        if ($this->encounter_id !== null) {
            $encounter = Encounter::query()->whereKey($this->encounter_id)->first();
            if ($encounter === null || $encounter->patient_id !== $this->patient_id) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $this->encounter_id);
            }
        }

        if ($this->to_branch_id !== null && ! Branch::query()->whereKey($this->to_branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('to_branch_id', (string) $this->to_branch_id);
        }
    }
}
