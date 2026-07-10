<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Encounter;
use Modules\Comms\Contracts\TelehealthProvider;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Models\TelehealthParticipant;
use Modules\Comms\Models\TelehealthSession;
use Modules\Comms\Providers\Telehealth\FakeTelehealthProvider;
use Modules\Comms\Services\TelehealthService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function g4Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function g4Fake(): FakeTelehealthProvider
{
    $fake = app(FakeTelehealthProvider::class);
    app()->instance(FakeTelehealthProvider::class, $fake);
    app()->instance(TelehealthProvider::class, $fake);

    return $fake;
}

/**
 * @return array{tenant: Tenant, actor: User, staff: StaffProfile, patient: Patient, encounter: Encounter, fake: FakeTelehealthProvider}
 */
function g4Fixture(string $slug = 'alpha'): array
{
    $fake = g4Fake();

    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    g4Ctx()->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()->where('key', 'doctor')->firstOrFail()->id,
    ]);

    $branch = Branch::query()->create(['name' => 'Tele Branch', 'code' => 'TELE']);
    $staff = StaffProfile::query()->create([
        'user_id' => $actor->id,
        'first_name' => 'Tele',
        'last_name' => 'Doctor',
        'display_name' => 'Tele Doctor',
        'profession' => 'doctor',
        'primary_branch_id' => $branch->id,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Video',
        'last_name' => 'Patient',
        'date_of_birth' => '1987-07-07',
        'sex' => 'female',
    ]);
    $encounter = Encounter::query()->create([
        'patient_id' => $patient->id,
        'practitioner_id' => $staff->id,
        'branch_id' => $branch->id,
        'appointment_id' => null,
        'type' => Encounter::TYPE_CONSULTATION,
        'started_at' => now()->toDateTimeString(),
        'status' => Encounter::STATUS_OPEN,
        'reason_for_visit' => 'Telehealth fixture',
    ]);

    return compact('tenant', 'actor', 'staff', 'patient', 'encounter', 'fake');
}

function g4PortalReady(Patient $patient, User $staff): PortalAccount
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
    app(ConsentService::class)->grant($patient, 'portal', 'Video Patient', $staff);

    return PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'video.'.$patient->id.'@portal.test',
        'password' => bcrypt('secret-portal-pass'),
        'status' => PortalAccount::STATUS_ACTIVE,
        'activated_at' => now(),
    ]);
}

function g4AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('room creation passes recording-disabled options to the provider', function () {
    $fx = g4Fixture();

    app(TelehealthService::class)->createSessionFromEncounter($fx['encounter'], $fx['actor']);

    expect($fx['fake']->createdRooms)->toHaveCount(1)
        ->and($fx['fake']->createdRooms[0]['options']['recording_disabled'])->toBeTrue();

    // The adapter REFUSES a room without the recording-disabled option.
    expect(fn () => $fx['fake']->createRoom('rogue-room', []))
        ->toThrow(InvalidArgumentException::class, 'recording disabled');
});

test('issued tokens are scoped to one room one identity one role with short TTL and no recording grant', function () {
    $fx = g4Fixture();
    $service = app(TelehealthService::class);
    $session = $service->createSessionFromEncounter($fx['encounter'], $fx['actor']);

    $token = $service->joinTokenForStaff($session, $fx['actor']);

    expect($token->grants['room'])->toBe($session->room_reference)
        ->and($token->identity)->toBe('staff-'.$fx['actor']->id)
        ->and($token->role)->toBe('staff')
        ->and($token->grants['roomJoin'])->toBeTrue()
        ->and($token->grants['canPublish'])->toBeTrue()
        ->and($token->grants['canSubscribe'])->toBeTrue()
        // NOBODY can record or administer the room:
        ->and($token->grants['roomRecord'])->toBeFalse()
        ->and($token->grants['roomAdmin'])->toBeFalse()
        ->and($token->grants['recorder'])->toBeFalse()
        ->and($token->ttlSeconds)->toBeLessThanOrEqual((int) config('telehealth.max_token_ttl_seconds'))
        ->and($token->ttlSeconds)->toBeLessThanOrEqual(600)
        // The token is never persisted anywhere.
        ->and(Schema::hasTable('telehealth_tokens'))->toBeFalse();
});

