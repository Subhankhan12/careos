<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\Services\AuditService;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Models\NotificationTemplate;
use Modules\Comms\Notifications\TemplateNotification;
use Modules\Comms\Services\NotificationService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

function g2Ctx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * @return array{tenant: Tenant, actor: User, patient: Patient}
 */
function g2Fixture(string $slug = 'alpha', bool $withEmail = true): array
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Care',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    g2Ctx()->set($tenant);

    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $actor->id,
        'role_id' => Role::query()->where('key', 'reception')->firstOrFail()->id,
    ]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Notify',
        'last_name' => 'Patient',
        'date_of_birth' => '1992-03-03',
        'sex' => 'female',
    ]);

    if ($withEmail) {
        PatientContact::query()->create([
            'patient_id' => $patient->id,
            'type' => PatientContact::TYPE_EMAIL,
            'value' => $slug.'.notify@example.test',
            'is_primary' => true,
        ]);
    }

    return compact('tenant', 'actor', 'patient');
}

function g2GrantEmailConsent(Patient $patient, User $staff): void
{
    ConsentTemplate::query()->firstOrCreate(
        ['key' => 'comms', 'version' => 1],
        [
            'title' => 'Email communications',
            'body' => 'Email communications consent',
            'scope_keys' => ['comms.email'],
            'is_active' => true,
        ],
    );
    app(ConsentService::class)->grant($patient, 'comms', $patient->first_name.' '.$patient->last_name, $staff);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g2Template(string $key, string $category, array $overrides = []): NotificationTemplate
{
    return NotificationTemplate::query()->create([
        'key' => $key,
        'channel' => NotificationTemplate::CHANNEL_EMAIL,
        'locale' => 'en',
        'subject' => 'Subject for {{name}}',
        'body' => 'Hello {{name}}, your balance is {{balance}}.',
        'category' => $category,
        'active' => true,
        'version' => 1,
        ...$overrides,
    ]);
}

function g2AuditRows(string $tenantId, string $action): Collection
{
    return collect(DB::select(
        'SELECT * FROM audit_events WHERE tenant_id <=> ? AND action = ? ORDER BY occurred_at ASC',
        [$tenantId, $action],
    ));
}

test('the category comes from the template and a caller cannot relabel it', function () {
    Notification::fake();
    $fx = g2Fixture();
    g2Template('marketing.newsletter', NotificationTemplate::CATEGORY_MARKETING);

    // A caller trying to pass the consent gate by claiming "legal" is REJECTED.
    expect(fn () => app(NotificationService::class)->send(
        'marketing.newsletter',
        $fx['patient'],
        ['name' => 'Ada'],
        NotificationTemplate::CATEGORY_LEGAL,
    ))->toThrow(InvalidArgumentException::class, 'cannot relabel');

    expect(NotificationDelivery::query()->count())->toBe(0);
    Notification::assertNothingSent();

    // Without a claimed category the template's own category governs: the
    // marketing message to a patient without consent is skipped fail-closed.
    $delivery = app(NotificationService::class)->send('marketing.newsletter', $fx['patient'], ['name' => 'Ada']);

    expect($delivery->category)->toBe(NotificationTemplate::CATEGORY_MARKETING)
        ->and($delivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($delivery->skipped_reason)->toBe('no_consent');
});

test('marketing and transactional to a patient without consent are skipped and legal always sends', function () {
    Notification::fake();
    $fx = g2Fixture();
    g2Template('marketing.newsletter', NotificationTemplate::CATEGORY_MARKETING);
    g2Template('booking.confirmation', NotificationTemplate::CATEGORY_TRANSACTIONAL);
    g2Template('legal.notice', NotificationTemplate::CATEGORY_LEGAL);
    $service = app(NotificationService::class);

    $marketing = $service->send('marketing.newsletter', $fx['patient'], ['name' => 'A']);
    $transactional = $service->send('booking.confirmation', $fx['patient'], ['name' => 'B']);
    $legal = $service->send('legal.notice', $fx['patient'], ['name' => 'C']);

    expect($marketing->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($marketing->skipped_reason)->toBe('no_consent')
        ->and($transactional->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($transactional->skipped_reason)->toBe('no_consent')
        ->and($legal->status)->toBe(NotificationDelivery::STATUS_SENT);
    Notification::assertCount(1);

    // With comms.email consent the gated categories send.
    g2GrantEmailConsent($fx['patient'], $fx['actor']);

    $marketingSent = $service->send('marketing.newsletter', $fx['patient'], ['name' => 'A2']);
    $transactionalSent = $service->send('booking.confirmation', $fx['patient'], ['name' => 'B2']);

    expect($marketingSent->status)->toBe(NotificationDelivery::STATUS_SENT)
        ->and($transactionalSent->status)->toBe(NotificationDelivery::STATUS_SENT);
    Notification::assertCount(3);
});

test('staff recipients are not consent-gated', function () {
    Notification::fake();
    $fx = g2Fixture();
    g2Template('staff.digest', NotificationTemplate::CATEGORY_TRANSACTIONAL);

    $delivery = app(NotificationService::class)->send('staff.digest', $fx['actor'], ['name' => 'Team']);

    expect($delivery->status)->toBe(NotificationDelivery::STATUS_SENT)
        ->and($delivery->recipient_type)->toBe(NotificationDelivery::RECIPIENT_STAFF)
        ->and($delivery->patient_id)->toBeNull();
    Notification::assertCount(1);
});

test('deliveries are append-only at the database level', function () {
    Notification::fake();
    $fx = g2Fixture();
    g2Template('legal.notice', NotificationTemplate::CATEGORY_LEGAL);
    $delivery = app(NotificationService::class)->send('legal.notice', $fx['patient'], ['name' => 'X']);

    expect(fn () => DB::update("UPDATE notification_deliveries SET status = 'failed' WHERE id = ?", [$delivery->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::delete('DELETE FROM notification_deliveries WHERE id = ?', [$delivery->id]))
        ->toThrow(QueryException::class)
        ->and(fn () => $delivery->forceFill(['status' => 'failed'])->save())
        ->toThrow(LogicException::class);
});

test('the rendered body is a snapshot that later template edits never alter', function () {
    Notification::fake();
    $fx = g2Fixture();
    g2GrantEmailConsent($fx['patient'], $fx['actor']);
    $template = g2Template('booking.confirmation', NotificationTemplate::CATEGORY_TRANSACTIONAL);

    $delivery = app(NotificationService::class)->send('booking.confirmation', $fx['patient'], [
        'name' => 'Ada',
        'balance' => '1200',
    ]);

    expect($delivery->rendered_body)->toBe('Hello Ada, your balance is 1200.')
        ->and($delivery->rendered_subject)->toBe('Subject for Ada')
        ->and($delivery->template_version)->toBe(1);

    // A NEW template version supersedes; the old delivery row is untouched.
    NotificationTemplate::query()->create([
        'key' => 'booking.confirmation',
        'channel' => NotificationTemplate::CHANNEL_EMAIL,
        'subject' => 'Completely new subject',
        'body' => 'Completely new body {{name}}.',
        'category' => NotificationTemplate::CATEGORY_TRANSACTIONAL,
        'version' => 2,
    ]);
    $template->forceFill(['body' => 'Mutated v1 body'])->save();

    expect($delivery->refresh()->rendered_body)->toBe('Hello Ada, your balance is 1200.');

    $second = app(NotificationService::class)->send('booking.confirmation', $fx['patient'], [
        'name' => 'Ada',
        'balance' => '900',
    ]);

    expect($second->template_version)->toBe(2)
        ->and($second->rendered_body)->toBe('Completely new body Ada.');
});

test('the dedupe key prevents double-send on retry', function () {
    Notification::fake();
    $fx = g2Fixture();
    g2Template('legal.notice', NotificationTemplate::CATEGORY_LEGAL);
    $service = app(NotificationService::class);
    $context = ['name' => 'Once', 'balance' => '5'];

    $first = $service->send('legal.notice', $fx['patient'], $context);
    $retry = $service->send('legal.notice', $fx['patient'], $context);

    expect($retry->id)->toBe($first->id)
        ->and(NotificationDelivery::query()->count())->toBe(1);
    Notification::assertCount(1);

    // Different context is a different logical notification.
    $service->send('legal.notice', $fx['patient'], ['name' => 'Twice', 'balance' => '6']);
    Notification::assertCount(2);
});

test('queueing dispatches through Horizon and stays idempotent', function () {
    // CI exports QUEUE_CONNECTION=redis at the OS level (phpunit <env> does not
    // override it), which would park the job unprocessed on Redis. This test
    // asserts dispatch idempotency, not queue infrastructure (C.0 proves the
    // real Redis round trip), so pin the sync driver explicitly.
    config()->set('queue.default', 'sync');
    Notification::fake();
    $fx = g2Fixture();
    g2Template('legal.notice', NotificationTemplate::CATEGORY_LEGAL);
    $service = app(NotificationService::class);
    $context = ['name' => 'Queued'];

    $service->queue('legal.notice', $fx['patient'], $context); // sync queue in tests
    $service->queue('legal.notice', $fx['patient'], $context);

    expect(NotificationDelivery::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->firstOrFail()->status)->toBe(NotificationDelivery::STATUS_SENT);
    Notification::assertCount(1);
});

test('deliveries are tenant isolated audited and fail closed', function () {
    Notification::fake();
    $alpha = g2Fixture('alpha');
    g2Template('legal.notice', NotificationTemplate::CATEGORY_LEGAL);
    $delivery = app(NotificationService::class)->send('legal.notice', $alpha['patient'], ['name' => 'A']);

    expect(g2AuditRows($alpha['tenant']->id, 'notification.sent'))->toHaveCount(1)
        ->and(app(AuditService::class)->verifyChain($alpha['tenant']->id)['ok'])->toBeTrue();

    $beta = g2Fixture('beta');

    expect(NotificationDelivery::query()->whereKey($delivery->id)->exists())->toBeFalse()
        ->and(fn () => app(NotificationService::class)->send('legal.notice', $alpha['patient'], ['name' => 'X']))
        ->toThrow(InvalidArgumentException::class); // beta has no such template; alpha's is invisible

    g2Ctx()->forget();

    expect(fn () => NotificationDelivery::query()->count())->toThrow(TenantContextMissingException::class)
        ->and(fn () => NotificationTemplate::query()->count())->toThrow(TenantContextMissingException::class);
});

test('a patient without an email address is skipped with a reason', function () {
    Notification::fake();
    $fx = g2Fixture('noaddr', withEmail: false);
    g2Template('legal.notice', NotificationTemplate::CATEGORY_LEGAL);

    $delivery = app(NotificationService::class)->send('legal.notice', $fx['patient'], ['name' => 'N']);

    expect($delivery->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($delivery->skipped_reason)->toBe('no_recipient_address');
    Notification::assertNothingSent();
});

test('built-in templates back the migrated reminder and dunning senders', function () {
    Notification::fake();
    $fx = g2Fixture();
    $service = app(NotificationService::class);

    // billing.dunning is LEGAL: no consent needed, snapshot recorded.
    $dunning = $service->send('billing.dunning', $fx['patient'], [
        'body' => 'Please pay.',
        'invoice' => 'INV-9',
        'level' => 1,
    ]);

    expect($dunning->category)->toBe(NotificationTemplate::CATEGORY_LEGAL)
        ->and($dunning->status)->toBe(NotificationDelivery::STATUS_SENT)
        ->and($dunning->template_version)->toBe(0)
        ->and($dunning->rendered_body)->toContain('Please pay.')
        ->and($dunning->rendered_body)->toContain('INV-9');

    // appointment.reminder is TRANSACTIONAL: consent-gated fail-closed.
    $reminder = $service->send('appointment.reminder', $fx['patient'], ['starts_at' => '2026-08-01 09:00:00']);

    expect($reminder->category)->toBe(NotificationTemplate::CATEGORY_TRANSACTIONAL)
        ->and($reminder->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($reminder->skipped_reason)->toBe('no_consent');

    // Sending TemplateNotification only for the engine-rendered dunning send.
    Notification::assertSentOnDemand(TemplateNotification::class);
});
