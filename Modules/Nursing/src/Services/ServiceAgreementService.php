<?php

namespace Modules\Nursing\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Nursing\Events\ServiceAgreementChanged;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Service;

class ServiceAgreementService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $services
     */
    public function create(array $attributes, array $services, User $actor): ServiceAgreement
    {
        $this->authorizeManage($actor);
        $this->validateAgreement($attributes);

        if ($services === []) {
            throw new InvalidArgumentException('At least one agreement service is required.');
        }

        foreach ($services as $service) {
            $this->validateAgreementService($service);
        }

        return DB::transaction(function () use ($attributes, $services, $actor): ServiceAgreement {
            $agreement = ServiceAgreement::query()->create([
                'patient_id' => (string) $attributes['patient_id'],
                'branch_id' => (string) $attributes['branch_id'],
                'funding_type' => (string) $attributes['funding_type'],
                'payer_name' => $attributes['payer_name'] ?? null,
                'authorization_ref' => $attributes['authorization_ref'] ?? null,
                'authorized_hours_per_week' => $attributes['authorized_hours_per_week'] ?? null,
                'starts_on' => $attributes['starts_on'],
                'ends_on' => $attributes['ends_on'] ?? null,
                'status' => $attributes['status'] ?? ServiceAgreement::STATUS_DRAFT,
                'created_by' => $actor->id,
            ]);

            foreach ($services as $service) {
                AgreementService::query()->create([
                    'service_agreement_id' => $agreement->id,
                    'service_id' => (string) $service['service_id'],
                    'planned_frequency_text' => (string) $service['planned_frequency_text'],
                    'required_qualification' => $service['required_qualification'] ?? null,
                    'required_competencies' => $service['required_competencies'] ?? null,
                    'duration_minutes' => (int) $service['duration_minutes'],
                ]);
            }

            $agreement = $agreement->refresh()->load('agreementServices');
            $this->audit($agreement, $actor, 'service_agreement.created');

            return $agreement;
        });
    }

    public function read(ServiceAgreement $agreement, User $actor): ServiceAgreement
    {
        $this->authorizeManage($actor);
        $this->assertSameTenant($agreement, 'service_agreement_id');

        $agreement->auditRead(['surface' => 'nursing.service_agreement']);

        return $agreement->load('agreementServices');
    }

    public function activate(ServiceAgreement $agreement, User $actor): ServiceAgreement
    {
        return $this->transition($agreement, ServiceAgreement::STATUS_ACTIVE, $actor);
    }

    public function suspend(ServiceAgreement $agreement, User $actor): ServiceAgreement
    {
        return $this->transition($agreement, ServiceAgreement::STATUS_SUSPENDED, $actor);
    }

    public function end(ServiceAgreement $agreement, User $actor): ServiceAgreement
    {
        return $this->transition($agreement, ServiceAgreement::STATUS_ENDED, $actor);
    }

    /**
     * @return Collection<int, ServiceAgreement>
     */
    public function listForPatient(Patient $patient, User $actor): Collection
    {
        $this->authorizeManage($actor);
        $this->assertSameTenant($patient, 'patient_id');

        return ServiceAgreement::query()
            ->where('patient_id', $patient->id)
            ->with('agreementServices')
            ->orderByDesc('starts_on')
            ->get();
    }

    private function transition(ServiceAgreement $agreement, string $toStatus, User $actor): ServiceAgreement
    {
        $this->authorizeManage($actor);
        $this->assertSameTenant($agreement, 'service_agreement_id');

        $fromStatus = $agreement->status;

        if (! $this->canTransition($fromStatus, $toStatus)) {
            throw new InvalidArgumentException("Illegal service agreement transition [{$fromStatus} -> {$toStatus}].");
        }

        $agreement->forceFill(['status' => $toStatus])->save();

        $this->audit($agreement->refresh(), $actor, 'service_agreement.'.$toStatus, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ]);

        return $agreement;
    }

    private function canTransition(string $fromStatus, string $toStatus): bool
    {
        return match ($fromStatus) {
            ServiceAgreement::STATUS_DRAFT => in_array(
                $toStatus,
                [ServiceAgreement::STATUS_ACTIVE, ServiceAgreement::STATUS_ENDED],
                true,
            ),
            ServiceAgreement::STATUS_ACTIVE => in_array(
                $toStatus,
                [ServiceAgreement::STATUS_SUSPENDED, ServiceAgreement::STATUS_ENDED],
                true,
            ),
            ServiceAgreement::STATUS_SUSPENDED => in_array(
                $toStatus,
                [ServiceAgreement::STATUS_ACTIVE, ServiceAgreement::STATUS_ENDED],
                true,
            ),
            default => false,
        };
    }

    private function authorizeManage(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('agreement.manage')) {
            throw new AuthorizationException('This user cannot manage nursing service agreements.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validateAgreement(array $attributes): void
    {
        foreach (['patient_id', 'branch_id', 'funding_type', 'starts_on'] as $required) {
            if (! array_key_exists($required, $attributes) || trim((string) $attributes[$required]) === '') {
                throw new InvalidArgumentException("Service agreement {$required} is required.");
            }
        }

        if (! in_array((string) $attributes['funding_type'], [
            ServiceAgreement::FUNDING_SELF_PAY,
            ServiceAgreement::FUNDING_PRIVATE_INSURANCE,
            ServiceAgreement::FUNDING_OTHER,
        ], true)) {
            throw new InvalidArgumentException('Unsupported funding type.');
        }

        if (
            array_key_exists('status', $attributes)
            && ! in_array((string) $attributes['status'], [
                ServiceAgreement::STATUS_DRAFT,
                ServiceAgreement::STATUS_ACTIVE,
                ServiceAgreement::STATUS_SUSPENDED,
                ServiceAgreement::STATUS_ENDED,
            ], true)
        ) {
            throw new InvalidArgumentException('Unsupported service agreement status.');
        }

        if (
            array_key_exists('authorized_hours_per_week', $attributes)
            && $attributes['authorized_hours_per_week'] !== null
            && (float) $attributes['authorized_hours_per_week'] < 0
        ) {
            throw new InvalidArgumentException('Authorized hours must be zero or greater.');
        }

        if (! Patient::query()->whereKey($attributes['patient_id'])->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $attributes['patient_id']);
        }

        if (! Branch::query()->whereKey($attributes['branch_id'])->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $attributes['branch_id']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function validateAgreementService(array $attributes): void
    {
        foreach (['service_id', 'planned_frequency_text', 'duration_minutes'] as $required) {
            if (! array_key_exists($required, $attributes) || trim((string) $attributes[$required]) === '') {
                throw new InvalidArgumentException("Agreement service {$required} is required.");
            }
        }

        if ((int) $attributes['duration_minutes'] <= 0) {
            throw new InvalidArgumentException('Agreement service duration must be greater than zero.');
        }

        if (! Service::query()->whereKey($attributes['service_id'])->exists()) {
            throw CrossTenantReferenceException::forAttribute('service_id', (string) $attributes['service_id']);
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(ServiceAgreement $agreement, User $actor, string $action, array $context = []): void
    {
        Event::dispatch(new ServiceAgreementChanged(
            $agreement,
            $actor,
            $action,
            [
                'patient_id' => $agreement->patient_id,
                'branch_id' => $agreement->branch_id,
                'funding_type' => $agreement->funding_type,
                'status' => $agreement->status,
                ...$context,
            ],
        ));
    }
}
