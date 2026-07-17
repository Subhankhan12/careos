<?php

namespace Modules\Reporting\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Models\User;

/**
 * Thin facade assembling the universal metric set into one plain data bundle —
 * ready for a future (post-discovery) dashboard to consume, returned as DATA only.
 * No HTTP page, no UI.
 *
 * RBAC: the summary requires `reporting.view`. The `financial` section is included
 * ONLY when the actor also holds `billing.view` — otherwise it is omitted entirely
 * (fail-closed; a coordinator gets the operational bundle without financials).
 * Aging is computed as of the range end date (documented).
 */
class ReportingService
{
    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        ?string $branchId = null,
    ): array {
        $fromDate = Carbon::parse($from instanceof CarbonInterface ? $from->toDateString() : $from)->toDateString();
        $toDate = Carbon::parse($to instanceof CarbonInterface ? $to->toDateString() : $to)->toDateString();

        $summary = [
            'range' => [
                'from' => $fromDate,
                'to' => $toDate,
                'branch_id' => $branchId,
            ],
            'operational' => [
                'appointments' => $this->metrics->appointmentsInRange($actor, $fromDate, $toDate, $branchId),
                'no_shows' => $this->metrics->noShows($actor, $fromDate, $toDate, $branchId),
                'checked_in' => $this->metrics->checkedInCount($actor, $fromDate, $toDate, $branchId),
                'visits_completed' => $this->metrics->visitsCompletedInRange($actor, $fromDate, $toDate, $branchId),
                'active_patients' => $this->metrics->activePatientsCount($actor, $fromDate, $toDate, $branchId),
            ],
            'throughput' => [
                'encounters' => $this->metrics->encountersInRange($actor, $fromDate, $toDate, $branchId),
                'signed_notes' => $this->metrics->signedNotesInRange($actor, $fromDate, $toDate),
                'orders_placed' => $this->metrics->ordersPlacedInRange($actor, $fromDate, $toDate),
            ],
        ];

        if (Gate::forUser($actor)->allows('billing.view')) {
            $summary['financial'] = [
                'invoiced_total_minor' => $this->metrics->invoicedTotalMinor($actor, $fromDate, $toDate),
                'payments_received_total_minor' => $this->metrics->paymentsReceivedTotalMinor($actor, $fromDate, $toDate),
                'outstanding_balance_minor' => $this->metrics->outstandingBalanceMinor($actor),
                'aging' => $this->metrics->agingBuckets($actor, $toDate),
            ];
        }

        return $summary;
    }
}
