<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Vital;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\WardRound;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\BedsideChartService;
use Modules\Hospital\Services\WardService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * QA-FIX.9c — `P9-C3`: a ward round, its note and its observations record the person who PERFORMED them,
 * not the stay's admitting clinician.
 *
 * THE FIXTURE IS THE POINT. Every one of P2-C1, P6-C2, P7-C1 and P9-C3 survived its own suite because the
 * fixture made the actor and the attributed person the same, so a substitution was invisible. Here they
 * are deliberately DIFFERENT people — Dr Keller admits, Nurse Studer rounds — and every assertion names
 * which one it expects.
 *
 * D-195 drew the line: "whose visit is this" is the encounter and legitimately keeps the booked clinician;
 * "who wrote this down" is the note. A ward round has NO booked clinician — there is no appointment behind
 * it — so all three answers are the rounder. What legitimately does not change is `stays.
 * admitting_clinician_id`, asserted below, because that is where responsibility for the ADMISSION lives.
 */

function wraTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Ward Attribution Hospital', 'slug' => 'wra-hosp', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function wraUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * A stay admitted BY Dr Keller, to be rounded ON by Nurse Studer — two different people, on purpose.
 *
 * @return array{stay: Stay, rounder: User, rounderProfile: StaffProfile, admitting: StaffProfile}
 */
function wraFixture(): array
{
    $tenant = wraTenant();
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);

    $admitter = wraUser($tenant, 'hospitalist');   // admission.manage
    $rounder = wraUser($tenant, 'hospitalist');    // encounter.manage + note.write — a DIFFERENT person
    $manager = wraUser($tenant, 'bed_manager');

    $admittingProfile = StaffProfile::query()->create([
        'first_name' => 'Martin', 'last_name' => 'Keller', 'display_name' => 'Dr. med. Martin Keller',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'user_id' => $admitter->id,
    ]);
    $rounderProfile = StaffProfile::query()->create([
        'first_name' => 'Lena', 'last_name' => 'Studer', 'display_name' => 'Lena Studer',
        'profession' => 'nurse', 'primary_branch_id' => $branch->id, 'user_id' => $rounder->id,
    ]);

    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward 1', 'W1');
    $bed = app(BedService::class)->create($manager, $ward, '1', 'general');
    $patient = app(PatientService::class)->create(['first_name' => 'Rolf', 'last_name' => 'Schmid', 'date_of_birth' => '1958-04-02', 'sex' => 'male']);
    $stay = app(AdmissionService::class)->admit($admitter, $patient, $bed, $admittingProfile, Stay::TYPE_ELECTIVE);

    return ['stay' => $stay, 'rounder' => $rounder, 'rounderProfile' => $rounderProfile, 'admitting' => $admittingProfile];
}

it('authors a ward-round note to the clinician who started the round, not the admitting clinician', function () {
    $fx = wraFixture();

    $result = app(BedsideChartService::class)->startRound($fx['rounder'], $fx['stay']);

    // BEFORE THE FIX this was the admitting clinician's id — the screen printed "Dr. med. Martin Keller"
    // above the words "You author this note".
    expect($result['note']->author_id)->toBe($fx['rounderProfile']->id)
        ->and($result['note']->author_id)->not->toBe($fx['admitting']->id);
});

it('records the round Encounter against the clinician who performed it', function () {
    $fx = wraFixture();

    $result = app(BedsideChartService::class)->startRound($fx['rounder'], $fx['stay']);
    $encounter = Encounter::query()->findOrFail($result['round']->encounter_id);

    // THE STUDY'S CONCLUSION, ASSERTED EXPLICITLY. D-195 keeps an APPOINTMENT's encounter on its booked
    // clinician; a ward round has no booking, so its practitioner is the person doing it.
    expect($encounter->practitioner_id)->toBe($fx['rounderProfile']->id)
        ->and($encounter->practitioner_id)->not->toBe($fx['admitting']->id);
});

