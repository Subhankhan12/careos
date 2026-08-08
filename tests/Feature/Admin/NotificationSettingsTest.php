<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Models\NotificationPreference;
use Modules\Comms\Models\NotificationTemplate;
use Modules\Comms\Services\NotificationPreferenceService;
use Modules\Comms\Services\NotificationService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * SETTINGS.P5 — the "Notifications" card manages a per-event EMAIL preference store that
 * NotificationService actually consults (an email-off event is not sent). SMS is an inert seam
 * (no provider wired). The clinician-attention flag is the Inbox agent's AI hand-off — locked-on,
 * not a preference, with no disable path from this card.
 */

function notifPrefCtx(): TenantContext
{
    return app(TenantContext::class);
}

function notifPrefTenant(string $slug = 'alpha'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    notifPrefCtx()->set($tenant);

    return $tenant;
}

function notifPrefUser(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    if ($roleKey !== '') {
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id]);
    }

    return $user;
}

/** A patient with a primary email + comms.email consent, so a transactional email can actually send. */
function notifPrefPatient(Tenant $tenant, User $staff): Patient
{
    $patient = app(PatientService::class)->create([
        'first_name' => 'Notify', 'last_name' => 'Patient', 'date_of_birth' => '1992-03-03', 'sex' => 'female',
    ]);
    PatientContact::query()->create([
        'patient_id' => $patient->id, 'type' => PatientContact::TYPE_EMAIL, 'value' => 'notify@example.test', 'is_primary' => true,
    ]);
    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'comms', 'version' => 1],
        ['title' => 'Email', 'body' => 'Email consent', 'scope_keys' => ['comms.email'], 'is_active' => true],
    );
    app(ConsentService::class)->grant($patient, 'comms', 'Notify Patient', $staff);

    return $patient;
}

// ── The card + preference store ───────────────────────────────────────────────

test('the notifications card lists the manageable email events, all on by default, SMS unavailable', function () {
    $tenant = notifPrefTenant();
    $admin = notifPrefUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->get('/admin/notifications')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Notifications')
            ->where('smsAvailable', false)
            ->has('events', 3)
            ->where('events.0.emailEnabled', true)
            ->has('updateUrl'));
});

test('saving persists email on/off per event, tenant-scoped and audited', function () {
    $tenant = notifPrefTenant();
    $admin = notifPrefUser($tenant, 'org_admin');

    $this->actingAs($admin)
        ->post('/admin/notifications', ['email' => ['appointment.reminder' => false, 'waitlist.offer' => true]])
        ->assertRedirect('/admin/notifications');

    notifPrefCtx()->set($tenant);
    expect(app(NotificationPreferenceService::class)->emailEnabled('appointment.reminder'))->toBeFalse()
        ->and(app(NotificationPreferenceService::class)->emailEnabled('waitlist.offer'))->toBeTrue();

    $audited = DB::selectOne(
        'SELECT COUNT(*) c FROM audit_events WHERE tenant_id <=> ? AND action = ?',
        [$tenant->id, 'notification.preferences_changed'],
    )->c;
    expect((int) $audited)->toBe(1);
});

// ── THE FENCE (c): an email-off pref actually suppresses that email ────────────

