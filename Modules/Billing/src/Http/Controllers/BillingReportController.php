<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Reporting\Services\MetricsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Billing & AR management report (BILLAR.P6) — the consolidated grid that DISPLAYS
 * the P1–P5 MetricsService engine figures (headline, stat cards, aging, roll-forward,
 * by-payer, DSO/collection-rate, charged-vs-collected trend) in one place.
 *
 * FENCE: this controller computes NO money. Every figure is a MetricsService return
 * value; the page only formats them. The period switcher RE-PARAMETERIZES the engine
 * ([from,to] passed to the tested service) — the server recomputes for the new period;
 * nothing is re-sliced/re-aggregated in the page. The CSV export streams the same
 * engine figures. It does NOT replace the live aging/reporting/dunning surfaces — those
 * routes stay working; this page consolidates their AR content and links back to them.
 * billing.view + tenant-scoped (the service fails closed without a tenant context).
 */
class BillingReportController
{
    /** The period keys the switcher offers, in presentation order. */
    private const PERIODS = ['week', 'month', 'quarter', 'ytd'];

    public function show(Request $request, MetricsService $metrics, SettingsService $settings): Response
    {
        Gate::authorize('billing.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $period = $this->resolvePeriodKey($request);
        $compareOn = $request->boolean('compare');

        [$from, $to, $bucket] = $this->periodBounds($period, now());
        $report = $this->engineFigures($actor, $metrics, $from, $to, $bucket);

        $compare = null;
        if ($compareOn) {
            [$cFrom, $cTo, $cBucket] = $this->comparePeriodBounds($period, now());
            $compare = $this->engineFigures($actor, $metrics, $cFrom, $cTo, $cBucket);
        }

        return Inertia::render('Billing/Report', [
            'period' => $period,
            'periods' => self::PERIODS,
            'compareOn' => $compareOn,
            'currency' => $settings->get('currency', 'EUR'),
            'report' => $report,
            'compare' => $compare,
            'links' => [
                'self' => route('billing.report'),
                'aging' => route('billing.aging'),
                'dunning' => route('billing.dunning.index'),
                'reporting' => route('reporting.dashboard'),
                'invoices' => route('billing.invoices.index'),
                'export' => route('billing.report.export', ['period' => $period, 'compare' => $compareOn ? 1 : 0]),
            ],
        ]);
    }

    /**
     * CSV export of the SAME engine figures the grid displays — engine-computed data,
     * not a page-side recomputation. (PDF is deliberately not faked; CSV only for now.)
     */
    public function export(Request $request, MetricsService $metrics): StreamedResponse
    {
        Gate::authorize('billing.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $period = $this->resolvePeriodKey($request);
        [$from, $to, $bucket] = $this->periodBounds($period, now());
        $r = $this->engineFigures($actor, $metrics, $from, $to, $bucket);

        // Every value below is a MetricsService figure (integer minor units, or the
        // service's own rounded ratio) — the export sums/derives nothing itself.
        $rows = [
            ['section', 'metric', 'value_minor_or_ratio'],
            ['period', 'from', $r['period']['from']],
            ['period', 'to', $r['period']['to']],
            ['headline', 'total_ar_minor', $r['total_ar_minor']],
            ['headline', 'current_minor', $r['aging']['current']],
            ['headline', 'overdue_minor', $r['overdue_minor']],
            ['headline', 'in_collection_minor', $r['aging']['days_90_plus']],
            ['headline', 'dso', $r['dso']['dso'] ?? ''],
            ['aging', 'current', $r['aging']['current']],
            ['aging', 'days_1_30', $r['aging']['days_1_30']],
            ['aging', 'days_31_60', $r['aging']['days_31_60']],
            ['aging', 'days_61_90', $r['aging']['days_61_90']],
            ['aging', 'days_90_plus', $r['aging']['days_90_plus']],
            ['roll_forward', 'opening_minor', $r['roll_forward']['opening_minor']],
            ['roll_forward', 'charges_minor', $r['roll_forward']['charges_minor']],
            ['roll_forward', 'collections_minor', $r['roll_forward']['collections_minor']],
            ['roll_forward', 'adjustments_minor', $r['roll_forward']['adjustments_minor']],
            ['roll_forward', 'write_offs_minor', $r['roll_forward']['write_offs_minor']],
            ['roll_forward', 'closing_minor', $r['roll_forward']['closing_minor']],
            ['roll_forward', 'discrepancy_minor', $r['roll_forward']['discrepancy_minor']],
            ['roll_forward', 'ties', $r['roll_forward']['ties'] ? '1' : '0'],
            ['collection_rate', 'collections_minor', $r['collection_rate']['collections_minor']],
            ['collection_rate', 'collectible_minor', $r['collection_rate']['collectible_minor']],
            ['collection_rate', 'rate', $r['collection_rate']['rate'] ?? ''],
        ];
        foreach ($r['by_payer']['groups'] as $g) {
            $rows[] = ['by_payer:'.$g['payer_type'], 'ar_minor', $g['ar_minor']];
            $rows[] = ['by_payer:'.$g['payer_type'], 'collections_minor', $g['collections_minor']];
            $rows[] = ['by_payer:'.$g['payer_type'], 'charges_minor', $g['charges_minor']];
        }
        foreach ($r['trend']['buckets'] as $b) {
            $rows[] = ['trend:'.$b['label'], 'charges_minor', $b['charges_minor']];
            $rows[] = ['trend:'.$b['label'], 'collections_minor', $b['collections_minor']];
        }

        $filename = 'billing-ar-report-'.$period.'-'.$r['period']['from'].'_'.$r['period']['to'].'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function resolvePeriodKey(Request $request): string
    {
        $period = (string) $request->query('period', 'month');

        return in_array($period, self::PERIODS, true) ? $period : 'month';
    }

    /**
     * Assemble every displayed figure from the tested engine. No money is computed
     * here — each value is a MetricsService return. asOf for the point-in-time AR /
     * aging figures is the period end.
     *
     * @return array<string, mixed>
     */
    private function engineFigures(User $actor, MetricsService $metrics, Carbon $from, Carbon $to, string $bucket): array
    {
        $asOf = $to;

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'bucket' => $bucket],
            'total_ar_minor' => $metrics->outstandingBalanceMinor($actor),
            'overdue_minor' => $metrics->overdueBalanceMinor($actor, $asOf),
            'aging' => $metrics->agingBuckets($actor, $asOf),
            'invoiced_minor' => $metrics->invoicedTotalMinor($actor, $from, $to),
            'collected_minor' => $metrics->paymentsReceivedTotalMinor($actor, $from, $to),
            'roll_forward' => $metrics->arRollForward($actor, $from, $to),
            'dso' => $metrics->daysSalesOutstanding($actor, $from, $to),
            'collection_rate' => $metrics->netCollectionRate($actor, $from, $to),
            'by_payer' => $metrics->arByPayer($actor, $from, $to),
            'trend' => $metrics->chargedVsCollectedTrend($actor, $from, $to, $bucket),
        ];
    }

    /**
     * Map a period key to [from, to, trend-bucket]. Calendar-aligned to the period
     * containing "now"; short periods bucket the trend by week, long ones by month.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function periodBounds(string $period, Carbon $now): array
    {
        return match ($period) {
            'week' => [$now->copy()->startOfWeek(), $now->copy(), 'week'],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy(), 'month'],
            'ytd' => [$now->copy()->startOfYear(), $now->copy(), 'month'],
            default => [$now->copy()->startOfMonth(), $now->copy(), 'week'],
        };
    }

    /**
     * The prior equivalent period, for Compare.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function comparePeriodBounds(string $period, Carbon $now): array
    {
        return match ($period) {
            'week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek(), 'week'],
            'quarter' => [$now->copy()->subQuarterNoOverflow()->startOfQuarter(), $now->copy()->subQuarterNoOverflow()->endOfQuarter(), 'month'],
            'ytd' => [$now->copy()->subYearNoOverflow()->startOfYear(), $now->copy()->subYearNoOverflow(), 'month'],
            default => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth(), 'week'],
        };
    }
}
