<?php

namespace Modules\Comms\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Encounter;
use Modules\Comms\Contracts\TelehealthProvider;
use Modules\Comms\Models\TelehealthParticipant;
use Modules\Comms\Models\TelehealthSession;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;

/**
 * Telehealth sessions (D-G1/G2/G3):
 *  - media NEVER passes through or rests on CareOS servers — we persist only
 *    the room reference, participants, and join/leave timestamps;
 *  - rooms are created with RECORDING DISABLED at the provider level;
 *  - the room is NOT the clinical record: no transcript, no audio capture,
 *    no AI listening, ever (ELECTRIC FENCE) — documentation happens in a
 *    Phase D SOAP note like any other encounter;
 *  - join tokens are short-lived, single-room, single-identity, single-role,
 *    never stored, never logged.
 */
class TelehealthService
{
    public function __construct(
        private readonly TelehealthProvider $provider,
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
        private readonly ConsentService $consents,
        private readonly NotificationService $notifications,
    ) {}

    public function createSessionFromEncounter(Encounter $encounter, User $actor): TelehealthSession
    {
        Gate::forUser($actor)->authorize('encounter.manage');
        $this->assertActorTenant($actor);
        $this->assertSameTenant($encounter, 'encounter_id');

        return $this->createSession(
            patientId: $encounter->patient_id,
            practitionerId: $encounter->practitioner_id,
            actor: $actor,
            encounterId: $encounter->id,
        );
    }

    public function createSessionFromAppointment(Appointment $appointment, StaffProfile $practitioner, User $actor): TelehealthSession
    {
        Gate::forUser($actor)->authorize('appointment.manage');
        $this->assertActorTenant($actor);
        $this->assertSameTenant($appointment, 'appointment_id');
        $this->assertSameTenant($practitioner, 'practitioner_id');

        if ($appointment->patient_id === null) {
            throw new InvalidArgumentException('A telehealth session requires an appointment with a patient.');
        }

        return $this->createSession(
            patientId: $appointment->patient_id,
            practitionerId: $practitioner->id,
            actor: $actor,
            appointmentId: $appointment->id,
        );
    }

    /**
     * Staff join token: short-lived, one room, one identity, one role, no
     * recording capability. Issued on demand — never stored, never logged.
     */
    public function joinTokenForStaff(TelehealthSession $session, User $staff): TelehealthToken
    {
        Gate::forUser($staff)->authorize($session->encounter_id !== null ? 'encounter.manage' : 'appointment.manage');
        $this->assertActorTenant($staff);
        $this->assertSameTenant($session, 'session_id');

        return $this->issueToken($session, TelehealthParticipant::TYPE_STAFF, 'staff-'.$staff->id, 'staff', (string) $staff->id);
    }

    /**
     * Patient join token — fail-closed on ALL THREE: the portal account must be
     * ACTIVE, must belong to the session's patient, and the patient must hold
     * the portal.access consent.
     */
    public function joinTokenForPatient(TelehealthSession $session, PortalAccount $account): TelehealthToken
    {
        $this->assertSameTenant($session, 'session_id');
        $this->assertSameTenant($account, 'portal_account_id');

        if ($account->status !== PortalAccount::STATUS_ACTIVE) {
            throw new AuthorizationException('This portal account is not active.');
        }

        if ($account->patient_id !== $session->patient_id) {
            throw new AuthorizationException('This patient is not part of this telehealth session.');
        }

        $patient = Patient::query()->whereKey($session->patient_id)->firstOrFail();

        if (! $this->consents->has($patient, 'portal.access')) {
            throw new AuthorizationException('This patient has not consented to portal access.');
        }

        return $this->issueToken($session, TelehealthParticipant::TYPE_PATIENT, 'patient-'.$account->patient_id, 'patient', $account->patient_id);
    }

    public function recordJoin(TelehealthSession $session, string $participantType, string $participantId): TelehealthParticipant
    {
        $this->assertSameTenant($session, 'session_id');

        if ($session->status === TelehealthSession::STATUS_CREATED) {
            $session->forceFill(['status' => TelehealthSession::STATUS_ACTIVE, 'started_at' => now()])->save();
            $this->auditSession('telehealth.session_started', $session);
        }

        return TelehealthParticipant::query()->create([
            'session_id' => $session->id,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'joined_at' => now(),
        ]);
    }

