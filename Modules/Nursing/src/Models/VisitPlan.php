<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $service_agreement_id
 * @property string $agreement_service_id
 * @property string $rrule
 * @property string $timezone
 * @property string $window_start_time
 * @property string $window_end_time
 * @property int $duration_minutes
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property bool $active
 */
class VisitPlan extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'service_agreement_id',
        'agreement_service_id',
        'rrule',
        'timezone',
        'window_start_time',
        'window_end_time',
        'duration_minutes',
        'starts_on',
        'ends_on',
        'active',
    ];

    protected $attributes = [
        'active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(fn (VisitPlan $visitPlan) => $visitPlan->assertValidPlan());
        static::updating(function (VisitPlan $visitPlan): void {
            if ($visitPlan->isDirty([
                'service_agreement_id',
                'agreement_service_id',
                'timezone',
                'window_start_time',
                'window_end_time',
                'duration_minutes',
            ])) {
                $visitPlan->assertValidPlan();
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
            'duration_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ServiceAgreement::class, 'service_agreement_id');
    }

    public function agreementService(): BelongsTo
    {
        return $this->belongsTo(AgreementService::class, 'agreement_service_id');
    }

    public function plannedVisits(): HasMany
    {
        return $this->hasMany(PlannedVisit::class);
    }

    private function assertValidPlan(): void
    {
        $agreement = ServiceAgreement::query()->whereKey($this->service_agreement_id)->first();

        if ($agreement === null) {
            throw CrossTenantReferenceException::forAttribute(
                'service_agreement_id',
                (string) $this->service_agreement_id,
            );
        }

        $agreementService = AgreementService::query()->whereKey($this->agreement_service_id)->first();

        if ($agreementService === null) {
            throw CrossTenantReferenceException::forAttribute(
                'agreement_service_id',
                (string) $this->agreement_service_id,
            );
        }

        if ($agreementService->service_agreement_id !== $agreement->id) {
            throw CrossTenantReferenceException::forAttribute(
                'agreement_service_id',
                (string) $this->agreement_service_id,
            );
        }

        if (! in_array($this->timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Visit plan timezone is not supported.');
        }

        if ((int) $this->duration_minutes <= 0) {
            throw new InvalidArgumentException('Visit plan duration must be greater than zero.');
        }

        if ((string) $this->window_start_time >= (string) $this->window_end_time) {
            throw new InvalidArgumentException('Visit plan window end must be after window start.');
        }
    }
}
