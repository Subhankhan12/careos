<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;

/**
 * "Scheduling" settings card (SETTINGS.P3) — presentation over settings that ALREADY exist and
 * are ALREADY read by the schedulers. It writes ONLY through {@see SettingsService} at the exact
 * keys the readers consume, so a saved value is actually honored:
 *
 *  - `scheduling.portal.cancel_min_hours` (default 24) is read by
 *    Scheduling\PortalAppointmentController::cancelMinHours() to gate portal self-cancellation.
 *  - `nursing.dispatch.average_speed_kmh` (default 40) is read by Nursing\AssignmentValidator and
 *    the dispatch proposal engine to validate travel time.
 *
 * The "default appointment buffer" is deliberately NOT an editable global here: buffers exist ONLY
 * per Service (`Service.buffer_before/after_minutes`) and there is no global-default setting the
 * scheduler reads. Rendering an editable global would persist a value nothing consumes — dishonest
 * — so the buffer is shown read-only as a per-service pointer (SETTINGS.P3 decision (a)).
 *
 * App-layer because the change is audited and Platform may not depend on Audit. Gated on
 * `admin.manage`; tenant-scoped.
 */
class SchedulingSettingsController
{
    private const KEY_CANCEL = 'scheduling.portal.cancel_min_hours';

    private const KEY_SPEED = 'nursing.dispatch.average_speed_kmh';

    /** Mirror the readers' inline defaults (PortalAppointmentController / AssignmentValidator). */
    private const DEFAULT_CANCEL = 24;

    private const DEFAULT_SPEED = 40;

    public function index(Request $request, SettingsService $settings): Response
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Admin/Scheduling', [
            'scheduling' => [
                'cancelMinHours' => (int) $settings->get(self::KEY_CANCEL, self::DEFAULT_CANCEL),
                'travelSpeedKmh' => (int) $settings->get(self::KEY_SPEED, self::DEFAULT_SPEED),
            ],
            'bounds' => [
                'cancelMinHours' => ['min' => 0, 'max' => 168],
                'travelSpeedKmh' => ['min' => 1, 'max' => 200],
            ],
            'updateUrl' => route('admin.scheduling.update'),
            'settingsUrl' => route('settings.index'),
        ]);
    }

    public function update(Request $request, SettingsService $settings, AuditService $audit): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $data = $request->validate([
            // Sane bounds: cancel window 0–168h (up to a week); travel speed 1–200 km/h.
            'cancel_min_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'travel_speed_kmh' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $before = [
            'cancelMinHours' => (int) $settings->get(self::KEY_CANCEL, self::DEFAULT_CANCEL),
            'travelSpeedKmh' => (int) $settings->get(self::KEY_SPEED, self::DEFAULT_SPEED),
        ];

        // Write ONLY the existing keys the schedulers already read — the value is honored, not ignored.
        $settings->set(self::KEY_CANCEL, $data['cancel_min_hours'], 'int');
        $settings->set(self::KEY_SPEED, $data['travel_speed_kmh'], 'int');

        $after = ['cancelMinHours' => $data['cancel_min_hours'], 'travelSpeedKmh' => $data['travel_speed_kmh']];

        if ($before !== $after) {
            $audit->record([
                'action' => 'settings.scheduling_changed',
                'resource_type' => 'settings',
                'context' => ['before' => $before, 'after' => $after],
            ]);
        }

        return redirect()->route('admin.scheduling.index')->with('status', 'saved');
    }
}
