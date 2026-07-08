<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\DuplicateCandidate;
use Modules\Patients\Services\DuplicateDetector;
use Modules\Patients\Services\PatientDuplicateReviewService;
use Modules\Patients\Services\PatientMergeService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function b3Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function b3Ctx(): TenantContext
{
    return app(TenantContext::class);
}

function b3PatientService(): PatientService
{
    return app(PatientService::class);
}

function b3MergeService(): PatientMergeService
{
    return app(PatientMergeService::class);
}

function b3ConsentService(): ConsentService
{
    return app(ConsentService::class);
}

function b3Role(string $key): Role
{
    return Role::where('key', $key)->firstOrFail();
}

function b3Grant(User $user, string $role = 'org_admin'): void
{
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => b3Role($role)->id]);
    Auth::login($user);
}

/**
 * @param  list<array<string, mixed>>  $contacts
 * @param  list<array<string, mixed>>  $identifiers
 * @param  list<array<string, mixed>>  $coverages
 */
function b3Patient(array $overrides = [], array $contacts = [], array $identifiers = [], array $coverages = []): Patient
{
    return b3PatientService()->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'date_of_birth' => '1980-05-10',
        'sex' => 'female',
        ...$overrides,
    ], $contacts, $identifiers, $coverages);
}

function b3Address(string $line1 = 'Main Street 10', string $postal = '8000', string $city = 'Zurich'): array
{
    return [
        'type' => 'address',
        'line1' => $line1,
        'postal' => $postal,
        'city' => $city,
        'country' => 'CH',
        'is_primary' => true,
    ];
}

function b3ConsentTemplate(): ConsentTemplate
{
    return ConsentTemplate::create([
        'key' => 'portal',
        'title' => 'Portal Access',
        'body' => 'Portal access consent',
        'version' => 1,
        'scope_keys' => ['portal.access'],
        'is_active' => true,
    ]);
}

function b3Candidate(Collection $candidates, Patient $patient): DuplicateCandidate
{
    return $candidates->first(fn (DuplicateCandidate $candidate): bool => $candidate->patient->is($patient));
}

function b3AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('detector scores demographic duplicates explicitly and keeps identifier-only low', function () {
    $tenant = b3Tenant('alpha');
    b3Ctx()->set($tenant);

    $subject = b3Patient(
        contacts: [b3Address()],
        identifiers: [['system' => 'national_id', 'value' => 'SHARED-ID']],
    );
    $obvious = b3Patient(
        ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
        [b3Address('Main Street 12')],
        [['system' => 'national_id', 'value' => 'SHARED-ID']],
    );
    $nameOnly = b3Patient([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'date_of_birth' => '1999-01-01',
    ]);
    $identifierOnly = b3Patient([
        'first_name' => 'Zoe',
        'last_name' => 'Zebra',
        'date_of_birth' => '1970-01-01',
    ], identifiers: [['system' => 'national_id', 'value' => 'SHARED-ID']]);
    $unrelated = b3Patient([
        'first_name' => 'Mina',
        'last_name' => 'Stone',
        'date_of_birth' => '1965-03-03',
    ], [b3Address('Lake Road 1', '9000', 'St Gallen')]);

    $candidates = app(DuplicateDetector::class)->findForPatient($subject);
    $review = app(PatientDuplicateReviewService::class)->potentialDuplicatesFor($subject, 80);

    $obviousScore = b3Candidate($candidates, $obvious);
    $nameOnlyScore = b3Candidate($candidates, $nameOnly);
    $identifierOnlyScore = b3Candidate($candidates, $identifierOnly);
    $unrelatedScore = b3Candidate($candidates, $unrelated);

    expect($obviousScore->score)->toBeGreaterThanOrEqual(80)
        ->and($obviousScore->confidence)->toBe('high')
        ->and($obviousScore->reasons)->toContain('dob_exact')
        ->and($obviousScore->reasons)->toContain('postal_exact')
        ->and($obviousScore->reasons)->toContain('identifier_match_raises_confidence')
        ->and($nameOnlyScore->score)->toBeLessThan(50)
        ->and($nameOnlyScore->confidence)->toBe('low')
        ->and($identifierOnlyScore->score)->toBe(10)
        ->and($identifierOnlyScore->confidence)->toBe('low')
        ->and($unrelatedScore->score)->toBe(0)
        ->and($review->pluck('patient.id')->all())->toBe([$obvious->id]);
});

test('duplicate detection is tenant scoped', function () {
    $a = b3Tenant('alpha');
    $b = b3Tenant('beta');

    b3Ctx()->set($a);
    $subject = b3Patient(contacts: [b3Address()]);

    b3Ctx()->set($b);
    b3Patient(contacts: [b3Address()]);

    b3Ctx()->set($a);
    $candidates = app(DuplicateDetector::class)->findForPatient($subject);

    expect($candidates)->toHaveCount(0);
});

