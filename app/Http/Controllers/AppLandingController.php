<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Reporting\Services\MetricsService;
use Modules\Scheduling\Models\Appointment;

/**
 * Staff landing ("today at a glance"). Every figure is a call into the EXISTING
 * tested MetricsService (P0P.G14) for the CURRENT tenant, scoped to today — no new
 * metric or query is invented here. Operational figures are read ONLY when the
 * actor holds `reporting.view` and financial ONLY with `billing.view` (the service
 * would otherwise throw); a role with neither simply gets the shell + quick links,
 * fail-closed like the rest of the app. Money stays integer minor units.
 */
class AppLandingController
{
    public function __invoke(Request $request, MetricsService $metrics, SettingsService $settings): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $today = now()->toDateString();

        // OPERATIONAL — today, tenant-wide. Only with reporting.view.
        $operational = null;
        if (Gate::allows('reporting.view')) {
            $appointments = $metrics->appointmentsInRange($actor, $today, $today);
            $noShows = $metrics->noShows($actor, $today, $today);

            $operational = [
                'appointments' => $appointments['total'],
                'by_status' => $appointments['by_status'],
                'waiting' => $appointments['by_status'][Appointment::STATUS_ARRIVED] ?? 0,
                'no_shows' => $noShows['no_show'],
                'scheduled' => $noShows['scheduled'],
                'active_patients' => $metrics->activePatientsCount($actor, $today, $today),
            ];
        }

        // FINANCIAL — current outstanding balance. Only with billing.view.
        $financial = null;
        if (Gate::allows('billing.view')) {
            $financial = [
                'outstanding_minor' => $metrics->outstandingBalanceMinor($actor),
                'currency' => (string) $settings->get('currency', 'EUR'),
            ];
        }

        return Inertia::render('App/Landing', [
            'today' => $today,
            'operational' => $operational,
            'financial' => $financial,
        ]);
    }
}
