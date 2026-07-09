<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Clinical\Events\ClinicalRecordChanged;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Referral;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class ReferralService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Patient $patient, User $actor, array $data, ?Encounter $encounter = null): Referral
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($patient, 'patient_id');
        $this->assertEncounter($patient, $encounter);

        $referral = Referral::query()->create([
            'patient_id' => $patient->id,
            'encounter_id' => $encounter?->id,
            'direction' => $data['direction'] ?? Referral::DIRECTION_OUTBOUND,
            'to_provider_name' => $data['to_provider_name'] ?? null,
            'from_provider_name' => $data['from_provider_name'] ?? null,
            'to_branch_id' => $data['to_branch_id'] ?? null,
            'specialty' => $data['specialty'] ?? null,
            'reason' => (string) $data['reason'],
            'status' => $data['status'] ?? Referral::STATUS_DRAFT,
            'sent_at' => $data['sent_at'] ?? null,
            'responded_at' => $data['responded_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit($referral, $actor, 'referral.created');

        return $referral;
    }

    public function send(Referral $referral, User $actor): Referral
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($referral, 'referral_id');

        if ($referral->status !== Referral::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft referrals can be sent.');
        }

        $referral->forceFill([
            'status' => Referral::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        $this->audit($referral, $actor, 'referral.sent');

        return $referral->refresh();
    }

    public function respond(Referral $referral, string $status, User $actor, ?string $notes = null): Referral
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($referral, 'referral_id');

        if ($referral->status !== Referral::STATUS_SENT) {
            throw new InvalidArgumentException('Only sent referrals can be responded to.');
        }

        if (! in_array($status, [Referral::STATUS_ACCEPTED, Referral::STATUS_DECLINED], true)) {
            throw new InvalidArgumentException('Referral responses must be accepted or declined.');
        }

        $referral->forceFill([
            'status' => $status,
            'responded_at' => now(),
            'notes' => $notes ?? $referral->notes,
        ])->save();

        $this->audit($referral, $actor, 'referral.responded', ['response_status' => $status]);

        return $referral->refresh();
    }

    public function complete(Referral $referral, User $actor): Referral
    {
        $this->authorizeWrite($actor);
        $this->assertSameTenant($referral, 'referral_id');

        if ($referral->status !== Referral::STATUS_ACCEPTED) {
            throw new InvalidArgumentException('Only accepted referrals can be completed.');
        }

        $referral->forceFill(['status' => Referral::STATUS_COMPLETED])->save();

        $this->audit($referral, $actor, 'referral.completed');

        return $referral->refresh();
    }

    private function authorizeWrite(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('note.write')) {
            throw new AuthorizationException('This user cannot manage referrals.');
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    private function assertEncounter(Patient $patient, ?Encounter $encounter): void
    {
        if ($encounter === null) {
            return;
        }

        $this->assertSameTenant($encounter, 'encounter_id');

        if ($encounter->patient_id !== $patient->id) {
            throw CrossTenantReferenceException::forAttribute('encounter_id', $encounter->id);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(Referral $referral, User $actor, string $action, array $context = []): void
    {
        Event::dispatch(new ClinicalRecordChanged(
            $action,
            'referral',
            $referral->id,
            $referral->patient_id,
            $actor,
            [
                'direction' => $referral->direction,
                'status' => $referral->status,
                'encounter_id' => $referral->encounter_id,
                'to_branch_id' => $referral->to_branch_id,
                'specialty' => $referral->specialty,
                ...$context,
            ],
        ));
    }
}
