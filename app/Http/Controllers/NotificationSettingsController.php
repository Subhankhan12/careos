<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Audit\Services\AuditService;
use Modules\Comms\Services\NotificationPreferenceService;
use Modules\Comms\Services\NotificationService;
use Modules\Platform\Models\User;

/**
 * "Notifications" settings card (SETTINGS.P5) — presentation over the per-event EMAIL preference
 * store the {@see NotificationPreferenceService} owns and {@see NotificationService}
 * consults. It manages ONLY email preferences for the manageable (non-legal) events:
 *
 *  - Toggling an event's email OFF actually suppresses that email (the send path checks the pref).
 *  - SMS is an INERT SEAM: no SMS provider is wired, so there is no SMS preference to write and the
 *    card renders the SMS column disabled. This controller never accepts or stores an SMS value.
 *  - The clinician-attention flag is NOT a notification preference: it is the Inbox agent's safety
 *    hand-off (it sets `clinician_attention_at` on a thread when it refuses a clinical question —
 *    the electric fence). It is locked-on and there is no key here that can disable it.
 *
 * App-layer because the change is audited and Platform/Comms may not depend on Audit from a
 * controller composition. Gated on `admin.manage`; tenant-scoped.
 */
class NotificationSettingsController
{
    public function index(Request $request, NotificationPreferenceService $preferences): Response
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Admin/Notifications', [
            'events' => $preferences->manageable(),
            // SMS is a seam only — present in the UI, disabled, and consulted by nothing.
            'smsAvailable' => false,
            'updateUrl' => route('admin.notifications.update'),
            'settingsUrl' => route('settings.index'),
        ]);
    }

    public function update(Request $request, NotificationPreferenceService $preferences, AuditService $audit): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $data = $request->validate([
            'email' => ['required', 'array'],
            // Only the manageable (non-legal) events may be toggled. A legal event or the
            // clinician-attention flag has no key here — there is no path to suppress them.
            'email.*' => ['required', 'boolean'],
        ]);

        $changes = [];

        foreach ($data['email'] as $eventKey => $enabled) {
            if (! in_array((string) $eventKey, NotificationPreferenceService::MANAGEABLE, true)) {
                continue; // ignore anything that isn't a manageable email event (e.g. sms/attention keys)
            }

            $before = $preferences->emailEnabled((string) $eventKey);
            $preferences->setEmail((string) $eventKey, (bool) $enabled);

            if ($before !== (bool) $enabled) {
                $changes[] = ['event' => (string) $eventKey, 'email' => (bool) $enabled];
            }
        }

        if ($changes !== []) {
            $audit->record([
                'action' => 'notification.preferences_changed',
                'resource_type' => 'notification_preferences',
                'context' => ['changes' => $changes],
            ]);
        }

        return redirect()->route('admin.notifications.index')->with('status', 'saved');
    }
}
