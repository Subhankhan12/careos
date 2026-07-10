<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Comms\Models\ThreadParticipant;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function g1Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function g1User(Tenant $tenant, string $role = 'reception'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, patient: Patient}
 */
function g1Fixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    g1Ctx()->set($tenant);

    $actor = g1User($tenant);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Thread',
        'last_name' => 'Patient',
        'date_of_birth' => '1990-05-05',
        'sex' => 'female',
    ]);

    return compact('tenant', 'actor', 'patient');
}

function g1PortalReady(Patient $patient, User $staff): void
{
    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'portal', 'version' => 1],
        [
            'title' => 'Portal Access',
            'body' => 'Portal access consent',
            'scope_keys' => ['portal.access'],
            'is_active' => true,
        ],
    );
    app(ConsentService::class)->grant($patient, 'portal', $patient->first_name.' '.$patient->last_name, $staff);

    PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => strtolower($patient->first_name).'.'.$patient->id.'@portal.test',
        'password' => bcrypt('secret-portal-pass'),
        'status' => PortalAccount::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);
}

function g1AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('a patient thread carries its patient and the care team can message it', function () {
    $fx = g1Fixture();
    $service = app(ThreadService::class);

    $thread = $service->openPatientThread($fx['patient'], 'Post-visit question', $fx['actor']);

    expect($thread->type)->toBe(Thread::TYPE_PATIENT)
        ->and($thread->patient_id)->toBe($fx['patient']->id)
        ->and(ThreadParticipant::query()->where('thread_id', $thread->id)->whereNull('removed_at')->count())->toBe(2);

    $message = $service->postStaffMessage($thread, $fx['actor'], 'Hello, how can we help?');

    expect($message->author_type)->toBe(Message::AUTHOR_STAFF)
        ->and($message->ai_assisted)->toBeFalse()
        ->and($thread->refresh()->last_message_at?->toDateTimeString())->toBe($message->sent_at->toDateTimeString())
        ->and(g1AuditRows($fx['tenant']->id, 'thread.opened'))->toHaveCount(1)
        ->and(g1AuditRows($fx['tenant']->id, 'message.posted'))->toHaveCount(1);
});

test('a patient with portal account and consent can read and post to their own thread', function () {
    $fx = g1Fixture();
    g1PortalReady($fx['patient'], $fx['actor']);
    $service = app(ThreadService::class);
    $thread = $service->openPatientThread($fx['patient'], 'My results', $fx['actor']);
    $service->postStaffMessage($thread, $fx['actor'], 'Your documents are ready.');

    $messages = $service->messagesForPatient($thread, $fx['patient']);
    $reply = $service->postPatientMessage($thread, $fx['patient'], 'Thank you, I have a question.');

    expect($messages)->toHaveCount(1)
        ->and($reply->author_type)->toBe(Message::AUTHOR_PATIENT)
        ->and($reply->author_patient_id)->toBe($fx['patient']->id);
});

test('a patient cannot read another patients thread', function () {
    $fx = g1Fixture();
    g1PortalReady($fx['patient'], $fx['actor']);
    $other = app(PatientService::class)->create([
        'first_name' => 'Other',
        'last_name' => 'Patient',
        'date_of_birth' => '1980-01-01',
        'sex' => 'male',
    ]);
    g1PortalReady($other, $fx['actor']);

    $thread = app(ThreadService::class)->openPatientThread($fx['patient'], 'Private thread', $fx['actor']);

    expect(fn () => app(ThreadService::class)->messagesForPatient($thread, $other))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ThreadService::class)->postPatientMessage($thread, $other, 'Let me in'))
        ->toThrow(AuthorizationException::class);
});

