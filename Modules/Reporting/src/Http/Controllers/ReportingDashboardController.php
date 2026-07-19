<?php

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Reporting\Services\ReportingService;

/**
 * The thin reporting dashboard over the tested ReportingService summary bundle
 * (P0P.G14). READS gate on `reporting.view`. The bundle is FACTS ONLY — counts,
 * sums, and rates the service already returns; nothing is graded, compared to a
 * target, trended, or labelled good/bad. The `financial` section is present only
 * when the actor also holds `billing.view` (the service composes fail-closed).
 * Money is integer minor units; the view formats, it never computes.
 */
class ReportingDashboardController
{
    public function __invoke(Request $request, ReportingService $reporting, SettingsService $settings): Response
    {
        Gate::authorize('reporting.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->toDateString()
            : now()->startOfMonth()->toDateString();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->toDateString()
            : now()->toDateString();

        return Inertia::render('Reporting/Dashboard', [
            'summary' => $reporting->summary($actor, $from, $to),
            'currency' => (string) $settings->get('currency', 'EUR'),
            'hasFinancial' => Gate::allows('billing.view'),
            'filtersUrl' => route('reporting.dashboard'),
        ]);
    }
}
