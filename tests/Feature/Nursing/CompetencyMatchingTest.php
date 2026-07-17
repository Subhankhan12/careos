<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Nursing\Exceptions\AssignmentValidationException;
use Modules\Nursing\Models\AgreementService;
use Modules\Nursing\Models\Competency;
use Modules\Nursing\Models\NurseCompetency;
use Modules\Nursing\Models\NurseConstraint;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\VisitPlan;
use Modules\Nursing\Services\AssignmentValidator;
use Modules\Nursing\Services\CompetencyService;
use Modules\Nursing\Services\ServiceAgreementService;
use Modules\Nursing\Services\VisitAssignmentService;
use Modules\Nursing\Services\VisitPlanGenerator;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

function g12Tenant(string $slug): Tenant
{
    return Tenant::create([
        'name' => ucfirst($slug).' Nursing',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
}

function g12User(Tenant $tenant, string $role = 'coordinator'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();

    if ($role !== '') {
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
        ]);
    }

    return $user;
}

/**
 * @return array{tenant: Tenant, actor: User, branch: Branch, patient: Patient, plan: VisitPlan, resource: BookableResource}
 */
function g12Fixture(string $slug = 'alpha'): array
{
    $tenant = g12Tenant($slug);
    app(TenantContext::class)->set($tenant);
    $actor = g12User($tenant);
    $branch = Branch::query()->create(['name' => strtoupper($slug).' Branch', 'code' => strtoupper(substr($slug, 0, 4))]);
    $patient = app(PatientService::class)->create([
        'first_name' => ucfirst($slug),
        'last_name' => 'Patient',
        'date_of_birth' => '1942-02-02',
        'sex' => 'female',
    ]);
    $service = Service::query()->create([
        'name' => 'Nursing visit',
        'code' => strtoupper($slug).'-NURSE',
        'category' => 'home-care',
        'default_duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => false,
        'active' => true,
    ]);
    $agreement = app(ServiceAgreementService::class)->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'funding_type' => ServiceAgreement::FUNDING_OTHER,
        'starts_on' => '2026-08-01',
    ], [[
        'service_id' => $service->id,
        'planned_frequency_text' => 'Weekdays as documented',
        'required_qualification' => 'RN',
        'duration_minutes' => 60,
    ]], $actor);

    $plan = VisitPlan::query()->create([
        'service_agreement_id' => $agreement->id,
        'agreement_service_id' => $agreement->agreementServices()->firstOrFail()->id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=1',
        'timezone' => 'Europe/Zurich',
        'window_start_time' => '09:00:00',
        'window_end_time' => '11:00:00',
        'duration_minutes' => 60,
        'starts_on' => '2026-08-03',
        'active' => true,
    ]);

    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Nurse One',
        'branch_id' => $branch->id,
    ]);
    NurseConstraint::query()->create([
        'resource_id' => $resource->id,
        'qualification' => 'RN',
        'max_hours_per_week' => '40.00',
        'max_travel_minutes_between_visits' => 60,
    ]);

    return compact('tenant', 'actor', 'branch', 'patient', 'plan', 'resource');
}

function g12Visit(array $fixture, array $overrides = []): PlannedVisit
{
    return PlannedVisit::query()->create([
        'visit_plan_id' => $fixture['plan']->id,
        'patient_id' => $fixture['patient']->id,
        'scheduled_date' => '2026-08-03',
        'window_start_at' => '2026-08-03 09:00:00',
        'window_end_at' => '2026-08-03 10:00:00',
        'duration_minutes' => 60,
        'required_qualification' => 'RN',
        'status' => PlannedVisit::STATUS_PLANNED,
        'location_latitude' => '47.376900',
        'location_longitude' => '8.541700',
        ...$overrides,
    ]);
}

function g12Competency(string $code, string $enforcement = Competency::ENFORCEMENT_HARD): Competency
{
    return Competency::query()->create([
        'code' => $code,
        'name' => ucfirst(str_replace('_', ' ', $code)),
        'enforcement' => $enforcement,
        'active' => true,
    ]);
}

function g12Grant(BookableResource $resource, Competency $competency, array $overrides = []): NurseCompetency
{
    return NurseCompetency::query()->create([
        'resource_id' => $resource->id,
        'competency_id' => $competency->id,
        'granted_at' => '2026-01-01',
        'expires_at' => null,
        'active' => true,
        ...$overrides,
    ]);
}

function g12AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('a hard required competency the nurse lacks blocks assignment; holding it allows it', function () {
    $fixture = g12Fixture();
    $wound = g12Competency('wound_care', Competency::ENFORCEMENT_HARD);
    $visit = g12Visit($fixture, ['required_competencies' => ['wound_care']]);

    $result = app(AssignmentValidator::class)->evaluate($visit, $fixture['resource'], []);

    expect($result->passes())->toBeFalse()
        ->and($result->blocking)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_HARD.':wound_care')
        ->and($result->warnings)->toBe([]);

    // Assignment through the concurrency-safe service is refused.
    expect(fn () => app(VisitAssignmentService::class)->assign($visit, $fixture['resource'], $fixture['actor']))
        ->toThrow(AssignmentValidationException::class);

    // Grant it → now allowed.
    g12Grant($fixture['resource'], $wound);

    $assigned = app(VisitAssignmentService::class)->assign($visit->refresh(), $fixture['resource'], $fixture['actor']);

    expect($assigned->status)->toBe(PlannedVisit::STATUS_ASSIGNED)
        ->and($assigned->assignmentWarnings)->toBe([]);
});