test('merge requires a reason permission and same tenant target', function () {
    $a = b3Tenant('alpha');
    $b = b3Tenant('beta');

    b3Ctx()->set($a);
    $user = User::factory()->forTenant($a)->create();
    b3Grant($user);
    $source = b3Patient();
    $target = b3Patient(['first_name' => 'Ada Target']);

    expect(fn () => b3MergeService()->merge($source->id, $target->id, '   '))
        ->toThrow(InvalidArgumentException::class);

    Auth::logout();
    $unprivileged = User::factory()->forTenant($a)->create();
    Auth::login($unprivileged);

    expect(fn () => b3MergeService()->merge($source->id, $target->id, 'Duplicate registration'))
        ->toThrow(AuthorizationException::class);

    Auth::logout();
    b3Grant($user);

    b3Ctx()->set($b);
    $targetB = b3Patient(['first_name' => 'Beta Target']);

    b3Ctx()->set($a);

    expect(fn () => b3MergeService()->merge($source->id, $targetB->id, 'Cross-tenant target'))
        ->toThrow(ModelNotFoundException::class);
});

test('merge re-points children marks the source merged and combines target records', function () {
    $tenant = b3Tenant('alpha');
    b3Ctx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->create();
    b3Grant($user);

    $source = b3Patient(
        contacts: [
            ['type' => 'email', 'value' => 'source@example.test', 'is_primary' => true],
            b3Address('Source Street 1'),
        ],
        identifiers: [['system' => 'passport', 'value' => 'SRC']],
        coverages: [['payer_name' => 'Self', 'member_id' => 'SRC-COV', 'coverage_type' => 'self_pay', 'priority' => 1]],
    );
    $target = b3Patient(
        ['first_name' => 'Ada Target'],
        [['type' => 'email', 'value' => 'target@example.test', 'is_primary' => true]],
    );
    b3ConsentTemplate();
    $sourceConsent = b3ConsentService()->grant($source, 'portal', 'Ada Lovelace', $user);

    b3MergeService()->merge($source->id, $target->id, 'Duplicate registration');

    $sourceAfter = Patient::withTrashed()->whereKey($source->id)->firstOrFail();

    expect(Patient::find($source->id))->toBeNull()
        ->and($sourceAfter->status)->toBe(Patient::STATUS_MERGED)
        ->and($sourceAfter->merged_into_id)->toBe($target->id)
        ->and($sourceAfter->trashed())->toBeTrue()
        ->and(PatientContact::where('patient_id', $target->id)->count())->toBe(3)
        ->and($target->refresh()->contacts()->where('value', 'source@example.test')->exists())->toBeTrue()
        ->and($target->identifiers()->where('value', 'SRC')->exists())->toBeTrue()
        ->and($target->coverages()->where('member_id', 'SRC-COV')->exists())->toBeTrue()
        ->and(PatientConsent::whereKey($sourceConsent->id)->value('patient_id'))->toBe($target->id);
});

test('merge writes an audit event and the tenant audit chain verifies', function () {
    $tenant = b3Tenant('alpha');
    b3Ctx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->create();
    b3Grant($user);

    $source = b3Patient();
    $target = b3Patient(['first_name' => 'Ada Target']);

    $eventId = b3MergeService()->merge($source->id, $target->id, 'Duplicate registration');

    $rows = b3AuditRows($tenant->id, 'patient.merged');
    $context = json_decode($rows[0]->context, true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($eventId)
        ->and($rows[0]->resource_id)->toBe($target->id)
        ->and($rows[0]->patient_id)->toBe($target->id)
        ->and($rows[0]->reason)->toBe('Duplicate registration')
        ->and($context['source_patient_id'])->toBe($source->id)
        ->and($context['target_patient_id'])->toBe($target->id)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('unmerge restores the source and only the children moved by the merge', function () {
    $tenant = b3Tenant('alpha');
    b3Ctx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->create();
    b3Grant($user);

    $source = b3Patient(
        contacts: [['type' => 'email', 'value' => 'source@example.test', 'is_primary' => true]],
        identifiers: [['system' => 'passport', 'value' => 'SRC']],
        coverages: [['payer_name' => 'Self', 'member_id' => 'SRC-COV', 'coverage_type' => 'self_pay', 'priority' => 1]],
    );
    $sourceContactId = $source->contacts->first()->id;
    $sourceIdentifierId = $source->identifiers->first()->id;
    $sourceCoverageId = $source->coverages->first()->id;
    b3ConsentTemplate();
    $sourceConsentId = b3ConsentService()->grant($source, 'portal', 'Ada Lovelace', $user)->id;
    $target = b3Patient(['first_name' => 'Ada Target']);

    $mergeEventId = b3MergeService()->merge($source->id, $target->id, 'Duplicate registration');

    $target->contacts()->create(['type' => 'phone', 'value' => '+4100000000', 'is_primary' => true]);

    $unmergeEventId = b3MergeService()->unmerge($mergeEventId);

    $sourceAfter = Patient::findOrFail($source->id);

    expect($sourceAfter->status)->toBe(Patient::STATUS_ACTIVE)
        ->and($sourceAfter->merged_into_id)->toBeNull()
        ->and(PatientContact::whereKey($sourceContactId)->value('patient_id'))->toBe($source->id)
        ->and(DB::table('patient_identifiers')->where('id', $sourceIdentifierId)->value('patient_id'))->toBe($source->id)
        ->and(DB::table('patient_coverages')->where('id', $sourceCoverageId)->value('patient_id'))->toBe($source->id)
        ->and(PatientConsent::whereKey($sourceConsentId)->value('patient_id'))->toBe($source->id)
        ->and($target->contacts()->where('value', '+4100000000')->exists())->toBeTrue()
        ->and(b3AuditRows($tenant->id, 'patient.unmerged'))->toHaveCount(1)
        ->and(b3AuditRows($tenant->id, 'patient.unmerged')->first()->id)->toBe($unmergeEventId)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});
