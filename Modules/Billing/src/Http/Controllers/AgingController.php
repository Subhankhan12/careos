<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Invoice;
use Modules\Platform\Models\User;
use Modules\Reporting\Services\MetricsService;

/**
 * Accounts-receivable aging — a READ-ONLY, factual snapshot from the tested
 * Reporting MetricsService (billing.view). Buckets are pure date math over
 * due_date; there is NO "bad debt" / write-off labeling and no interpretation.
 * All money stays integer minor units — the view formats only.
 */
class AgingController
{
    public function __invoke(Request $request, MetricsService $metrics): Response
    {
        Gate::authorize('billing.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $today = now();
        $buckets = $metrics->agingBuckets($actor, $today);
        $outstanding = $metrics->outstandingBalanceMinor($actor);

        $monthStart = $today->copy()->startOfMonth();

        return Inertia::render('Billing/Aging', [
            'asOf' => $today->toDateString(),
            'currency' => Invoice::query()->where('series', Invoice::SERIES_INVOICE)->value('currency') ?? 'EUR',
            'outstanding_minor' => $outstanding,
            'buckets' => $buckets,
            'monthToDate' => [
                'invoiced_minor' => $metrics->invoicedTotalMinor($actor, $monthStart, $today),
                'collected_minor' => $metrics->paymentsReceivedTotalMinor($actor, $monthStart, $today),
            ],
            'invoicesUrl' => route('billing.invoices.index'),
        ]);
    }
}