test('a soft required competency the nurse lacks allows assignment but returns a non-blocking warning', function () {
    $fixture = g12Fixture();
    g12Competency('dementia_care', Competency::ENFORCEMENT_SOFT);
    $visit = g12Visit($fixture, ['required_competencies' => ['dementia_care']]);

    $result = app(AssignmentValidator::class)->evaluate($visit, $fixture['resource'], []);

    expect($result->passes())->toBeTrue()
        ->and($result->blocking)->toBe([])
        ->and($result->warnings)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_SOFT.':dementia_care');

    // Booked, with the warning surfaced to the dispatcher, and recorded in audit.
    $assigned = app(VisitAssignmentService::class)->assign($visit, $fixture['resource'], $fixture['actor']);

    expect($assigned->status)->toBe(PlannedVisit::STATUS_ASSIGNED)
        ->and($assigned->assignmentWarnings)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_SOFT.':dementia_care');

    $assignRow = g12AuditRows($fixture['tenant']->id, 'planned_visit.assigned')->first();
    $context = json_decode($assignRow->context, true);

    expect($context['soft_competency_warnings'])
        ->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_SOFT.':dementia_care');
});

test('an expired competency counts as not-held for both hard and soft enforcement', function () {
    $fixture = g12Fixture();
    $hard = g12Competency('injection', Competency::ENFORCEMENT_HARD);
    $soft = g12Competency('palliative', Competency::ENFORCEMENT_SOFT);

    // Grants exist but expired yesterday-relative-to-any-run.
    g12Grant($fixture['resource'], $hard, ['expires_at' => '2020-01-01']);
    g12Grant($fixture['resource'], $soft, ['expires_at' => '2020-01-01']);

    $hardVisit = g12Visit($fixture, ['required_competencies' => ['injection']]);
    $softVisit = g12Visit($fixture, ['scheduled_date' => '2026-08-10', 'required_competencies' => ['palliative']]);

    $hardResult = app(AssignmentValidator::class)->evaluate($hardVisit, $fixture['resource'], []);
    $softResult = app(AssignmentValidator::class)->evaluate($softVisit, $fixture['resource'], []);

    expect($hardResult->blocking)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_HARD.':injection')
        ->and($softResult->passes())->toBeTrue()
        ->and($softResult->warnings)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_SOFT.':palliative');

    // A non-expired grant flips the hard visit to held/allowed.
    NurseCompetency::query()
        ->where('resource_id', $fixture['resource']->id)
        ->where('competency_id', $hard->id)
        ->update(['expires_at' => '2099-01-01']);

    expect(app(AssignmentValidator::class)->evaluate($hardVisit, $fixture['resource'], [])->passes())->toBeTrue();
});

test('the competency rule composes with other rules and preserves distinct reasons', function () {
    $fixture = g12Fixture();
    // Nurse HOLDS the hard competency, so competency passes...
    $wound = g12Competency('wound_care', Competency::ENFORCEMENT_HARD);
    g12Grant($fixture['resource'], $wound);
    // ...but the hour cap is tiny, so hour-cap must still block with its own reason.
    NurseConstraint::query()->where('resource_id', $fixture['resource']->id)->update(['max_hours_per_week' => '1.50']);

    $visit = g12Visit($fixture, [
        'window_start_at' => '2026-08-03 10:00:00',
        'window_end_at' => '2026-08-03 11:00:00',
        'required_competencies' => ['wound_care'],
    ]);
    $existing = g12Visit($fixture, [
        'scheduled_date' => '2026-08-04',
        'window_start_at' => '2026-08-03 08:00:00',
        'window_end_at' => '2026-08-03 09:00:00',
        'status' => PlannedVisit::STATUS_ASSIGNED,
        'assigned_resource_id' => $fixture['resource']->id,
    ]);

    $result = app(AssignmentValidator::class)->evaluate($visit, $fixture['resource'], [$existing]);

    expect($result->passes())->toBeFalse()
        ->and($result->blocking)->toContain(AssignmentValidator::REASON_HOUR_CAP_EXCEEDED)
        ->and($result->blocking)->not->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_HARD.':wound_care');
});

