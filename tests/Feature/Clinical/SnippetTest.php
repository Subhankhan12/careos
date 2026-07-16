<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\TextSnippet;
use Modules\Clinical\Services\SnippetService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function snCtx(): TenantContext
{
    return app(TenantContext::class);
}

function snTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    snCtx()->set($tenant);

    return $tenant;
}

function snUser(Tenant $tenant, string $role = 'doctor', string $name = 'Dana Doctor'): User
{
    snCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'Staff');
    StaffProfile::query()->create([
        'user_id' => $user->id,
        'first_name' => $first,
        'last_name' => $last,
        'display_name' => $name,
        'profession' => $role,
        'status' => StaffProfile::STATUS_ACTIVE,
    ]);

    return $user;
}

function snStaff(User $user): StaffProfile
{
    return StaffProfile::query()->where('user_id', $user->id)->firstOrFail();
}

test('personal wins over shared on the same trigger', function () {
    $tenant = snTenant('alpha');
    $doctor = snUser($tenant, 'doctor'); // has snippet.manage.shared
    $service = app(SnippetService::class);

    $service->create($doctor, ['scope' => 'personal', 'trigger' => 'normalexam', 'title' => 'Mine', 'body' => 'PERSONAL body']);
    $service->create($doctor, ['scope' => 'shared', 'trigger' => 'normalexam', 'title' => 'Ours', 'body' => 'SHARED body']);

    // The author sees their PERSONAL for this trigger.
    $resolvedForOwner = $service->resolveFor(snStaff($doctor), 'normalexam');
    expect($resolvedForOwner?->scope)->toBe(TextSnippet::SCOPE_PERSONAL)
        ->and($resolvedForOwner?->body)->toBe('PERSONAL body');

    // A different clinician (no personal for that trigger) falls back to SHARED.
    $other = snUser($tenant, 'nurse', 'Nora Nurse');
    $resolvedForOther = $service->resolveFor(snStaff($other), 'normalexam');
    expect($resolvedForOther?->scope)->toBe(TextSnippet::SCOPE_SHARED)
        ->and($resolvedForOther?->body)->toBe('SHARED body');
});

test('a clinician sees only their personal plus active shared — never another clinician personal', function () {
    $tenant = snTenant('alpha');
    $doctorA = snUser($tenant, 'doctor', 'A Doc');
    $doctorB = snUser($tenant, 'doctor', 'B Doc');
    $service = app(SnippetService::class);

    $service->create($doctorA, ['scope' => 'personal', 'trigger' => 'aaa', 'title' => 'A', 'body' => 'a']);
    $service->create($doctorB, ['scope' => 'personal', 'trigger' => 'bbb', 'title' => 'B', 'body' => 'b']);
    $service->create($doctorA, ['scope' => 'shared', 'trigger' => 'ccc', 'title' => 'C', 'body' => 'c']);

    $listA = $service->list(snStaff($doctorA))->pluck('trigger')->all();
    expect($listA)->toContain('aaa')
        ->and($listA)->toContain('ccc')
        ->and($listA)->not->toContain('bbb'); // never B's personal
});

test('shared CRUD requires snippet.manage.shared; a regular clinician is refused', function () {
    $tenant = snTenant('alpha');
    $doctor = snUser($tenant, 'doctor');
    $nurse = snUser($tenant, 'nurse', 'Nora Nurse'); // no snippet.manage.shared
    $service = app(SnippetService::class);

    // A regular clinician cannot create a shared snippet.
    expect(fn () => $service->create($nurse, ['scope' => 'shared', 'trigger' => 'x', 'title' => 'X', 'body' => 'x']))
        ->toThrow(AuthorizationException::class);

    // A privileged clinician can, and the nurse cannot then edit/delete it.
    $shared = $service->create($doctor, ['scope' => 'shared', 'trigger' => 'greet', 'title' => 'Greeting', 'body' => 'Hello']);
    expect(fn () => $service->update($shared, $nurse, ['title' => 'hacked']))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->delete($shared, $nurse))->toThrow(AuthorizationException::class);

    // A duplicate shared trigger is refused (service-enforced uniqueness).
    expect(fn () => $service->create($doctor, ['scope' => 'shared', 'trigger' => 'greet', 'title' => 'Dup', 'body' => 'y']))
        ->toThrow(InvalidArgumentException::class);
});