test('patient access is fail-closed without an active portal account or portal consent', function () {
    $fx = g1Fixture();
    $service = app(ThreadService::class);
    $thread = $service->openPatientThread($fx['patient'], 'Gated thread', $fx['actor']);

    // No portal account, no consent.
    expect(fn () => $service->messagesForPatient($thread, $fx['patient']))
        ->toThrow(AuthorizationException::class);

    // Active portal account but still no portal.access consent.
    PortalAccount::query()->create([
        'patient_id' => $fx['patient']->id,
        'email' => 'gated@portal.test',
        'password' => bcrypt('secret-portal-pass'),
        'status' => PortalAccount::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);

    expect(fn () => $service->messagesForPatient($thread, $fx['patient']))
        ->toThrow(AuthorizationException::class);
});

test('staff without comms.manage cannot post or open threads', function () {
    $fx = g1Fixture();
    $doctor = g1User($fx['tenant'], 'doctor'); // doctor has no comms.manage
    $service = app(ThreadService::class);
    $thread = $service->openPatientThread($fx['patient'], 'RBAC thread', $fx['actor']);

    expect(fn () => $service->postStaffMessage($thread, $doctor, 'Not allowed'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $service->openInternalThread('Nope', $doctor))
        ->toThrow(AuthorizationException::class)
        ->and(Message::query()->count())->toBe(0);
});

test('a patient can never be added to an internal thread', function () {
    $fx = g1Fixture();
    $service = app(ThreadService::class);
    $internal = $service->openInternalThread('Team huddle', $fx['actor']);

    expect(fn () => $service->addPatientParticipant($internal, $fx['patient'], $fx['actor']))
        ->toThrow(InvalidArgumentException::class, 'internal')
        ->and(fn () => ThreadParticipant::query()->create([
            'thread_id' => $internal->id,
            'participant_type' => ThreadParticipant::TYPE_PATIENT,
            'patient_id' => $fx['patient']->id,
            'added_at' => now(),
        ]))->toThrow(InvalidArgumentException::class)
        ->and(ThreadParticipant::query()->where('thread_id', $internal->id)->whereNotNull('patient_id')->count())->toBe(0);

    // An internal thread also rejects carrying a patient reference at all.
    expect(fn () => Thread::query()->create([
        'subject' => 'Bad internal',
        'type' => Thread::TYPE_INTERNAL,
        'patient_id' => $fx['patient']->id,
        'created_by' => $fx['actor']->id,
    ]))->toThrow(InvalidArgumentException::class);
});

test('only the thread patient may participate in a patient thread', function () {
    $fx = g1Fixture();
    $other = app(PatientService::class)->create([
        'first_name' => 'Wrong',
        'last_name' => 'Patient',
        'date_of_birth' => '1975-02-02',
        'sex' => 'other',
    ]);
    $thread = app(ThreadService::class)->openPatientThread($fx['patient'], 'One patient only', $fx['actor']);

    expect(fn () => app(ThreadService::class)->addPatientParticipant($thread, $other, $fx['actor']))
        ->toThrow(InvalidArgumentException::class);
});

test('messages are append-only at the database level and corrections are new messages', function () {
    $fx = g1Fixture();
    $service = app(ThreadService::class);
    $thread = $service->openPatientThread($fx['patient'], 'Append only', $fx['actor']);
    $message = $service->postStaffMessage($thread, $fx['actor'], 'Original wording.');

    expect(fn () => DB::update("UPDATE messages SET body = 'rewritten' WHERE id = ?", [$message->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM messages WHERE id = ?', [$message->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => $message->forceFill(['body' => 'rewritten'])->save())
        ->toThrow(LogicException::class)
        ->and(fn () => $message->delete())->toThrow(LogicException::class);

    $correction = $service->postStaffMessage($thread, $fx['actor'], 'Correction: the appointment is at 10:00.');

    expect(Message::query()->where('thread_id', $thread->id)->count())->toBe(2)
        ->and($message->refresh()->body)->toBe('Original wording.')
        ->and($correction->body)->toContain('Correction');
});

test('reading a patient thread writes a patient-scoped read audit row', function () {
    $fx = g1Fixture();
    $service = app(ThreadService::class);
    $thread = $service->openPatientThread($fx['patient'], 'Read logged', $fx['actor']);
    $service->postStaffMessage($thread, $fx['actor'], 'Sensitive content.');

    $service->messagesForStaff($thread, $fx['actor']);

    $reads = g1AuditRows($fx['tenant']->id, 'read')
        ->filter(fn (object $row): bool => $row->patient_id === $fx['patient']->id && $row->resource_type === 'threads');

    expect($reads->count())->toBeGreaterThanOrEqual(1)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();

    // Internal threads are not patient data: no patient-scoped read row.
    $internal = $service->openInternalThread('No patient data', $fx['actor']);
    $before = g1AuditRows($fx['tenant']->id, 'read')->count();
    $service->messagesForStaff($internal, $fx['actor']);

    expect(g1AuditRows($fx['tenant']->id, 'read')->count())->toBe($before);
});

test('threads and messages are tenant isolated and fail closed', function () {
    $alpha = g1Fixture('alpha');
    $thread = app(ThreadService::class)->openPatientThread($alpha['patient'], 'Alpha thread', $alpha['actor']);
    app(ThreadService::class)->postStaffMessage($thread, $alpha['actor'], 'Alpha message');

    $beta = g1Fixture('beta');

    expect(Thread::query()->whereKey($thread->id)->exists())->toBeFalse()
        ->and(Message::query()->count())->toBe(0)
        ->and(fn () => app(ThreadService::class)->postStaffMessage($thread, $beta['actor'], 'Cross-tenant'))
        ->toThrow(CrossTenantReferenceException::class);

    g1Ctx()->forget();

    expect(fn () => Thread::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => Message::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => ThreadParticipant::query()->count())->toThrow(TenantContextMissingException::class);
});

test('closing a thread stops new messages and is audited', function () {
    $fx = g1Fixture();
    $service = app(ThreadService::class);
    $thread = $service->openPatientThread($fx['patient'], 'To close', $fx['actor']);

    $service->close($thread, $fx['actor']);

    expect($thread->refresh()->status)->toBe(Thread::STATUS_CLOSED)
        ->and(fn () => $service->postStaffMessage($thread, $fx['actor'], 'Too late'))
        ->toThrow(InvalidArgumentException::class)
        ->and(g1AuditRows($fx['tenant']->id, 'thread.closed'))->toHaveCount(1);
});