test('enforcement is per-competency: one hard and one soft on the same visit behave correctly together', function () {
    $fixture = g12Fixture();
    $hard = g12Competency('wound_care', Competency::ENFORCEMENT_HARD);
    g12Competency('dementia_care', Competency::ENFORCEMENT_SOFT);

    $visit = g12Visit($fixture, ['required_competencies' => ['wound_care', 'dementia_care']]);

    // Holds neither: the hard one blocks, the soft one still warns.
    $result = app(AssignmentValidator::class)->evaluate($visit, $fixture['resource'], []);
    expect($result->passes())->toBeFalse()
        ->and($result->blocking)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_HARD.':wound_care')
        ->and($result->warnings)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_SOFT.':dementia_care');

    // Satisfy only the hard one: assignment now passes but the soft warning remains.
    g12Grant($fixture['resource'], $hard);
    $result = app(AssignmentValidator::class)->evaluate($visit, $fixture['resource'], []);
    expect($result->passes())->toBeTrue()
        ->and($result->warnings)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_SOFT.':dementia_care');
});

test('a required code with no active tenant competency definition is advisory-only, never a hard block', function () {
    $fixture = g12Fixture();
    // No competency defined for this code at all.
    $visit = g12Visit($fixture, ['required_competencies' => ['unconfigured_skill']]);

    $result = app(AssignmentValidator::class)->evaluate($visit, $fixture['resource'], []);

    expect($result->passes())->toBeTrue()
        ->and($result->warnings)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_SOFT.':unconfigured_skill');

    // An INACTIVE definition is likewise advisory-only, never a hard block.
    $comp = g12Competency('inactive_skill', Competency::ENFORCEMENT_HARD);
    $comp->forceFill(['active' => false])->save();
    $visit2 = g12Visit($fixture, ['scheduled_date' => '2026-08-17', 'required_competencies' => ['inactive_skill']]);

    $result2 = app(AssignmentValidator::class)->evaluate($visit2, $fixture['resource'], []);
    expect($result2->passes())->toBeTrue()
        ->and($result2->warnings)->toContain(AssignmentValidator::REASON_COMPETENCY_MISSING_SOFT.':inactive_skill');
});

test('competencies are tenant-authored and tenant-isolated', function () {
    $alpha = g12Fixture('alpha');
    $alphaComp = g12Competency('wound_care');

    $beta = g12Fixture('beta');

    // Beta sees none of alpha's competencies.
    expect(Competency::query()->count())->toBe(0);

    // Beta cannot grant alpha's competency to alpha's nurse (both cross-tenant).
    expect(fn () => app(CompetencyService::class)->grant($alpha['resource'], $alphaComp, [], $beta['actor']))
        ->toThrow(CrossTenantReferenceException::class);
});

test('competency definition, enforcement, and grant/revoke changes are audited and chain verifies', function () {
    $fixture = g12Fixture();

    $comp = app(CompetencyService::class)->create([
        'code' => 'wound_care',
        'name' => 'Wound care',
        'enforcement' => Competency::ENFORCEMENT_SOFT,
    ], $fixture['actor']);

    app(CompetencyService::class)->update($comp, ['enforcement' => Competency::ENFORCEMENT_HARD], $fixture['actor']);

    $grant = app(CompetencyService::class)->grant($fixture['resource'], $comp->refresh(), [], $fixture['actor']);
    app(CompetencyService::class)->revoke($grant, $fixture['actor']);

    expect(g12AuditRows($fixture['tenant']->id, 'competency.defined'))->toHaveCount(1)
        ->and(g12AuditRows($fixture['tenant']->id, 'competency.enforcement_changed'))->toHaveCount(1)
        ->and(g12AuditRows($fixture['tenant']->id, 'nurse_competency.granted'))->toHaveCount(1)
        ->and(g12AuditRows($fixture['tenant']->id, 'nurse_competency.revoked'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($fixture['tenant']->id)['ok'])->toBeTrue();

    $enforcementRow = g12AuditRows($fixture['tenant']->id, 'competency.enforcement_changed')->first();
    $context = json_decode($enforcementRow->context, true);
    expect($context['from_enforcement'])->toBe(Competency::ENFORCEMENT_SOFT)
        ->and($context['to_enforcement'])->toBe(Competency::ENFORCEMENT_HARD);
});

test('competency management requires the competency.manage permission', function () {
    $fixture = g12Fixture();
    $reception = g12User($fixture['tenant'], 'reception');
    $comp = g12Competency('wound_care');

    expect(fn () => app(CompetencyService::class)->create(['code' => 'x', 'name' => 'X', 'enforcement' => 'hard'], $reception))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(CompetencyService::class)->grant($fixture['resource'], $comp, [], $reception))
        ->toThrow(AuthorizationException::class);
});

test('the required competencies propagate from agreement service through materialization to the planned visit', function () {
    $fixture = g12Fixture();

    // Set required competencies on the agreement service and re-materialize the plan.
    AgreementService::query()
        ->where('id', $fixture['plan']->agreement_service_id)
        ->update(['required_competencies' => json_encode(['wound_care'])]);

    app(VisitPlanGenerator::class)->materialize(
        $fixture['plan']->refresh(),
        '2026-08-01',
        '2026-08-31',
    );

    $visit = PlannedVisit::query()->where('visit_plan_id', $fixture['plan']->id)->firstOrFail();

    expect($visit->required_competencies)->toBe(['wound_care']);
});