    public function recordLeave(TelehealthParticipant $participant): TelehealthParticipant
    {
        $this->assertSameTenant($participant, 'participant_id');

        $participant->forceFill(['left_at' => now()])->save();

        return $participant->refresh();
    }

    public function endSession(TelehealthSession $session, User $actor): TelehealthSession
    {
        Gate::forUser($actor)->authorize($session->encounter_id !== null ? 'encounter.manage' : 'appointment.manage');
        $this->assertActorTenant($actor);
        $this->assertSameTenant($session, 'session_id');

        $this->provider->endRoom($session->room_reference);
        $session->forceFill(['status' => TelehealthSession::STATUS_ENDED, 'ended_at' => now()])->save();

        $this->auditSession('telehealth.session_ended', $session, ['actor_id' => $actor->id]);

        return $session->refresh();
    }

    /**
     * Invitation (D-G4 classification: TRANSACTIONAL). The invitation delivers
     * a service the patient already booked — contract performance, not
     * marketing — so it uses the transactional template category, which keeps
     * the same consent posture as appointment reminders: consent-gated
     * fail-closed on comms.email; staff can always convey the link directly.
     */
    public function sendInvitation(TelehealthSession $session, User $actor): void
    {
        Gate::forUser($actor)->authorize($session->encounter_id !== null ? 'encounter.manage' : 'appointment.manage');
        $this->assertActorTenant($actor);
        $this->assertSameTenant($session, 'session_id');

        $patient = Patient::query()->whereKey($session->patient_id)->firstOrFail();

        $this->notifications->send('telehealth.invite', $patient, [
            'join_url' => url('/portal/telehealth'),
            'session_id' => $session->id,
        ]);
    }

    private function createSession(
        string $patientId,
        string $practitionerId,
        User $actor,
        ?string $appointmentId = null,
        ?string $encounterId = null,
    ): TelehealthSession {
        // D-G2: recording is disabled AT THE PROVIDER — the room cannot record.
        $roomReference = $this->provider->createRoom('careos-'.strtolower((string) Str::ulid()), [
            'recording_disabled' => true,
            'max_participants' => 2,
        ]);

        $session = TelehealthSession::query()->create([
            'appointment_id' => $appointmentId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'practitioner_id' => $practitionerId,
            'provider' => $this->provider->name(),
            'room_reference' => $roomReference,
            'status' => TelehealthSession::STATUS_CREATED,
        ]);

        $this->auditSession('telehealth.session_created', $session, ['actor_id' => $actor->id]);

        return $session->refresh();
    }

    private function issueToken(
        TelehealthSession $session,
        string $participantType,
        string $identity,
        string $role,
        string $participantRef,
    ): TelehealthToken {
        if ($session->status === TelehealthSession::STATUS_ENDED) {
            throw new InvalidArgumentException('This telehealth session has ended.');
        }

        $ttl = min(600, (int) config('telehealth.max_token_ttl_seconds', 600));
        $token = $this->provider->issueToken($session->room_reference, $identity, $role, $ttl);

        // Token issuance is access to a patient encounter: patient-scoped
        // read-log + audit. The token itself is NEVER persisted or logged.
        $session->auditRead(['surface' => 'telehealth_token', 'participant_type' => $participantType]);
        $this->auditSession('telehealth.token_issued', $session, [
            'participant_type' => $participantType,
            'role' => $role,
            'ttl_seconds' => $ttl,
        ]);

        return $token;
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('actor_id', (string) $actor->id);
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
    private function auditSession(string $action, TelehealthSession $session, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'system',
            'action' => $action,
            'patient_id' => $session->patient_id,
            'resource_type' => 'telehealth_session',
            'resource_id' => $session->id,
            'context' => [
                'provider' => $session->provider,
                'room_reference' => $session->room_reference,
                'status' => $session->status,
                ...$context,
            ],
        ]);
    }
}
