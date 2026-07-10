<?php

namespace Modules\Comms\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Comms\Channels\EmailNotificationDriver;
use Modules\Comms\Contracts\NotificationChannelDriver;
use Modules\Comms\Jobs\SendNotificationJob;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Models\NotificationTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Throwable;

/**
 * The single notification engine: event -> template -> channel -> delivery
 * record. The CATEGORY comes from the TEMPLATE (D-G4) — a caller can assert
 * the category it expects, but can never override the template's own.
 *
 * Consent matrix (D-G4 / D-F7):
 *   marketing      -> patient: consent-gated (fail-closed, skip + no_consent)
 *   transactional  -> patient: consent-gated (fail-closed, skip + no_consent)
 *   legal          -> patient: NOT consent-gated (dunning, statutory notices)
 *   any            -> staff:   not consent-gated (internal recipients)
 */
class NotificationService
{
    /**
     * Consent scope required per channel for consent-gated categories.
     */
    private const CONSENT_SCOPES = [
        NotificationTemplate::CHANNEL_EMAIL => 'comms.email',
    ];

    /**
     * Built-in platform default templates (used when the tenant has not
     * customised one): key => [channel, category, subject, body].
     * The category is part of the TEMPLATE definition, never caller input.
     *
     * @var array<string, array{channel: string, category: string, subject: ?string, body: string}>
     */
    public const BUILT_IN = [
        'appointment.reminder' => [
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'category' => NotificationTemplate::CATEGORY_TRANSACTIONAL,
            'subject' => 'Appointment reminder',
            'body' => "This is a reminder for an upcoming appointment.\nAppointment time: {{starts_at}}",
        ],
        'billing.dunning' => [
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'category' => NotificationTemplate::CATEGORY_LEGAL,
            'subject' => 'Payment reminder: invoice {{invoice}}',
            'body' => "{{body}}\nInvoice: {{invoice}}\nReminder level: {{level}}",
        ],
    ];