test('THE FENCE: NotificationService respects an email-off preference — the event is not sent', function () {
    Notification::fake();
    $tenant = notifPrefTenant();
    $admin = notifPrefUser($tenant, 'org_admin');
    $patient = notifPrefPatient($tenant, $admin);
    $service = app(NotificationService::class);

    // Default ON → the transactional reminder sends.
    $sent = $service->send('appointment.reminder', $patient, ['starts_at' => '2026-09-01 09:00']);
    expect($sent->status)->toBe(NotificationDelivery::STATUS_SENT);

    // Turn email OFF for that event → the next (distinct) send is SKIPPED with pref_off.
    app(NotificationPreferenceService::class)->setEmail('appointment.reminder', false);
    $skipped = $service->send('appointment.reminder', $patient, ['starts_at' => '2026-09-02 09:00']);
    expect($skipped->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($skipped->skipped_reason)->toBe('pref_off');
});

test('a legal notice can never be suppressed by the preference store', function () {
    Notification::fake();
    $tenant = notifPrefTenant();
    $admin = notifPrefUser($tenant, 'org_admin');
    $patient = notifPrefPatient($tenant, $admin);

    // The store refuses to write a preference for a non-manageable (legal) event.
    expect(fn () => app(NotificationPreferenceService::class)->setEmail('billing.dunning', false))
        ->toThrow(InvalidArgumentException::class);

    // And a legal notice sends regardless (its category is excluded from the preference gate).
    NotificationTemplate::query()->create([
        'key' => 'legal.notice', 'version' => 1, 'channel' => NotificationTemplate::CHANNEL_EMAIL,
        'category' => NotificationTemplate::CATEGORY_LEGAL, 'subject' => 'Notice', 'body' => 'Body', 'active' => true,
    ]);
    $legal = app(NotificationService::class)->send('legal.notice', $patient, []);
    expect($legal->status)->toBe(NotificationDelivery::STATUS_SENT);
});

// ── THE FENCE (b): SMS is an inert seam ───────────────────────────────────────

test('THE FENCE: SMS is an inert seam — a posted sms value is ignored and nothing is stored', function () {
    $tenant = notifPrefTenant();
    $admin = notifPrefUser($tenant, 'org_admin');

    // Even if a client forges an sms map, the controller only reads `email` for manageable events.
    $this->actingAs($admin)
        ->post('/admin/notifications', ['email' => ['appointment.reminder' => true], 'sms' => ['appointment.reminder' => true]])
        ->assertRedirect('/admin/notifications');

    notifPrefCtx()->set($tenant);
    // The store has no SMS concept at all — only an email flag exists on the row.
    expect(NotificationPreference::query()->get()->every(fn ($p) => ! isset($p->sms_enabled)))->toBeTrue();
});

// ── THE FENCE (a): the clinician-attention hand-off has no disable path ────────

test('THE FENCE: the clinician-attention flag is not manageable and cannot be disabled from the card', function () {
    $tenant = notifPrefTenant();
    $admin = notifPrefUser($tenant, 'org_admin');

    // It is NOT among the manageable events — the store cannot even hold a preference for it.
    expect(NotificationPreferenceService::MANAGEABLE)->not->toContain('clinician.attention')
        ->and(fn () => app(NotificationPreferenceService::class)->setEmail('clinician.attention', false))
        ->toThrow(InvalidArgumentException::class);

    // Posting a clinician-attention key through the card is ignored — no preference is written for it.
    $this->actingAs($admin)
        ->post('/admin/notifications', ['email' => ['clinician.attention' => false]])
        ->assertRedirect('/admin/notifications');

    notifPrefCtx()->set($tenant);
    expect(NotificationPreference::query()->where('event_key', 'clinician.attention')->exists())->toBeFalse();
});

// ── RBAC ──────────────────────────────────────────────────────────────────────

test('the notifications card is gated on admin.manage — a non-admin is 403 on read and write', function () {
    $tenant = notifPrefTenant();
    $reception = notifPrefUser($tenant, 'reception'); // no admin.manage

    $this->actingAs($reception)->get('/admin/notifications')->assertForbidden();
    $this->actingAs($reception)
        ->post('/admin/notifications', ['email' => ['appointment.reminder' => false]])
        ->assertForbidden();
});

test('notification preferences are tenant-scoped', function () {
    $alpha = notifPrefTenant('alpha');
    $alphaAdmin = notifPrefUser($alpha, 'org_admin');
    $beta = notifPrefTenant('beta'); // sets context to beta

    $this->actingAs($alphaAdmin)
        ->post('/admin/notifications', ['email' => ['appointment.reminder' => false]])
        ->assertRedirect('/admin/notifications');

    // Beta never saw the change — its event is still emailed (default on).
    notifPrefCtx()->set($beta);
    expect(app(NotificationPreferenceService::class)->emailEnabled('appointment.reminder'))->toBeTrue();
});
