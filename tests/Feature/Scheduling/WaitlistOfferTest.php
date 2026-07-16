<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\Services\AuditService;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\WaitlistException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource as BookableResource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Models\WaitlistEntry;
use Modules\Scheduling\Models\WaitlistOffer;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\BookingService;
use Modules\Scheduling\Services\WaitlistOfferService;
use Modules\Scheduling\Services\WaitlistService;

uses(RefreshDatabase::class);

function woCtx(): TenantContext
{
    return app(TenantContext::class);
}

function woTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    woCtx()->set($tenant);

    return $tenant;
}

function woUser(Tenant $tenant, string $role = 'reception'): User
{
    woCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function woBranch(string $code = 'MAIN'): Branch
{
    return Branch::query()->create(['name' => $code.' Branch', 'code' => $code]);
}

function woService(): Service
{
    return Service::query()->create([
        'name' => 'Consult',
        'code' => 'CONS-'.strtoupper(substr(uniqid(), -5)),
        'default_duration_minutes' => 30,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_resource_types' => [BookableResource::TYPE_PRACTITIONER],
        'bookable_online' => true,
        'active' => true,
    ]);
}

function woResource(Branch $branch): BookableResource
{
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER,
        'name' => 'Practitioner',
        'branch_id' => $branch->id,
        'active' => true,
    ]);

    ResourceAvailability::query()->create([
        'resource_id' => $resource->id,
        'weekday' => 1, // Monday; 2026-07-13 is a Monday
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    return $resource;
}

function woPatient(array $overrides = []): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Wanda',
        'last_name' => 'Waitlist',
        'date_of_birth' => '1988-08-08',
        'sex' => 'female',
        ...$overrides,
    ]);
}

function woWaitlistEntry(Patient $patient, Service $service, Branch $branch, array $overrides = []): WaitlistEntry
{
    return app(WaitlistService::class)->create([
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'branch_id' => $branch->id,
        'flexible' => true,
        'priority' => 5,
        ...$overrides,
    ]);
}

function woEmail(Patient $patient): void
{
    PatientContact::query()->create([
        'patient_id' => $patient->id,
        'type' => PatientContact::TYPE_EMAIL,
        'value' => 'wanda.'.$patient->id.'@example.test',
        'is_primary' => true,
    ]);
}

function woGrantEmailConsent(Patient $patient, User $staff): void
{
    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'comms', 'version' => 1],
        ['title' => 'Email', 'body' => 'Email consent', 'scope_keys' => ['comms.email'], 'is_active' => true],
    );
    app(ConsentService::class)->grant($patient, 'comms', $patient->first_name.' '.$patient->last_name, $staff);
}

const WO_SLOT_START = '2026-07-13 10:00:00';
const WO_SLOT_END = '2026-07-13 10:30:00';

test('cancelling a slot surfaces matching waitlist entries, respecting service/branch/window and tenant', function () {
    Notification::fake();
    $tenant = woTenant('alpha');
    $actor = woUser($tenant);
    $branch = woBranch();
    $service = woService();
    $resource = woResource($branch);
    $bookedPatient = woPatient(['first_name' => 'Booked']);

    // A booked appointment that will free its slot on cancel.
    $appointment = app(BookingService::class)->book(
        $service->id, $bookedPatient->id, $branch->id, WO_SLOT_START, [$resource->id], $actor,
    );

    // Matching + non-matching waitlist entries.
    $match = woWaitlistEntry(woPatient(['first_name' => 'Match']), $service, $branch);
    $otherService = woService();
    $noMatch = woWaitlistEntry(woPatient(['first_name' => 'Nope']), $otherService, $branch);

    // Another tenant's matching-looking entry must never surface here.
    $beta = woTenant('beta');
    woUser($beta);
    $betaService = woService();
    $betaBranch = woBranch('BETA');
    woWaitlistEntry(woPatient(['first_name' => 'Beta']), $betaService, $betaBranch);

    woCtx()->set($tenant);
    app(AppointmentService::class)->cancel($appointment, $actor, 'Patient cancelled');

    $candidates = app(WaitlistOfferService::class)->candidates(
        $service->id, $branch->id, WO_SLOT_START, WO_SLOT_END, $actor,
    );

    expect($candidates->pluck('id')->all())->toContain($match->id)
        ->and($candidates->pluck('id')->all())->not->toContain($noMatch->id)
        ->and($candidates)->toHaveCount(1)
        ->and($appointment->refresh()->status)->toBe(Appointment::STATUS_CANCELLED);
});

