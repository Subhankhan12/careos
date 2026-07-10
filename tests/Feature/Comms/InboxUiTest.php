<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function g3Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function g3User(Tenant $tenant, string $role = 'reception'): User
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
function g3Fixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    g3Ctx()->set($tenant);

    $actor = g3User($tenant);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Inbox',
        'last_name' => 'Patient',
        'date_of_birth' => '1991-01-01',
        'sex' => 'female',
    ]);

    return compact('tenant', 'actor', 'patient');
}

function g3ReadRows(string $tenantId, string $patientId): Collection
{
    return collect(DB::select(
        "SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'read' AND patient_id = ? AND resource_type = 'threads'",
        [$tenantId, $patientId],
    ));
}

test('the inbox route is RBAC gated and renders the Inertia component with thread props', function () {
    $fx = g3Fixture();
    $thread = app(ThreadService::class)->openPatientThread($fx['patient'], 'Inbox thread', $fx['actor']);
    app(ThreadService::class)->postStaffMessage($thread, $fx['actor'], 'First message');

    $doctor = g3User($fx['tenant'], 'doctor'); // no comms.manage

    $this->actingAs($doctor)->get('/comms/inbox')->assertForbidden();

    $this->actingAs($fx['actor'])
        ->get('/comms/inbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Comms/Inbox')
            ->has('threads', 1)
            ->where('threads.0.subject', 'Inbox thread')
            ->where('threads.0.type', 'patient')
            ->where('activeThread', null)
            ->has('filters')
            ->has('actions.replyUrl'));
});

test('opening a patient thread writes a patient-scoped read audit row and marks it read', function () {
    $fx = g3Fixture();
    $service = app(ThreadService::class);
    $thread = $service->openPatientThread($fx['patient'], 'Read me', $fx['actor']);
    $service->postStaffMessage($thread, $fx['actor'], 'Hello');

    $reader = g3User($fx['tenant']); // second reception user

    expect($service->unreadCount($thread, $reader))->toBe(1);

    $this->actingAs($reader)
        ->get('/comms/inbox?thread_id='.$thread->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Comms/Inbox')
            ->where('activeThread.id', $thread->id)
            ->has('activeThread.messages', 1));

    expect(g3ReadRows($fx['tenant']->id, $fx['patient']->id)->count())->toBeGreaterThanOrEqual(1)
        ->and($service->unreadCount($thread->refresh(), $reader))->toBe(0);
});

test('posting a reply appends a message through the service and respects append-only', function () {
    $fx = g3Fixture();
    $thread = app(ThreadService::class)->openPatientThread($fx['patient'], 'Reply thread', $fx['actor']);

    $this->actingAs($fx['actor'])
        ->post('/comms/inbox/reply', [
            'thread_id' => $thread->id,
            'body' => 'A reply from the inbox',
        ])
        ->assertRedirect();

    $message = Message::query()->where('thread_id', $thread->id)->firstOrFail();

    expect($message->body)->toBe('A reply from the inbox')
        ->and($message->author_type)->toBe(Message::AUTHOR_STAFF)
        ->and($message->ai_assisted)->toBeFalse();

    $doctor = g3User($fx['tenant'], 'doctor');
    $this->actingAs($doctor)
        ->post('/comms/inbox/reply', ['thread_id' => $thread->id, 'body' => 'Nope'])
        ->assertForbidden();

    expect(Message::query()->where('thread_id', $thread->id)->count())->toBe(1);
});

test('unread counts are derived per staff user and drop to zero after reading', function () {
    $fx = g3Fixture();
    $service = app(ThreadService::class);
    $thread = $service->openPatientThread($fx['patient'], 'Unread math', $fx['actor']);
    $service->postStaffMessage($thread, $fx['actor'], 'One');
    $service->postStaffMessage($thread, $fx['actor'], 'Two');

    $reader = g3User($fx['tenant']);

    expect($service->unreadCount($thread, $reader))->toBe(2);

    $service->markRead($thread, $reader);
    expect($service->unreadCount($thread, $reader))->toBe(0);

    $service->postStaffMessage($thread, $fx['actor'], 'Three');
    expect($service->unreadCount($thread->refresh(), $reader))->toBe(1)
        // No stored counter anywhere: the value is derived from messages + marker.
        ->and(DB::table('thread_reads')->where('staff_user_id', $reader->id)->count())->toBe(1);
});

test('assignment drives the mine filter and close reopen work through the actions', function () {
    $fx = g3Fixture();
    $service = app(ThreadService::class);
    $mine = $service->openPatientThread($fx['patient'], 'Mine', $fx['actor']);
    $service->openInternalThread('Not mine', $fx['actor']);

    $this->actingAs($fx['actor'])
        ->post('/comms/inbox/assign', ['thread_id' => $mine->id, 'assign_self' => true])
        ->assertRedirect();

    expect($mine->refresh()->assigned_to)->toBe($fx['actor']->id);

    $this->actingAs($fx['actor'])
        ->get('/comms/inbox?scope=mine')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Comms/Inbox')
            ->has('threads', 1)
            ->where('threads.0.id', $mine->id));

    $this->actingAs($fx['actor'])
        ->post('/comms/inbox/status', ['thread_id' => $mine->id, 'action' => 'close'])
        ->assertRedirect();

    expect($mine->refresh()->status)->toBe(Thread::STATUS_CLOSED);

    $this->actingAs($fx['actor'])
        ->post('/comms/inbox/status', ['thread_id' => $mine->id, 'action' => 'reopen'])
        ->assertRedirect();

    expect($mine->refresh()->status)->toBe(Thread::STATUS_OPEN);
});

test('the inbox is tenant scoped', function () {
    $alpha = g3Fixture('alpha');
    app(ThreadService::class)->openPatientThread($alpha['patient'], 'Alpha only', $alpha['actor']);

    $beta = g3Fixture('beta');

    $this->actingAs($beta['actor'])
        ->get('/comms/inbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Comms/Inbox')
            ->has('threads', 0));
});
