<?php

use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\AiCore\Models\AgentAction;
use Modules\Billing\Models\Invoice;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Models\Encounter;
use Modules\Comms\Models\TelehealthSession;
use Modules\Comms\Models\Thread;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;

uses(RefreshDatabase::class);

/**
 * ADVERSARIAL. The victim is the P.1 demo tenant — a real clinic's worth of data
 * created through the real services, so these are not straw resources. The
 * attacker is a SEPARATE tenant's org_admin: the most privileged tenant role
 * there is, holding every permission in the catalog. If tenancy leaks anywhere,
 * this is the user who finds it.
 *
 * Every attempt must fail CLOSED (403/404) and no victim data may appear in the
 * response body.
 */
function p3Ctx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * @return array{victim: Tenant, attacker: User, ids: array<string, string>, secrets: list<string>}
 */
function p3Scene(): array
{
    Storage::fake('local');
    (new DemoClinicSeeder)->run();

    $victim = Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();
    p3Ctx()->set($victim);

    $patient = Patient::query()->orderBy('id')->firstOrFail();
    $note = ClinicalNote::query()->where('status', ClinicalNote::STATUS_SIGNED)->firstOrFail();
    $document = Document::query()->firstOrFail();
    $invoice = Invoice::query()->whereNotNull('number')->firstOrFail();
    $thread = Thread::query()->where('type', Thread::TYPE_PATIENT)->firstOrFail();
    $appointment = Appointment::query()->where('status', Appointment::STATUS_BOOKED)->firstOrFail();
    $plannedVisit = PlannedVisit::query()->where('status', PlannedVisit::STATUS_ASSIGNED)->firstOrFail();
    $encounter = Encounter::query()->firstOrFail();
    $agentAction = AgentAction::query()->firstOrFail();
    $nurseResource = BookableResource::query()->where('type', BookableResource::TYPE_PRACTITIONER)->firstOrFail();

    // The demo seeder does not create a telehealth session; the gate names one.
    $session = TelehealthSession::query()->create([
        'appointment_id' => null,
        'encounter_id' => null,
        'patient_id' => $patient->id,
        'practitioner_id' => StaffProfile::query()->where('profession', 'doctor')->firstOrFail()->id,
        'provider' => 'fake',
        'room_reference' => 'demo-room-secret-ref',
        'status' => 'created',
    ]);

    $ids = [
        'patient' => $patient->id,
        'note' => $note->id,
        'document' => $document->id,
        'invoice' => $invoice->id,
        'thread' => $thread->id,
        'appointment' => $appointment->id,
        'planned_visit' => $plannedVisit->id,
        'encounter' => $encounter->id,
        'agent_action' => $agentAction->id,
        'nurse_resource' => $nurseResource->id,
        'telehealth_session' => $session->id,
    ];

    // Strings that must NEVER appear in a response served to the attacker.
    $secrets = [
        $patient->last_name,
        $patient->mrn,
        $note->subjective,
        $document->title,
        $thread->subject,
        $session->room_reference,
    ];

    // The attacker: a different tenant, maximum privilege inside it.
    $attackerTenant = Tenant::query()->create([
        'name' => 'Attacker Clinic',
        'slug' => 'attacker-clinic',
        'region' => 'eu',
        'status' => 'active',
    ]);
    p3Ctx()->set($attackerTenant);

    $attacker = User::factory()->forTenant($attackerTenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $attacker->id,
        'role_id' => Role::query()
            ->where('tenant_id', $attackerTenant->id)
            ->where('key', 'org_admin')
            ->firstOrFail()->id,
        'branch_id' => null,
    ]);

    p3Ctx()->forget();

    return compact('victim', 'attacker', 'ids', 'secrets');
}

/**
 * @param  list<string>  $secrets
 */
function p3AssertFailsClosed(TestResponse $response, array $secrets, string $label): void
{
    expect($response->status())->toBeIn([403, 404], "{$label} did not fail closed");

    $body = $response->getContent();

    foreach ($secrets as $secret) {
        if (trim((string) $secret) === '') {
            continue;
        }

        expect($body)->not->toContain($secret, "{$label} leaked victim data");
    }
}