test('a patient token is fail-closed on portal account, consent, and being the session patient', function () {
    $fx = g4Fixture();
    $service = app(TelehealthService::class);
    $session = $service->createSessionFromEncounter($fx['encounter'], $fx['actor']);

    // 1) No portal account + no consent: nothing to authenticate with; an
    //    INACTIVE portal account is refused.
    $account = PortalAccount::query()->create([
        'patient_id' => $fx['patient']->id,
        'email' => 'inactive@portal.test',
        'password' => bcrypt('secret-portal-pass'),
        'status' => PortalAccount::STATUS_INVITED,
    ]);
    expect(fn () => $service->joinTokenForPatient($session, $account))
        ->toThrow(AuthorizationException::class, 'not active');

    // 2) Active account but NO portal.access consent.
    $account->forceFill(['status' => PortalAccount::STATUS_ACTIVE, 'activated_at' => now()])->save();
    expect(fn () => $service->joinTokenForPatient($session, $account))
        ->toThrow(AuthorizationException::class, 'consented');

    // 3) A DIFFERENT patient's active, consented account is refused.
    $other = app(PatientService::class)->create([
        'first_name' => 'Other',
        'last_name' => 'Patient',
        'date_of_birth' => '1970-01-01',
        'sex' => 'male',
    ]);
    $otherAccount = g4PortalReady($other, $fx['actor']);
    expect(fn () => $service->joinTokenForPatient($session, $otherAccount))
        ->toThrow(AuthorizationException::class, 'not part of this telehealth session');

    // All three satisfied: the session patient with an active, consented account.
    app(ConsentService::class)->grant($fx['patient'], 'portal', 'Video Patient', $fx['actor']);
    $token = $service->joinTokenForPatient($session, $account);

    expect($token->role)->toBe('patient')
        ->and($token->grants['roomRecord'])->toBeFalse();
});

test('another tenants session is unreachable', function () {
    $alpha = g4Fixture('alpha');
    $session = app(TelehealthService::class)->createSessionFromEncounter($alpha['encounter'], $alpha['actor']);

    g4Fixture('beta');

    expect(TelehealthSession::query()->whereKey($session->id)->exists())->toBeFalse()
        ->and(fn () => app(TelehealthService::class)->joinTokenForStaff($session, User::query()->firstOrFail()))
        ->toThrow(Exception::class);
});

test('no media or recording columns exist on the telehealth tables', function () {
    g4Fixture();

    $forbidden = '/record|media|audio|video|transcript|egress|stream|capture/i';

    foreach (['telehealth_sessions', 'telehealth_participants'] as $tableName) {
        foreach (Schema::getColumnListing($tableName) as $column) {
            expect(preg_match($forbidden, $column))->toBe(0, "Column {$tableName}.{$column} suggests media/recording storage.");
        }
    }
});

