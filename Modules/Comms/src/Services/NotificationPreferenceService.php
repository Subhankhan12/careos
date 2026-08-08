<?php

namespace Modules\Comms\Services;

use InvalidArgumentException;
use Modules\Comms\Models\NotificationPreference;

/**
 * Per-event EMAIL notification preferences (SETTINGS.P5). A thin tenant-scoped store consulted by
 * {@see NotificationService}: an event whose email preference is OFF is not emailed. Only the
 * built-in NON-LEGAL email events are manageable — legal notices (dunning, statutory) can never be
 * suppressed, and there is no SMS preference because no SMS channel is wired.
 *
 * Default is ON: a missing row means the event is emailed. Preferences store overrides only.
 */
class NotificationPreferenceService
{
    /**
     * The events an admin may manage, in display order. Driven by the real built-in non-legal email
     * templates ({@see NotificationService::BUILT_IN}); each maps to an i18n label on the client.
     *
     * @var list<string>
     */
    public const MANAGEABLE = [
        'appointment.reminder',
        'waitlist.offer',
        'telehealth.invite',
    ];

    /**
     * Whether the given event is emailed (default ON when no override row exists). Works for ANY
     * event key so the send path can consult it uniformly; only MANAGEABLE keys are ever written.
     */
    public function emailEnabled(string $eventKey): bool
    {
        $row = NotificationPreference::query()->where('event_key', $eventKey)->first();

        return $row === null ? true : (bool) $row->email_enabled;
    }

    /**
     * The manageable events with their current email state, in display order.
     *
     * @return list<array{key: string, emailEnabled: bool}>
     */
    public function manageable(): array
    {
        $rows = NotificationPreference::query()
            ->whereIn('event_key', self::MANAGEABLE)
            ->pluck('email_enabled', 'event_key');

        return array_map(
            fn (string $key): array => [
                'key' => $key,
                'emailEnabled' => $rows->has($key) ? (bool) $rows->get($key) : true,
            ],
            self::MANAGEABLE,
        );
    }

    /**
     * Set the email preference for a MANAGEABLE event. A non-manageable key is refused so a caller
     * can never write a preference for a legal event (which must always send) or an unknown key.
     */
    public function setEmail(string $eventKey, bool $enabled): void
    {
        if (! in_array($eventKey, self::MANAGEABLE, true)) {
            throw new InvalidArgumentException("Notification event {$eventKey} is not manageable.");
        }

        NotificationPreference::query()->updateOrCreate(
            ['event_key' => $eventKey],
            ['email_enabled' => $enabled],
        );
    }
}