    /**
     * @var array<string, NotificationChannelDriver>
     */
    private array $drivers;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ConsentService $consents,
        private readonly AuditService $audit,
        EmailNotificationDriver $email,
    ) {
        $this->drivers = [$email->channel() => $email];
    }

    /**
     * Queue a notification on Horizon. The queued job re-enters deliver()
     * with the same dedupe key, so a double-dispatch never double-sends.
     *
     * @param  array<string, mixed>  $context
     */
    public function queue(string $templateKey, Patient|User $recipient, array $context = [], ?string $expectedCategory = null): void
    {
        $tenantId = $this->tenantContext->id();

        if ($tenantId === null) {
            throw new InvalidArgumentException('Notifications require a tenant context.');
        }

        // Fail fast on a category mismatch before anything is queued.
        $this->resolveTemplate($templateKey, $expectedCategory);

        SendNotificationJob::dispatch(
            $tenantId,
            $templateKey,
            $recipient instanceof Patient ? NotificationDelivery::RECIPIENT_PATIENT : NotificationDelivery::RECIPIENT_STAFF,
            (string) $recipient->getKey(),
            $context,
            $expectedCategory,
        );
    }

    /**
     * Render + consent-gate + deliver + record, synchronously. Callers already
     * running inside workers (reminder jobs, dunning evaluation) use this
     * directly; queue() wraps it for ad-hoc senders.
     *
     * @param  array<string, mixed>  $context
     */
    public function send(
        string $templateKey,
        Patient|User $recipient,
        array $context = [],
        ?string $expectedCategory = null,
        ?Notification $mailable = null,
    ): NotificationDelivery {
        $template = $this->resolveTemplate($templateKey, $expectedCategory);
        $this->assertRecipientTenant($recipient);

        $rendered = $this->render($template, $context);
        $dedupeKey = $this->dedupeKey($template, $recipient, $context);

        $existing = NotificationDelivery::query()->where('dedupe_key', $dedupeKey)->first();
        if ($existing instanceof NotificationDelivery) {
            return $existing;
        }

        // Consent gate (D-G4): the template's category decides. Legal is never
        // consent-gated (D-F7); staff recipients are internal.
        if ($recipient instanceof Patient && $template['category'] !== NotificationTemplate::CATEGORY_LEGAL) {
            $scope = self::CONSENT_SCOPES[$template['channel']] ?? null;

            if ($scope === null || ! $this->consents->has($recipient, $scope)) {
                return $this->record($template, $recipient, $rendered, $dedupeKey, NotificationDelivery::STATUS_SKIPPED, skippedReason: 'no_consent');
            }
        }

        $driver = $this->drivers[$template['channel']] ?? null;
        if ($driver === null) {
            return $this->record($template, $recipient, $rendered, $dedupeKey, NotificationDelivery::STATUS_SKIPPED, skippedReason: 'channel_unavailable');
        }

        if (! $driver->canDeliver($recipient)) {
            return $this->record($template, $recipient, $rendered, $dedupeKey, NotificationDelivery::STATUS_SKIPPED, skippedReason: 'no_recipient_address');
        }

        try {
            $driver->deliver($recipient, $rendered['subject'], $rendered['body'], $mailable);
        } catch (Throwable $exception) {
            return $this->record($template, $recipient, $rendered, $dedupeKey, NotificationDelivery::STATUS_FAILED, error: $exception->getMessage());
        }

        return $this->record($template, $recipient, $rendered, $dedupeKey, NotificationDelivery::STATUS_SENT, sentAt: Carbon::now());
    }

    /**
     * Resolve the active template: the tenant's newest active version first,
     * else the built-in platform default. The returned category is the
     * TEMPLATE's — a caller-supplied expectation that mismatches is REJECTED,
     * never honored (a sender cannot relabel marketing as legal).
     *
     * @return array{key: string, channel: string, category: string, subject: ?string, body: string, version: int}
     */
    private function resolveTemplate(string $templateKey, ?string $expectedCategory): array
    {
        $row = NotificationTemplate::query()
            ->where('key', $templateKey)
            ->where('active', true)
            ->orderByDesc('version')
            ->first();

        if ($row instanceof NotificationTemplate) {
            $template = [
                'key' => $row->key,
                'channel' => $row->channel,
                'category' => $row->category,
                'subject' => $row->subject,
                'body' => $row->body,
                'version' => $row->version,
            ];
        } elseif (array_key_exists($templateKey, self::BUILT_IN)) {
            $template = [...self::BUILT_IN[$templateKey], 'key' => $templateKey, 'version' => 0];
        } else {
            throw new InvalidArgumentException("Notification template {$templateKey} is not defined.");
        }

        if ($expectedCategory !== null && $expectedCategory !== $template['category']) {
            throw new InvalidArgumentException(
                "Notification template {$templateKey} is {$template['category']}; callers cannot relabel it as {$expectedCategory}.",
            );
        }

        return $template;
    }

    /**
     * @param  array{key: string, channel: string, category: string, subject: ?string, body: string, version: int}  $template
     * @param  array<string, mixed>  $context
     * @return array{subject: ?string, body: string}
     */
    private function render(array $template, array $context): array
    {
        $replace = function (?string $text) use ($context): ?string {
            if ($text === null) {
                return null;
            }

            return (string) preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
                fn (array $matches): string => (string) ($context[$matches[1]] ?? ''),
                $text,
            );
        };

        return [
            'subject' => $replace($template['subject']),
            'body' => (string) $replace($template['body']),
        ];
    }

    /**
     * Idempotency: one delivery per (template, channel, recipient, context)
     * within the dedupe window. A Horizon retry or double-dispatch finds the
     * existing row and never sends twice; the unique DB index is the backstop.
     *
     * @param  array{key: string, channel: string, category: string, subject: ?string, body: string, version: int}  $template
     * @param  array<string, mixed>  $context
     */
    private function dedupeKey(array $template, Patient|User $recipient, array $context): string
    {
        ksort($context);

        return hash('sha256', implode('|', [
            $template['key'],
            $template['channel'],
            $recipient instanceof Patient ? 'patient' : 'staff',
            (string) $recipient->getKey(),
            (string) json_encode($context),
        ]));
    }

    /**
     * @param  array{key: string, channel: string, category: string, subject: ?string, body: string, version: int}  $template
     * @param  array{subject: ?string, body: string}  $rendered
     */
    private function record(
        array $template,
        Patient|User $recipient,
        array $rendered,
        string $dedupeKey,
        string $status,
        ?string $skippedReason = null,
        ?Carbon $sentAt = null,
        ?string $error = null,
    ): NotificationDelivery {
        try {
            $delivery = NotificationDelivery::query()->create([
                'template_key' => $template['key'],
                'template_version' => $template['version'],
                'channel' => $template['channel'],
                'category' => $template['category'],
                'recipient_type' => $recipient instanceof Patient ? NotificationDelivery::RECIPIENT_PATIENT : NotificationDelivery::RECIPIENT_STAFF,
                'recipient_id' => (string) $recipient->getKey(),
                'patient_id' => $recipient instanceof Patient ? $recipient->id : null,
                'rendered_subject' => $rendered['subject'],
                'rendered_body' => $rendered['body'],
                'status' => $status,
                'skipped_reason' => $skippedReason,
                'dedupe_key' => $dedupeKey,
                'sent_at' => $sentAt,
                'error' => $error,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent identical delivery won the race; return its record.
            return NotificationDelivery::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        $this->audit->record([
            'actor_type' => 'system',
            'action' => 'notification.'.$status,
            'patient_id' => $delivery->patient_id,
            'resource_type' => 'notification_delivery',
            'resource_id' => $delivery->id,
            'context' => array_filter([
                'template_key' => $template['key'],
                'template_version' => $template['version'],
                'channel' => $template['channel'],
                'category' => $template['category'],
                'recipient_type' => $delivery->recipient_type,
                'skipped_reason' => $skippedReason,
            ], fn (mixed $value): bool => $value !== null),
        ]);

        return $delivery;
    }

    private function assertRecipientTenant(Patient|User $recipient): void
    {
        $tenantId = $this->tenantContext->id();

        if ($recipient->tenant_id !== $tenantId) {
            throw CrossTenantReferenceException::forAttribute('recipient_id', (string) $recipient->getKey());
        }
    }
}
