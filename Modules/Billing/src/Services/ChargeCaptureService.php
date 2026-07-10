<?php

namespace Modules\Billing\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\TariffItem;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Nursing\Models\Visit;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class ChargeCaptureService
{
    public function __construct(
        private readonly TariffResolver $tariffResolver,
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
    ) {}

    public function captureFromEncounter(Encounter $encounter, string $code, int $quantity, User $actor): Charge
    {
        $this->assertSameTenant($encounter, 'encounter_id');

        return $this->capture(
            patientId: $encounter->patient_id,
            branchId: $encounter->branch_id,
            serviceDate: $encounter->started_at,
            code: $code,
            quantity: $quantity,
            actor: $actor,
            encounter: $encounter,
        );
    }

    public function captureFromVisit(Visit $visit, string $code, int $quantity, User $actor): Charge
    {
        $this->assertSameTenant($visit, 'visit_id');

        return $this->capture(
            patientId: $visit->patient_id,
            branchId: $visit->branch_id,
            serviceDate: $visit->checked_in_at ?? $visit->scheduled_start_at,
            code: $code,
            quantity: $quantity,
            actor: $actor,
            visit: $visit,
        );
    }

    public function captureManual(
        Patient $patient,
        Branch $branch,
        CarbonInterface|string $serviceDate,
        string $code,
        int $quantity,
        User $actor,
    ): Charge {
        $this->assertSameTenant($patient, 'patient_id');
        $this->assertSameTenant($branch, 'branch_id');

        return $this->capture(
            patientId: $patient->id,
            branchId: $branch->id,
            serviceDate: $serviceDate,
            code: $code,
            quantity: $quantity,
            actor: $actor,
        );
    }

    public function cancel(Charge $charge, User $actor, string $reason): Charge
    {
        $this->authorize($actor);
        $this->assertSameTenant($charge, 'charge_id');

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Cancelling a charge requires a reason.');
        }

        if (! in_array($charge->status, [Charge::STATUS_DRAFT, Charge::STATUS_VALIDATED], true)) {
            throw new InvalidArgumentException('Only draft or validated charges can be cancelled directly.');
        }

        $charge->forceFill([
            'status' => Charge::STATUS_CANCELLED,
            'cancelled_reason' => $reason,
        ])->save();

        $this->auditCharge('charge.cancelled', $charge->refresh(), $actor, ['reason' => $reason]);

        return $charge;
    }

    private function capture(
        string $patientId,
        string $branchId,
        CarbonInterface|string $serviceDate,
        string $code,
        int $quantity,
        User $actor,
        ?Encounter $encounter = null,
        ?Visit $visit = null,
    ): Charge {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertQuantity($quantity);

        $tenant = $this->currentTenant();
        $date = $this->serviceDate($serviceDate);
        $tariffItem = $this->tariffResolver->resolve($tenant, $code, $date);

        $this->assertDocumentation($tariffItem, $encounter, $visit);

        $charge = DB::transaction(function () use ($patientId, $branchId, $date, $tariffItem, $quantity, $actor, $encounter, $visit): Charge {
            return Charge::query()->create([
                'patient_id' => $patientId,
                'encounter_id' => $encounter?->id,
                'visit_id' => $visit?->id,
                'branch_id' => $branchId,
                'service_date' => $date,
                'tariff_catalog_id' => $tariffItem->tariff_catalog_id,
                'tariff_item_id' => $tariffItem->id,
                'code' => $tariffItem->code,
                'description' => $tariffItem->description,
                'unit_price_minor' => $tariffItem->unit_price_minor,
                'vat_rate_bp' => $tariffItem->vat_rate_bp,
                'quantity' => $quantity,
                'line_total_minor' => $quantity * $tariffItem->unit_price_minor,
                'status' => Charge::STATUS_DRAFT,
                'created_by' => $actor->id,
            ]);
        });

        $this->auditCharge('charge.captured', $charge, $actor);

        return $charge;
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('billing.manage')) {
            throw new AuthorizationException('This user cannot manage billing.');
        }
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Charge quantity must be at least 1.');
        }
    }

    private function currentTenant(): Tenant
    {
        $tenant = $this->tenantContext->current();

        if (! $tenant instanceof Tenant) {
            throw TenantContextMissingException::forQuery(new Charge);
        }

        return $tenant;
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('created_by', (string) $actor->id);
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    private function serviceDate(CarbonInterface|string $serviceDate): string
    {
        return $serviceDate instanceof CarbonInterface
            ? Carbon::instance($serviceDate)->toDateString()
            : Carbon::parse($serviceDate)->toDateString();
    }

    private function assertDocumentation(TariffItem $tariffItem, ?Encounter $encounter, ?Visit $visit): void
    {
        if (! $tariffItem->requires_service_documentation) {
            return;
        }

        if ($encounter instanceof Encounter && $this->encounterHasSignedNote($encounter)) {
            return;
        }

        if ($visit instanceof Visit && $visit->status === Visit::STATUS_COMPLETED) {
            return;
        }

        throw new InvalidArgumentException('This tariff item requires service documentation: a signed encounter note or completed visit.');
    }

    private function encounterHasSignedNote(Encounter $encounter): bool
    {
        return ClinicalNote::query()
            ->where('encounter_id', $encounter->id)
            ->where('status', ClinicalNote::STATUS_SIGNED)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditCharge(string $action, Charge $charge, User $actor, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'patient_id' => $charge->patient_id,
            'resource_type' => 'charge',
            'resource_id' => $charge->id,
            'context' => [
                'code' => $charge->code,
                'service_date' => $charge->service_date->toDateString(),
                'quantity' => $charge->quantity,
                'unit_price_minor' => $charge->unit_price_minor,
                'vat_rate_bp' => $charge->vat_rate_bp,
                'line_total_minor' => $charge->line_total_minor,
                'status' => $charge->status,
                'encounter_id' => $charge->encounter_id,
                'visit_id' => $charge->visit_id,
                ...$context,
            ],
        ]);
    }
}
