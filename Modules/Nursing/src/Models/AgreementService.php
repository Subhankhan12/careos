<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Scheduling\Models\Service;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $service_agreement_id
 * @property string $service_id
 * @property string $planned_frequency_text
 * @property string|null $required_qualification
 * @property int $duration_minutes
 */
class AgreementService extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'service_agreement_id',
        'service_id',
        'planned_frequency_text',
        'required_qualification',
        'duration_minutes',
    ];

    protected static function booted(): void
    {
        static::creating(fn (AgreementService $agreementService) => $agreementService->assertTenantReferences());
        static::updating(function (AgreementService $agreementService): void {
            if ($agreementService->isDirty(['service_agreement_id', 'service_id'])) {
                $agreementService->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ServiceAgreement::class, 'service_agreement_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    private function assertTenantReferences(): void
    {
        if (! ServiceAgreement::query()->whereKey($this->service_agreement_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute(
                'service_agreement_id',
                (string) $this->service_agreement_id,
            );
        }

        if (! Service::query()->whereKey($this->service_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('service_id', (string) $this->service_id);
        }
    }
}