test('participants are append-only: leave fills once and rows are never edited or deleted', function () {
    $fx = g4Fixture();
    $service = app(TelehealthService::class);
    $session = $service->createSessionFromEncounter($fx['encounter'], $fx['actor']);

    $participant = $service->recordJoin($session, TelehealthParticipant::TYPE_STAFF, (string) $fx['actor']->id);
    $service->recordLeave($participant);

    expect($participant->refresh()->left_at)->not->toBeNull()
        // left_at is immutable after set — model and DB trigger.
        ->and(fn () => $participant->forceFill(['left_at' => now()->addHour()])->save())
        ->toThrow(LogicException::class)
        ->and(fn () => DB::update('UPDATE telehealth_participants SET left_at = NOW() WHERE id = ?', [$participant->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::update("UPDATE telehealth_participants SET participant_id = 'tampered' WHERE id = ?", [$participant->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM telehealth_participants WHERE id = ?', [$participant->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => $participant->delete())->toThrow(LogicException::class);
});

test('provider credentials never appear in logs during session create and token issue', function () {
    config()->set('telehealth.providers.livekit.api_key', 'SENTINEL-KEY-1234');
    config()->set('telehealth.providers.livekit.api_secret', 'SENTINEL-SECRET-9876');

    $captured = [];
    Log::listen(function ($event) use (&$captured): void {
        $captured[] = $event->message.' '.json_encode($event->context);
    });

    $fx = g4Fixture();
    $service = app(TelehealthService::class);
    $session = $service->createSessionFromEncounter($fx['encounter'], $fx['actor']);
    $service->joinTokenForStaff($session, $fx['actor']);
    $service->endSession($session, $fx['actor']);

    $all = implode("\n", $captured);

    expect(str_contains($all, 'SENTINEL-KEY-1234'))->toBeFalse()
        ->and(str_contains($all, 'SENTINEL-SECRET-9876'))->toBeFalse();

    // Nor do credentials or tokens land in the audit trail.
    $auditBlob = json_encode(DB::select('SELECT context FROM audit_events'));
    expect(str_contains((string) $auditBlob, 'SENTINEL'))->toBeFalse();
});

test('the session lifecycle is audited and token issue is patient-scoped read-logged', function () {
    $fx = g4Fixture();
    $service = app(TelehealthService::class);
    $session = $service->createSessionFromEncounter($fx['encounter'], $fx['actor']);

    $service->joinTokenForStaff($session, $fx['actor']);
    $service->recordJoin($session, TelehealthParticipant::TYPE_STAFF, (string) $fx['actor']->id);
    $service->endSession($session->refresh(), $fx['actor']);

    $reads = g4AuditRows($fx['tenant']->id, 'read')
        ->filter(fn (object $row): bool => $row->patient_id === $fx['patient']->id
            && $row->resource_type === 'telehealth_sessions');

    expect(g4AuditRows($fx['tenant']->id, 'telehealth.session_created'))->toHaveCount(1)
        ->and(g4AuditRows($fx['tenant']->id, 'telehealth.token_issued'))->toHaveCount(1)
        ->and(g4AuditRows($fx['tenant']->id, 'telehealth.session_started'))->toHaveCount(1)
        ->and(g4AuditRows($fx['tenant']->id, 'telehealth.session_ended'))->toHaveCount(1)
        ->and($reads->count())->toBeGreaterThanOrEqual(1)
        ->and($fx['fake']->endedRooms)->toContain($session->room_reference)
        ->and(app(AuditService::class)->verifyChain($fx['tenant']->id)['ok'])->toBeTrue();
});

test('the invitation goes through the notification engine as transactional', function () {
    Notification::fake();
    $fx = g4Fixture();
    g4PortalReady($fx['patient'], $fx['actor']); // portal.access consent (not comms.email)
    $service = app(TelehealthService::class);
    $session = $service->createSessionFromEncounter($fx['encounter'], $fx['actor']);

    // No comms.email consent -> the transactional invite is skipped fail-closed.
    $service->sendInvitation($session, $fx['actor']);

    $delivery = NotificationDelivery::query()->where('template_key', 'telehealth.invite')->firstOrFail();

    expect($delivery->category)->toBe('transactional')
        ->and($delivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($delivery->skipped_reason)->toBe('no_consent');
});

test('staff without the session RBAC cannot create sessions or get tokens', function () {
    $fx = g4Fixture();
    $reception = User::factory()->forTenant($fx['tenant'])->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $reception->id,
        'role_id' => Role::query()->where('key', 'billing')->firstOrFail()->id, // no encounter.manage
    ]);

    expect(fn () => app(TelehealthService::class)->createSessionFromEncounter($fx['encounter'], $reception))
        ->toThrow(AuthorizationException::class);

    $session = app(TelehealthService::class)->createSessionFromEncounter($fx['encounter'], $fx['actor']);

    expect(fn () => app(TelehealthService::class)->joinTokenForStaff($session, $reception))
        ->toThrow(AuthorizationException::class);
});