it('records an observation against the person who took it', function () {
    $fx = wraFixture();
    app(BedsideChartService::class)->startRound($fx['rounder'], $fx['stay']);

    $vital = app(BedsideChartService::class)->recordVital($fx['rounder'], $fx['stay'], ['systolic' => 128, 'diastolic' => 76, 'heart_rate' => 84]);

    expect(Vital::query()->findOrFail($vital->id)->recorded_by)->toBe($fx['rounderProfile']->id)
        ->and($vital->recorded_by)->not->toBe($fx['admitting']->id);
});

it('leaves the stay\'s admitting clinician exactly where it was chosen', function () {
    $fx = wraFixture();

    app(BedsideChartService::class)->startRound($fx['rounder'], $fx['stay']);

    // WHAT LEGITIMATELY DOES NOT CHANGE. Responsibility for the ADMISSION is a different fact from who
    // performed a round, and it still lives on the stay, where the admit form chose it.
    expect($fx['stay']->fresh()->admitting_clinician_id)->toBe($fx['admitting']->id);
});

it('names the rounder on the chart the reader actually sees', function () {
    $fx = wraFixture();
    app(BedsideChartService::class)->startRound($fx['rounder'], $fx['stay']);

    // THE RENDERED ATTRIBUTION, not just the column (the QA-FIX.5a lesson): the chart page resolves the
    // round's practitioner and prints a name, and that name is the person who rounded.
    $page = $this->actingAs($fx['rounder'])
        ->get(route('hospital.admissions.chart', $fx['stay']->id))
        ->assertOk();

    $rounds = $page->viewData('page')['props']['rounds'];

    expect($rounds)->toHaveCount(1)
        ->and($rounds[0]['practitioner'])->toBe('Lena Studer')
        ->and($rounds[0]['practitioner'])->not->toBe('Dr. med. Martin Keller');
});

it('lets two different clinicians round on the same stay, which the substitution made impossible', function () {
    $fx = wraFixture();
    $second = wraUser(Tenant::query()->firstOrFail(), 'hospitalist');
    StaffProfile::query()->create([
        'first_name' => 'Petra', 'last_name' => 'Frei', 'display_name' => 'Petra Frei',
        'profession' => 'nurse', 'primary_branch_id' => Branch::query()->firstOrFail()->id, 'user_id' => $second->id,
    ]);

    app(BedsideChartService::class)->startRound($fx['rounder'], $fx['stay']);

    // THE OPERATIONAL HALF OF THE FINDING. Clinical's one-open-encounter-PER-PRACTITIONER invariant used
    // to collapse a whole stay to one concurrent round, because the practitioner was always the same
    // person — and the refusal named a third party. Two people can now round; the invariant is untouched.
    app(BedsideChartService::class)->startRound($second, $fx['stay']->fresh());

    expect(WardRound::query()->where('stay_id', $fx['stay']->id)->count())->toBe(2);
});

it('refuses to start a round for an actor with no staff profile, and writes nothing', function () {
    $fx = wraFixture();
    $ghost = wraUser(Tenant::query()->firstOrFail(), 'hospitalist'); // no StaffProfile

    // REFUSE, DO NOT GUESS (D-195, D-216). Before the fix this wrote a round attributed to the admitting
    // clinician, which is precisely the guess the rule forbids.
    expect(fn () => app(BedsideChartService::class)->startRound($ghost, $fx['stay']))
        ->toThrow(InvalidArgumentException::class, 'no staff profile');

    expect(WardRound::query()->count())->toBe(0)
        ->and(ClinicalNote::query()->count())->toBe(0)
        ->and(Encounter::query()->count())->toBe(0);
});

it('refuses to record an observation for an actor with no staff profile', function () {
    $fx = wraFixture();
    app(BedsideChartService::class)->startRound($fx['rounder'], $fx['stay']);
    $ghost = wraUser(Tenant::query()->firstOrFail(), 'hospitalist');

    expect(fn () => app(BedsideChartService::class)->recordVital($ghost, $fx['stay'], ['systolic' => 120]))
        ->toThrow(AdmissionException::class, 'no staff profile');

    expect(Vital::query()->count())->toBe(0);
});