test('expansion substitutes ONLY the whitelist and can never substitute a clinical field', function () {
    $tenant = snTenant('alpha');
    $doctor = snUser($tenant, 'doctor');
    $service = app(SnippetService::class);

    $snippet = $service->create($doctor, [
        'scope' => 'personal',
        'trigger' => 'demo',
        'title' => 'Demo',
        'body' => 'Seen {{patient_first_name}} (DOB {{patient_dob}}) by {{clinician_name}} at {{branch_name}} on {{date}}. '
            .'Dx {{diagnosis}}; Meds {{medication}}; Allergy {{allergy}}; BP {{bp}}; Score {{risk_score}}; {{unknown_token}}',
    ]);

    // The context deliberately ALSO contains clinical keys — they must be ignored.
    $context = [
        'date' => '2026-07-16',
        'patient_first_name' => 'Ada',
        'patient_dob' => '1990-05-15',
        'clinician_name' => 'Dr Bloggs',
        'branch_name' => 'Main',
        // Poisoned clinical keys that must NEVER be substituted:
        'diagnosis' => 'LEAKED-DIAGNOSIS',
        'medication' => 'LEAKED-MED',
        'allergy' => 'LEAKED-ALLERGY',
        'bp' => 'LEAKED-BP',
        'risk_score' => 'LEAKED-SCORE',
        'unknown_token' => 'LEAKED-UNKNOWN',
    ];

    $out = $service->expand($snippet, $context);

    // Whitelist substituted.
    expect($out)->toContain('Seen Ada (DOB 1990-05-15) by Dr Bloggs at Main on 2026-07-16.');

    // Clinical / unknown tokens left LITERAL — nothing leaked.
    foreach (['{{diagnosis}}', '{{medication}}', '{{allergy}}', '{{bp}}', '{{risk_score}}', '{{unknown_token}}'] as $literal) {
        expect($out)->toContain($literal);
    }
    foreach (['LEAKED-DIAGNOSIS', 'LEAKED-MED', 'LEAKED-ALLERGY', 'LEAKED-BP', 'LEAKED-SCORE', 'LEAKED-UNKNOWN'] as $leak) {
        expect($out)->not->toContain($leak);
    }

    // Deterministic — no interpretation: re-expanding yields the identical string.
    expect($service->expand($snippet, $context))->toBe($out);
});

test('a personal snippet is editable only by its owner', function () {
    $tenant = snTenant('alpha');
    $owner = snUser($tenant, 'doctor', 'Owner Doc');
    $stranger = snUser($tenant, 'doctor', 'Stranger Doc');
    $service = app(SnippetService::class);

    $mine = $service->create($owner, ['scope' => 'personal', 'trigger' => 'mine', 'title' => 'Mine', 'body' => 'x']);

    expect(fn () => $service->update($mine, $stranger, ['title' => 'stolen']))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->delete($mine, $stranger))->toThrow(AuthorizationException::class);

    $service->update($mine, $owner, ['title' => 'Renamed']);
    expect($mine->refresh()->title)->toBe('Renamed');

    // The DB enforces one personal trigger per owner.
    expect(fn () => $service->create($owner, ['scope' => 'personal', 'trigger' => 'mine', 'title' => 'Dup', 'body' => 'y']))
        ->toThrow(QueryException::class);
});

test('shared snippet changes are audited and snippets are tenant isolated', function () {
    $alpha = snTenant('alpha');
    $doctor = snUser($alpha, 'doctor');
    $service = app(SnippetService::class);

    $service->create($doctor, ['scope' => 'shared', 'trigger' => 'hello', 'title' => 'Hello', 'body' => 'Hi']);
    $service->create($doctor, ['scope' => 'personal', 'trigger' => 'me', 'title' => 'Me', 'body' => 'x']);

    // Shared change is audited (affects everyone); snippets are NOT patient data (patient_id null).
    $sharedAudit = DB::select("SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'snippet.shared.created'", [$alpha->id]);
    expect($sharedAudit)->toHaveCount(1)
        ->and($sharedAudit[0]->patient_id)->toBeNull()
        ->and(app(AuditService::class)->verifyChain($alpha->id)['ok'])->toBeTrue();

    // A second tenant sees no snippets.
    $beta = snTenant('beta');
    $betaDoc = snUser($beta, 'doctor');
    expect(TextSnippet::query()->count())->toBe(0)
        ->and(app(SnippetService::class)->resolveFor(snStaff($betaDoc), 'hello'))->toBeNull()
        ->and(app(SnippetService::class)->list(snStaff($betaDoc))->count())->toBe(0);
});