test('an offer sends a consent-gated notification: skipped without consent, sent with it', function () {
    Notification::fake();
    $tenant = woTenant('alpha');
    $actor = woUser($tenant);
    $branch = woBranch();
    $service = woService();
    $resource = woResource($branch);

    // No consent, no email -> skipped fail-closed.
    $entry = woWaitlistEntry(woPatient(), $service, $branch);
    app(WaitlistOfferService::class)->offer($entry, $branch->id, WO_SLOT_START, WO_SLOT_END, [$resource->id], $actor);

    $skipped = NotificationDelivery::query()->where('template_key', 'waitlist.offer')->firstOrFail();
    expect($skipped->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($skipped->skipped_reason)->toBe('no_consent');

    // With email + comms.email consent -> sent.
    $consented = woPatient(['first_name' => 'Consented']);
    woEmail($consented);
    woGrantEmailConsent($consented, $actor);
    $entry2 = woWaitlistEntry($consented, $service, $branch);
    app(WaitlistOfferService::class)->offer($entry2, $branch->id, WO_SLOT_START, WO_SLOT_END, [$resource->id], $actor);

    $sent = NotificationDelivery::query()
        ->where('template_key', 'waitlist.offer')
        ->where('recipient_id', $consented->id)
        ->firstOrFail();
    expect($sent->status)->toBe(NotificationDelivery::STATUS_SENT);
});

test('accepting an offer books through BookingService and marks the waitlist entry booked', function () {
    Notification::fake();
    $tenant = woTenant('alpha');
    $actor = woUser($tenant);
    $branch = woBranch();
    $service = woService();
    $resource = woResource($branch);
    $patient = woPatient();
    $entry = woWaitlistEntry($patient, $service, $branch);

    $offer = app(WaitlistOfferService::class)->offer($entry, $branch->id, WO_SLOT_START, WO_SLOT_END, [$resource->id], $actor);
    expect(Appointment::query()->count())->toBe(0); // nothing booked yet

    $appointment = app(WaitlistOfferService::class)->accept($offer, $actor);

    expect($appointment->patient_id)->toBe($patient->id)
        ->and($appointment->starts_at->toDateTimeString())->toBe(WO_SLOT_START)
        ->and($appointment->status)->toBe(Appointment::STATUS_BOOKED)
        ->and($appointment->resourceLinks->pluck('resource_id')->all())->toBe([$resource->id])
        ->and($entry->refresh()->status)->toBe(WaitlistEntry::STATUS_BOOKED)
        ->and($offer->refresh()->status)->toBe(WaitlistOffer::STATUS_ACCEPTED)
        ->and($offer->booked_appointment_id)->toBe($appointment->id)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('declining an offer frees it so the next candidate can be offered the same slot', function () {
    Notification::fake();
    $tenant = woTenant('alpha');
    $actor = woUser($tenant);
    $branch = woBranch();
    $service = woService();
    $resource = woResource($branch);

    $entryA = woWaitlistEntry(woPatient(['first_name' => 'A']), $service, $branch, ['priority' => 9]);
    $entryB = woWaitlistEntry(woPatient(['first_name' => 'B']), $service, $branch, ['priority' => 1]);

    $offerA = app(WaitlistOfferService::class)->offer($entryA, $branch->id, WO_SLOT_START, WO_SLOT_END, [$resource->id], $actor);
    app(WaitlistOfferService::class)->decline($offerA, $actor);

    expect($offerA->refresh()->status)->toBe(WaitlistOffer::STATUS_DECLINED)
        ->and($entryA->refresh()->status)->toBe(WaitlistEntry::STATUS_WAITING) // stays waiting
        ->and(Appointment::query()->count())->toBe(0);

    // The same slot can now be offered to the next candidate.
    $offerB = app(WaitlistOfferService::class)->offer($entryB, $branch->id, WO_SLOT_START, WO_SLOT_END, [$resource->id], $actor);
    $appointment = app(WaitlistOfferService::class)->accept($offerB, $actor);

    expect($appointment->patient_id)->toBe($entryB->patient_id)
        ->and(Appointment::query()->count())->toBe(1);
});

test('an expired offer does not book', function () {
    Notification::fake();
    $tenant = woTenant('alpha');
    $actor = woUser($tenant);
    $branch = woBranch();
    $service = woService();
    $resource = woResource($branch);
    $entry = woWaitlistEntry(woPatient(), $service, $branch);

    $offer = app(WaitlistOfferService::class)->offer($entry, $branch->id, WO_SLOT_START, WO_SLOT_END, [$resource->id], $actor);

    // Force the TTL into the past.
    $offer->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

    expect(fn () => app(WaitlistOfferService::class)->accept($offer->refresh(), $actor))
        ->toThrow(WaitlistException::class);

    expect(Appointment::query()->count())->toBe(0)
        ->and($offer->refresh()->status)->toBe(WaitlistOffer::STATUS_EXPIRED)
        ->and($entry->refresh()->status)->toBe(WaitlistEntry::STATUS_WAITING);
});

test('the expire sweep releases timed-out offers so the slot can be re-offered', function () {
    Notification::fake();
    $tenant = woTenant('alpha');
    $actor = woUser($tenant);
    $branch = woBranch();
    $service = woService();
    $resource = woResource($branch);
    $entry = woWaitlistEntry(woPatient(), $service, $branch);

    $offer = app(WaitlistOfferService::class)->offer($entry, $branch->id, WO_SLOT_START, WO_SLOT_END, [$resource->id], $actor);
    $offer->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

    $count = app(WaitlistOfferService::class)->expireDue();

    expect($count)->toBe(1)
        ->and($offer->refresh()->status)->toBe(WaitlistOffer::STATUS_EXPIRED)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('offer, accept and decline are RBAC gated on appointment.manage', function () {
    Notification::fake();
    $tenant = woTenant('alpha');
    $actor = woUser($tenant);
    $branch = woBranch();
    $service = woService();
    $resource = woResource($branch);
    $entry = woWaitlistEntry(woPatient(), $service, $branch);

    // Billing role has no appointment.manage.
    $billing = woUser($tenant, 'billing');

    expect(fn () => app(WaitlistOfferService::class)->offer($entry, $branch->id, WO_SLOT_START, WO_SLOT_END, [$resource->id], $billing))
        ->toThrow(AuthorizationException::class);

    $offer = app(WaitlistOfferService::class)->offer($entry, $branch->id, WO_SLOT_START, WO_SLOT_END, [$resource->id], $actor);

    expect(fn () => app(WaitlistOfferService::class)->accept($offer, $billing))->toThrow(AuthorizationException::class)
        ->and(fn () => app(WaitlistOfferService::class)->decline($offer, $billing))->toThrow(AuthorizationException::class);
});

test('offers are tenant isolated and every lifecycle change is audited', function () {
    Notification::fake();
    $alpha = woTenant('alpha');
    $alphaActor = woUser($alpha);
    $alphaBranch = woBranch();
    $alphaService = woService();
    $alphaResource = woResource($alphaBranch);
    $entry = woWaitlistEntry(woPatient(), $alphaService, $alphaBranch);

    $offer = app(WaitlistOfferService::class)->offer($entry, $alphaBranch->id, WO_SLOT_START, WO_SLOT_END, [$alphaResource->id], $alphaActor);
    app(WaitlistOfferService::class)->accept($offer, $alphaActor);

    $offered = DB::select("SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'waitlist_offer.offered'", [$alpha->id]);
    $accepted = DB::select("SELECT * FROM audit_events WHERE tenant_id = ? AND action = 'waitlist_offer.accepted'", [$alpha->id]);

    expect($offered)->toHaveCount(1)
        ->and($accepted)->toHaveCount(1)
        ->and($offered[0]->patient_id)->toBe($entry->patient_id)
        ->and(app(AuditService::class)->verifyChain($alpha->id)['ok'])->toBeTrue();

    // A second tenant sees none of alpha's offers.
    woTenant('beta');
    expect(WaitlistOffer::query()->count())->toBe(0);
});
