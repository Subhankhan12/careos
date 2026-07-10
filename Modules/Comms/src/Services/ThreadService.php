<?php

namespace Modules\Comms\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Encounter;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Comms\Models\ThreadParticipant;
use Modules\Comms\Models\ThreadRead;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Secure messaging threads. Staff actions require 'comms.manage'. Patient
 * access is fail-closed on three checks: the patient must be the thread's own
 * patient AND an active participant AND hold an active portal account with the
 * 'portal.access' consent. A patient can never touch an internal thread —
 * ThreadParticipant enforces that invariant at the model level too.
 */
class ThreadService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
        private readonly ConsentService $consents,
    ) {}

    public function openPatientThread(Patient $patient, string $subject, User $creator, ?Encounter $encounter = null): Thread
    {
        $this->authorizeStaff($creator);
        $this->assertSameTenant($patient, 'patient_id');

        if ($encounter !== null) {
            $this->assertSameTenant($encounter, 'encounter_id');

            if ($encounter->patient_id !== $patient->id) {
                throw new InvalidArgumentException('A thread encounter must belong to the thread patient.');
            }
        }

        return DB::transaction(function () use ($patient, $subject, $creator, $encounter): Thread {
            $thread = Thread::query()->create([
                'subject' => $subject,
                'type' => Thread::TYPE_PATIENT,
                'patient_id' => $patient->id,
                'encounter_id' => $encounter?->id,
                'created_by' => $creator->id,
            ]);

            // A patient thread always includes its patient plus the creator.
            $this->participantRow($thread, ThreadParticipant::TYPE_PATIENT, null, $patient->id);
            $this->participantRow($thread, ThreadParticipant::TYPE_STAFF, $creator->id, null);

            $this->auditThread('thread.opened', $thread, $creator);

            return $thread->refresh();
        });
    }

    public function openInternalThread(string $subject, User $creator): Thread
    {
        $this->authorizeStaff($creator);

        return DB::transaction(function () use ($subject, $creator): Thread {
            $thread = Thread::query()->create([
                'subject' => $subject,
                'type' => Thread::TYPE_INTERNAL,
                'patient_id' => null,
                'created_by' => $creator->id,
            ]);

            $this->participantRow($thread, ThreadParticipant::TYPE_STAFF, $creator->id, null);
            $this->auditThread('thread.opened', $thread, $creator);

            return $thread->refresh();
        });
    }

    public function addStaffParticipant(Thread $thread, User $staff, User $actor): ThreadParticipant
    {
        $this->authorizeStaff($actor);
        $this->assertSameTenant($thread, 'thread_id');

        if ($staff->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('staff_user_id', (string) $staff->id);
        }

        $participant = $this->participantRow($thread, ThreadParticipant::TYPE_STAFF, $staff->id, null);
        $this->auditThread('thread.participant_added', $thread, $actor, ['staff_user_id' => $staff->id]);

        return $participant;
    }

    /**
     * Adding a patient participant is only legal on that patient's own patient
     * thread; the ThreadParticipant model enforces the internal-thread ban too.
     */
    public function addPatientParticipant(Thread $thread, Patient $patient, User $actor): ThreadParticipant
    {
        $this->authorizeStaff($actor);
        $this->assertSameTenant($thread, 'thread_id');
        $this->assertSameTenant($patient, 'patient_id');

        $participant = $this->participantRow($thread, ThreadParticipant::TYPE_PATIENT, null, $patient->id);
        $this->auditThread('thread.participant_added', $thread, $actor, ['patient_id' => $patient->id]);

        return $participant;
    }

    public function removeParticipant(ThreadParticipant $participant, User $actor): ThreadParticipant
    {
        $this->authorizeStaff($actor);
        $this->assertSameTenant($participant, 'participant_id');

        $participant->forceFill(['removed_at' => now()])->save();

        $thread = Thread::query()->whereKey($participant->thread_id)->firstOrFail();
        $this->auditThread('thread.participant_removed', $thread, $actor, [
            'participant_id' => $participant->id,
        ]);

        return $participant->refresh();
    }

    public function postStaffMessage(Thread $thread, User $author, string $body, bool $aiAssisted = false): Message
    {
        $this->authorizeStaff($author);
        $this->assertSameTenant($thread, 'thread_id');
        $this->assertOpen($thread);

        return $this->appendMessage($thread, [
            'author_type' => Message::AUTHOR_STAFF,
            'author_staff_user_id' => $author->id,
            'body' => $body,
            'ai_assisted' => $aiAssisted,
        ], actorType: 'user', actorId: (string) $author->id);
    }

    public function postPatientMessage(Thread $thread, Patient $patient, string $body): Message
    {
        $this->assertSameTenant($thread, 'thread_id');
        $this->assertPatientAccess($thread, $patient);
        $this->assertOpen($thread);

        return $this->appendMessage($thread, [
            'author_type' => Message::AUTHOR_PATIENT,
            'author_patient_id' => $patient->id,
            'body' => $body,
        ], actorType: 'patient', actorId: $patient->id);
    }

    public function close(Thread $thread, User $actor): Thread
    {
        $this->authorizeStaff($actor);
        $this->assertSameTenant($thread, 'thread_id');

        $thread->forceFill(['status' => Thread::STATUS_CLOSED])->save();
        $this->auditThread('thread.closed', $thread, $actor);

        return $thread->refresh();
    }

    public function reopen(Thread $thread, User $actor): Thread
    {
        $this->authorizeStaff($actor);
        $this->assertSameTenant($thread, 'thread_id');

        $thread->forceFill(['status' => Thread::STATUS_OPEN])->save();
        $this->auditThread('thread.reopened', $thread, $actor);

        return $thread->refresh();
    }

    /**
     * Disclose a thread's messages to a staff user. Reading a patient thread
     * is reading patient data: it writes a patient-scoped read audit row.
     *
     * @return EloquentCollection<int, Message>
     */
    public function messagesForStaff(Thread $thread, User $actor): EloquentCollection
    {
        $this->authorizeStaff($actor);
        $this->assertSameTenant($thread, 'thread_id');

        if ($thread->isPatientThread()) {
            $thread->auditRead(['surface' => 'comms_thread']);
        }

        return Message::query()->where('thread_id', $thread->id)->orderBy('sent_at')->orderBy('id')->get();
    }

    /**
     * Disclose a thread's messages to the patient — fail-closed on the
     * three-way patient access check, and read-logged.
     *
     * @return EloquentCollection<int, Message>
     */
    public function messagesForPatient(Thread $thread, Patient $patient): EloquentCollection
    {
        $this->assertSameTenant($thread, 'thread_id');
        $this->assertPatientAccess($thread, $patient);

        $thread->auditRead(['surface' => 'comms_thread_portal']);

        return Message::query()->where('thread_id', $thread->id)->orderBy('sent_at')->orderBy('id')->get();
    }

    public function assign(Thread $thread, ?User $assignee, User $actor): Thread
    {
        $this->authorizeStaff($actor);
        $this->assertSameTenant($thread, 'thread_id');

        if ($assignee !== null && $assignee->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('assigned_to', (string) $assignee->id);
        }

        $thread->forceFill(['assigned_to' => $assignee?->id])->save();
        $this->auditThread('thread.assigned', $thread, $actor, ['assigned_to' => $assignee?->id]);

        return $thread->refresh();
    }

    /**
     * Record that the staff user has read the thread up to its newest message.
     * Unread counts are DERIVED from this marker; nothing is stored to drift.
     */
    public function markRead(Thread $thread, User $staff): void
    {
        $newestMessageId = Message::query()
            ->where('thread_id', $thread->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->value('id');

        ThreadRead::query()->updateOrCreate(
            ['thread_id' => $thread->id, 'staff_user_id' => $staff->id],
            ['last_read_message_id' => $newestMessageId, 'read_at' => now()],
        );
    }

    /**
     * Derived unread count for one staff user: messages that arrived after the
     * user's read marker (ULID message ids are time-ordered). Never stored.
     */
    public function unreadCount(Thread $thread, User $staff): int
    {
        $read = ThreadRead::query()
            ->where('thread_id', $thread->id)
            ->where('staff_user_id', $staff->id)
            ->first();

        $query = Message::query()->where('thread_id', $thread->id);

        if ($read?->last_read_message_id !== null) {
            $query->where('id', '>', $read->last_read_message_id);
        }

        return $query->count();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function appendMessage(Thread $thread, array $attributes, string $actorType, string $actorId): Message
    {
        return DB::transaction(function () use ($thread, $attributes, $actorType, $actorId): Message {
            $message = Message::query()->create([
                ...$attributes,
                'thread_id' => $thread->id,
                'sent_at' => now(),
            ]);

            $thread->forceFill(['last_message_at' => $message->sent_at])->save();

            $this->audit->record([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => 'message.posted',
                'patient_id' => $thread->patient_id,
                'resource_type' => 'message',
                'resource_id' => $message->id,
                'context' => [
                    'thread_id' => $thread->id,
                    'thread_type' => $thread->type,
                    'author_type' => $message->author_type,
                    'ai_assisted' => $message->ai_assisted,
                ],
            ]);

            return $message;
        });
    }

    private function participantRow(Thread $thread, string $type, ?int $staffUserId, ?string $patientId): ThreadParticipant
    {
        $existing = ThreadParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('staff_user_id', $staffUserId)
            ->where('patient_id', $patientId)
            ->whereNull('removed_at')
            ->first();

        if ($existing instanceof ThreadParticipant) {
            return $existing;
        }

        return ThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'participant_type' => $type,
            'staff_user_id' => $staffUserId,
            'patient_id' => $patientId,
            'added_at' => now(),
        ]);
    }

    /**
     * Patient access is fail-closed on all three: own thread, active
     * participant, and an active portal account carrying 'portal.access'.
     */
    private function assertPatientAccess(Thread $thread, Patient $patient): void
    {
        if (! $thread->isPatientThread() || $thread->patient_id !== $patient->id) {
            throw new AuthorizationException('This patient cannot access this thread.');
        }

        $isParticipant = ThreadParticipant::query()
            ->where('thread_id', $thread->id)
            ->where('patient_id', $patient->id)
            ->whereNull('removed_at')
            ->exists();

        if (! $isParticipant) {
            throw new AuthorizationException('This patient does not participate in this thread.');
        }

        $hasActivePortalAccount = PortalAccount::query()
            ->where('patient_id', $patient->id)
            ->where('status', PortalAccount::STATUS_ACTIVE)
            ->exists();

        if (! $hasActivePortalAccount) {
            throw new AuthorizationException('This patient has no active portal account.');
        }

        if (! $this->consents->has($patient, 'portal.access')) {
            throw new AuthorizationException('This patient has not consented to portal access.');
        }
    }

    private function assertOpen(Thread $thread): void
    {
        if ($thread->status !== Thread::STATUS_OPEN) {
            throw new InvalidArgumentException('Only open threads accept messages.');
        }
    }

    private function authorizeStaff(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('comms.manage')) {
            throw new AuthorizationException('This user cannot manage communications.');
        }

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
    private function auditThread(string $action, Thread $thread, User $actor, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'patient_id' => $thread->patient_id,
            'resource_type' => 'thread',
            'resource_id' => $thread->id,
            'context' => [
                'type' => $thread->type,
                'subject' => $thread->subject,
                'status' => $thread->status,
                ...$context,
            ],
        ]);
    }
}
