<?php

namespace Modules\Reporting\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\Payment;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Order;
use Modules\Nursing\Models\Visit;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Appointment;

/**
 * Universal, tenant-scoped, READ-ONLY operational/financial aggregates (P0P.G14).
 *
 * Principles (D-080):
 * - Facts, not judgments: every method returns counts/sums/rates only. Nothing is
 *   ever labeled good/bad/high/low, ranked with a verdict, or graded.
 * - ELECTRIC FENCE: operational + financial aggregates only. No clinical
 *   interpretation, no risk scoring, no outcome grading — counting encounters is
 *   fine, interpreting them clinically is not.
 * - Tenant-scoped + fail-closed: every query runs through BelongsToTenant models,
 *   so without an established tenant context it THROWS; cross-tenant aggregation
 *   is impossible.
 * - Read-only: this service performs no writes. Aggregates are not patient
 *   records, so NO patient-scoped read-audit rows are written — no method returns
 *   or resolves a single patient's record. If a future metric can resolve to one
 *   patient, it must be treated as a patient read instead.
 * - Money is integer minor units, reusing the F.7 reconciliation definitions so
 *   reporting numbers agree with the billing source of truth.
 *
 * RBAC mapping (documented):
 * - OPERATIONAL + THROUGHPUT metrics require `reporting.view` (org_admin +
 *   coordinator starter roles — the manager capability).
 * - FINANCIAL metrics require `billing.view` (org_admin + billing starter roles —
 *   the finance capability).
 *
 * Date attribution (documented per metric): ranges are inclusive calendar days;
 * datetime columns are bounded [from 00:00:00, to 23:59:59], date columns
 * [from, to]. Branch filtering applies only where the underlying table carries a
 * branch dimension (appointments/visits/encounters); invoices, payments, notes,
 * and orders have no branch column, so those metrics take no branch parameter.
 */
class MetricsService
{
    /**
     * Every appointment lifecycle status, in stable presentation order, so the
     * breakdown is complete and zero-filled regardless of what the range holds.
     *
     * @var list<string>
     */
    private const APPOINTMENT_STATUSES = [
        Appointment::STATUS_BOOKED,
        Appointment::STATUS_CONFIRMED,
        Appointment::STATUS_ARRIVED,
        Appointment::STATUS_IN_PROGRESS,
        Appointment::STATUS_COMPLETED,
        Appointment::STATUS_CANCELLED,
        Appointment::STATUS_NO_SHOW,
        Appointment::STATUS_RESCHEDULED,
    ];

    /**
     * OPERATIONAL — appointments whose `starts_at` falls in the range, as a total
     * plus a raw per-status breakdown (every lifecycle status, zero-filled).
     *
     * @return array{total: int, by_status: array<string, int>}
     */
    public function appointmentsInRange(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        ?string $branchId = null,
    ): array {
        $this->authorizeOperational($actor);

        $counts = Appointment::query()
            ->whereBetween('starts_at', $this->dateTimeBounds($from, $to))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];
        foreach (self::APPOINTMENT_STATUSES as $status) {
            $byStatus[$status] = (int) ($counts[$status] ?? 0);
        }