test('a tenant A org_admin cannot reach any tenant B resource by id, across every module', function () {
    $scene = p3Scene();
    $ids = $scene['ids'];
    $secrets = $scene['secrets'];

    $this->actingAs($scene['attacker']);

    // [label, method, uri, payload]
    $attempts = [
        // --- Patients
        ['patients.show', 'GET', "/patients/{$ids['patient']}", []],
        ['patients.consents.grant', 'POST', "/patients/{$ids['patient']}/consents", ['template_key' => 'portal', 'signature' => 'X']],

        // --- Clinical
        ['clinical.chart', 'GET', "/clinical/chart/{$ids['patient']}", []],
        ['clinical.encounters.show', 'GET', "/clinical/encounters/{$ids['encounter']}", []],
        ['clinical.notes.show', 'GET', "/clinical/notes/{$ids['note']}", []],
        ['clinical.notes.edit', 'GET', "/clinical/notes/{$ids['note']}/edit", []],
        ['clinical.notes.update', 'PATCH', "/clinical/notes/{$ids['note']}", ['subjective' => 'injected']],
        ['clinical.notes.sign', 'POST', "/clinical/notes/{$ids['note']}/sign", []],
        ['clinical.notes.amend', 'POST', "/clinical/notes/{$ids['note']}/amend", ['reason' => 'x', 'subjective' => 'injected']],
        ['clinical.notes.store', 'POST', "/clinical/encounters/{$ids['encounter']}/notes", ['subjective' => 'injected']],
        ['clinical.documents.download', 'GET', "/clinical/documents/{$ids['document']}", []],
        ['clinical.documents.share', 'POST', "/clinical/documents/{$ids['document']}/share", []],
        ['clinical.documents.delete', 'DELETE', "/clinical/documents/{$ids['document']}", []],
        // A VALID upload payload on purpose: a request rejected by validation
        // would prove nothing about tenancy. This one is well-formed and must
        // be stopped by the tenant guard alone.
        ['clinical.documents.upload', 'POST', "/clinical/patients/{$ids['patient']}/documents", [
            'category' => 'letter',
            'title' => 'Injected',
            'file' => UploadedFile::fake()->create('injected.pdf', 8, 'application/pdf'),
        ]],

        // --- Scheduling
        ['day-board.transition', 'POST', '/scheduling/day-board/transition', ['appointment_id' => $ids['appointment'], 'action' => 'cancel', 'reason' => 'injected']],
        ['day-board.open-encounter', 'POST', '/scheduling/day-board/open-encounter', ['appointment_id' => $ids['appointment']]],

        // --- Nursing
        ['dispatch.assign', 'POST', '/nursing/dispatch/assign', ['planned_visit_id' => $ids['planned_visit'], 'resource_id' => $ids['nurse_resource']]],
        ['dispatch.unassign', 'POST', '/nursing/dispatch/unassign', ['planned_visit_id' => $ids['planned_visit']]],

        // --- Comms
        ['inbox.reply', 'POST', '/comms/inbox/reply', ['thread_id' => $ids['thread'], 'body' => 'injected']],
        ['inbox.status', 'POST', '/comms/inbox/status', ['thread_id' => $ids['thread'], 'action' => 'close']],
        ['inbox.assign', 'POST', '/comms/inbox/assign', ['thread_id' => $ids['thread'], 'assignee_id' => null]],

        // --- AiCore surfaces
        ['inbox.ai-draft', 'POST', '/comms/inbox/ai-draft', ['thread_id' => $ids['thread']]],
        ['clinical.summary.draft', 'POST', "/clinical/chart/{$ids['patient']}/summary-draft", ['request' => 'Summarise the record']],
    ];

    foreach ($attempts as [$label, $method, $uri, $payload]) {
        p3AssertFailsClosed($this->call($method, $uri, $payload), $secrets, $label);
    }

    // Pins the sweep's breadth: deleting an attempt has to be deliberate.
    expect($attempts)->toHaveCount(23);
});

test('the attacker sees none of tenant B on the index and board surfaces they are allowed to load', function () {
    $scene = p3Scene();

    $this->actingAs($scene['attacker']);

    // These are the attacker's OWN listing surfaces. Whatever status they
    // return for an empty tenant (200, or 404 where the page needs a branch the
    // attacker has not got), the thing that matters is the same: not one row of
    // the victim's may bleed into them.
    foreach ([
        ['patients.index', '/patients'],
        ['scheduling.day-board', '/scheduling/day-board'],
        ['comms.inbox', '/comms/inbox'],
        ['nursing.dispatch', '/nursing/dispatch'],
    ] as [$label, $uri]) {
        $response = $this->get($uri);

        expect($response->status())->not->toBe(500, "{$label} errored");

        foreach ($scene['secrets'] as $secret) {
            if (trim((string) $secret) === '') {
                continue;
            }

            expect($response->getContent())->not->toContain($secret, "{$label} leaked victim data into another tenant's page");
        }
    }
});

test('a portal patient cannot reach another tenant, another patient, or any staff route', function () {
    $scene = p3Scene();
    $ids = $scene['ids'];

    // A portal account belonging to the VICTIM tenant.
    p3Ctx()->set($scene['victim']);
    $victimAccount = PortalAccount::query()->where('status', PortalAccount::STATUS_ACTIVE)->orderBy('id')->firstOrFail();
    $otherAccount = PortalAccount::query()
        ->where('status', PortalAccount::STATUS_ACTIVE)
        ->whereKeyNot($victimAccount->id)
        ->firstOrFail();

    $otherPatientDocument = Document::query()->where('patient_id', $otherAccount->patient_id)->first();
    p3Ctx()->forget();

    $this->actingAs($victimAccount, 'patient');

    // A staff route is unreachable on the patient guard, whatever it is.
    foreach ([
        ['patients.index', 'GET', '/patients'],
        ['day-board', 'GET', '/scheduling/day-board'],
        ['inbox', 'GET', '/comms/inbox'],
        ['chart', 'GET', "/clinical/chart/{$ids['patient']}"],
        ['dispatch', 'GET', '/nursing/dispatch'],
    ] as [$label, $method, $uri]) {
        $response = $this->call($method, $uri);

        // The staff guard must never serve a patient session.
        expect($response->status())->not->toBe(200, "{$label} served a portal patient");
    }

    // Another patient's document in the SAME tenant is not reachable either.
    if ($otherPatientDocument instanceof Document) {
        $response = $this->get("/portal/documents/{$otherPatientDocument->id}");
        expect($response->status())->toBeIn([403, 404]);
    }
});

test('a staff user cannot reach portal routes', function () {
    $scene = p3Scene();

    $this->actingAs($scene['attacker']);

    foreach (['/portal', '/portal/appointments', '/portal/documents', '/portal/invoices', '/portal/messages'] as $uri) {
        $response = $this->get($uri);

        // The portal guard is a separate guard; a staff session is not a patient.
        expect($response->status())->not->toBe(200, "{$uri} served a staff user");
    }
});
