<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $branch_id
 * @property string $funding_type
 * @property string|null $payer_name
 * @property string|null $authorization_ref
 * @property string|null $authorized_hours_per_week
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string $status
 * @property int $created_by
 */
class ServiceAgreement extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const FUNDING_SELF_PAY = 'self_pay';

    public const FUNDING_PRIVATE_INSURANCE = 'private_insurance';

    public const FUNDING_OTHER = 'other';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ENDED = 'ended';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'branch_id',
        'funding_type',
        'payer_name',
        'authorization_ref',
        'authorized_hours_per_week',
        'starts_on',
        'ends_on',
        'status',
        'created_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::creating(fn (ServiceAgreement $agreement) => $agreement->assertTenantReferences());
        static::updating(function (ServiceAgreement $agreement): void {
            if ($agreement->isDirty(['patient_id', 'branch_id', 'created_by'])) {
                $agreement->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'authorized_hours_per_week' => 'decimal:2',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function agreementServices(): HasMany
    {
        return $this->hasMany(AgreementService::class);
    }

    protected function auditResourceType(): string
    {
        return 'service_agreement';
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

        if (! Branch::query()->whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }

        $currentTenantId = app(TenantContext::class)->id();

        if (! User::query()
            ->whereKey($this->created_by)
            ->where('tenant_id', $currentTenantId)
            ->exists()) {
            throw CrossTenantReferenceException::forAttribute('created_by', (string) $this->created_by);
        }
    }
}