        return [
            'total' => (int) $counts->sum(),
            'by_status' => $byStatus,
        ];
    }

    /**
     * OPERATIONAL — no-show count and rate. The denominator is ALL appointments
     * whose `starts_at` falls in the range, regardless of final status (documented
     * choice: "scheduled" = everything that was on the book for the range). The
     * rate is a fact (no_show / scheduled), 0.0 when nothing was scheduled.
     *
     * @return array{no_show: int, scheduled: int, rate: float}
     */
    public function noShows(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        ?string $branchId = null,
    ): array {
        $appointments = $this->appointmentsInRange($actor, $from, $to, $branchId);
        $noShow = $appointments['by_status'][Appointment::STATUS_NO_SHOW];
        $scheduled = $appointments['total'];

        return [
            'no_show' => $noShow,
            'scheduled' => $scheduled,
            'rate' => $scheduled === 0 ? 0.0 : round($noShow / $scheduled, 4),
        ];
    }

    /**
     * OPERATIONAL — appointments checked in (P0P.G7 self check-in or reception),
     * attributed by the `checked_in_at` moment falling in the range.
     */
    public function checkedInCount(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        ?string $branchId = null,
    ): int {
        $this->authorizeOperational($actor);

        return Appointment::query()
            ->whereNotNull('checked_in_at')
            ->whereBetween('checked_in_at', $this->dateTimeBounds($from, $to))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->count();
    }

    /**
     * OPERATIONAL — nursing visits with a completed status, attributed by
     * `scheduled_start_at` in the range (documented choice: the visit's scheduled
     * day, which is always present, not the checkout moment).
     */
    public function visitsCompletedInRange(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        ?string $branchId = null,
    ): int {
        $this->authorizeOperational($actor);

        return Visit::query()
            ->where('status', Visit::STATUS_COMPLETED)
            ->whereBetween('scheduled_start_at', $this->dateTimeBounds($from, $to))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->count();
    }

    /**
     * OPERATIONAL — distinct patients with ANY encounter, nursing visit, or
     * appointment in the range (a count, not a list — no patient record is
     * returned or resolvable from it).
     */
    public function activePatientsCount(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        ?string $branchId = null,
    ): int {
        $this->authorizeOperational($actor);
        $bounds = $this->dateTimeBounds($from, $to);

        $fromAppointments = Appointment::query()
            ->whereNotNull('patient_id')
            ->whereBetween('starts_at', $bounds)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->pluck('patient_id');

        $fromEncounters = Encounter::query()
            ->whereBetween('started_at', $bounds)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->pluck('patient_id');

        $fromVisits = Visit::query()
            ->whereBetween('scheduled_start_at', $bounds)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->pluck('patient_id');

        return $fromAppointments
            ->merge($fromEncounters)
            ->merge($fromVisits)
            ->unique()
            ->count();
    }

    /**
     * FINANCIAL — sum of issued (non-credit-note) invoice totals with
     * `issue_date` in the range, in integer minor units. Definition reused
     * VERBATIM from ReconciliationEngine I4 (series=INV, frozen statuses,
     * issue_date between bounds, sum of total_minor) so this number reconciles
     * with the F.7 source of truth.
     */
    public function invoicedTotalMinor(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
    ): int {
        $this->authorizeFinancial($actor);

        return (int) Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereIn('status', $this->frozenStatuses())
            ->whereBetween('issue_date', $this->dateBounds($from, $to))
            ->sum('total_minor');
    }

    /**
     * FINANCIAL — sum of payments with `received_on` in the range, in integer
     * minor units. Payments are the F.5 append-only ledger; refunds are separate
     * rows in their own table and are not netted here (a payment received is a
     * fact regardless of a later refund).
     */
    public function paymentsReceivedTotalMinor(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
    ): int {
        $this->authorizeFinancial($actor);

        return (int) Payment::query()
            ->whereBetween('received_on', $this->dateBounds($from, $to))
            ->sum('amount_minor');
    }

    /**
     * FINANCIAL — current outstanding balance: sum of `open_balance_minor` across
     * issued (non-credit-note) invoices, read from `invoice_balances` — the
     * F.4/F.5 projection that is the source of truth for open balances (I2).
     * This is a point-in-time fact, so it takes no date range.
     */
    public function outstandingBalanceMinor(User $actor): int
    {
        $this->authorizeFinancial($actor);

        return (int) InvoiceBalance::query()
            ->whereIn('invoice_id', $this->issuedInvoiceIdsQuery())
            ->sum('open_balance_minor');
    }

    /**
     * FINANCIAL — the outstanding balance split by overdue age as of a reference
     * date. Buckets are factual date math over `due_date` (days past due =
     * whole days between due_date and asOf): current (not yet due, or no due
     * date), 1-30, 31-60, 61-90, and 90+ days past due. No "bad debt" labeling —
     * the buckets carry sums only.
     *
     * @return array{current: int, days_1_30: int, days_31_60: int, days_61_90: int, days_90_plus: int}
     */
    public function agingBuckets(User $actor, CarbonInterface|string $asOf): array
    {
        $this->authorizeFinancial($actor);
        $asOfDate = Carbon::parse($asOf instanceof CarbonInterface ? $asOf->toDateString() : $asOf)->startOfDay();

        $buckets = [
            'current' => 0,
            'days_1_30' => 0,
            'days_31_60' => 0,
            'days_61_90' => 0,
            'days_90_plus' => 0,
        ];

        $balances = InvoiceBalance::query()
            ->whereIn('invoice_id', $this->issuedInvoiceIdsQuery())
            ->where('open_balance_minor', '>', 0)
            ->get();

        $invoices = Invoice::query()
            ->whereIn('id', $balances->pluck('invoice_id')->all())
            ->get()
            ->keyBy('id');

        foreach ($balances as $balance) {
            $invoice = $invoices->get($balance->invoice_id);
            $open = (int) $balance->open_balance_minor;

            $dueDate = $invoice?->due_date?->copy()->startOfDay();
            $daysPastDue = ($dueDate === null || ! $dueDate->lt($asOfDate))
                ? 0
                : (int) $dueDate->diffInDays($asOfDate);

            $bucket = match (true) {
                $daysPastDue <= 0 => 'current',
                $daysPastDue <= 30 => 'days_1_30',
                $daysPastDue <= 60 => 'days_31_60',
                $daysPastDue <= 90 => 'days_61_90',
                default => 'days_90_plus',
            };

            $buckets[$bucket] += $open;
        }

        return $buckets;
    }

    /**
     * THROUGHPUT — encounters with `started_at` in the range. A count only; no
     * clinical interpretation of what happened in them.
     */
    public function encountersInRange(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        ?string $branchId = null,
    ): int {
        $this->authorizeOperational($actor);

        return Encounter::query()
            ->whereBetween('started_at', $this->dateTimeBounds($from, $to))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->count();
    }

    /**
     * THROUGHPUT — clinical notes signed in the range (`status=signed`,
     * `signed_at` in bounds). A count only.
     */
    public function signedNotesInRange(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
    ): int {
        $this->authorizeOperational($actor);

        return ClinicalNote::query()
            ->where('status', ClinicalNote::STATUS_SIGNED)
            ->whereBetween('signed_at', $this->dateTimeBounds($from, $to))
            ->count();
    }

    /**
     * THROUGHPUT — structured orders placed in the range (`ordered_at` in
     * bounds). A count only.
     */
    public function ordersPlacedInRange(
        User $actor,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
    ): int {
        $this->authorizeOperational($actor);

        return Order::query()
            ->whereBetween('ordered_at', $this->dateTimeBounds($from, $to))
            ->count();
    }

    private function authorizeOperational(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('reporting.view')) {
            throw new AuthorizationException('This user cannot view operational reporting aggregates.');
        }
    }

    private function authorizeFinancial(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('billing.view')) {
            throw new AuthorizationException('This user cannot view financial reporting aggregates.');
        }
    }

    /**
     * Issued (non-credit-note) invoice ids — the I2/I4 population.
     *
     * @return Builder<Invoice>
     */
    private function issuedInvoiceIdsQuery()
    {
        return Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereIn('status', $this->frozenStatuses())
            ->select('id');
    }

    /**
     * The frozen (issued) invoice statuses, mirroring ReconciliationEngine.
     *
     * @return list<string>
     */
    private function frozenStatuses(): array
    {
        return [
            Invoice::STATUS_ISSUED,
            Invoice::STATUS_PAID,
            Invoice::STATUS_PARTIALLY_PAID,
            Invoice::STATUS_CANCELLED_BY_CREDIT_NOTE,
        ];
    }

    /**
     * Inclusive calendar-day bounds for datetime columns.
     *
     * @return array{0: string, 1: string}
     */
    private function dateTimeBounds(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return [
            Carbon::parse($from instanceof CarbonInterface ? $from->toDateString() : $from)->startOfDay()->toDateTimeString(),
            Carbon::parse($to instanceof CarbonInterface ? $to->toDateString() : $to)->endOfDay()->toDateTimeString(),
        ];
    }

    /**
     * Inclusive bounds for date columns.
     *
     * @return array{0: string, 1: string}
     */
    private function dateBounds(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return [
            Carbon::parse($from instanceof CarbonInterface ? $from->toDateString() : $from)->toDateString(),
            Carbon::parse($to instanceof CarbonInterface ? $to->toDateString() : $to)->toDateString(),
        ];
    }
}
