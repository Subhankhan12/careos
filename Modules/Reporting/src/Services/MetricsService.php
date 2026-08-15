<?php

namespace Modules\Reporting\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceAdjustment;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
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
     * FINANCIAL — the AR ROLL-FORWARD for a period, a RECONCILE-TO-THE-UNIT bridge in integer minor
     * units (BILLAR.P2): OPENING + CHARGES − COLLECTIONS − CONTRACTUAL ADJUSTMENTS − WRITE-OFFS = CLOSING.
     *
     * Every term is an engine aggregate over the append-only ledger + the reconciled `invoice_balances`
     * projection — the PAGE will only DISPLAY these six figures (P6), never compute them. The five
     * period terms are the EXHAUSTIVE set of AR movements:
     *   - `charges_minor`   = new issued invoices in the period, NET of credit-note cancellations
     *     (a credit note fully cancels an unpaid issued invoice — folding it here keeps the bridge
     *     exhaustive so the tie holds even when a credit note is issued in the period).
     *   - `collections_minor` = net payment allocations applied in the period (reversals net out) — the
     *     movement that actually reduces an invoice's open balance (not gross cash received).
     *   - `adjustments_minor` / `write_offs_minor` = the P1 {@see InvoiceAdjustment} movements in the period.
     * OPENING is the outstanding AR derived from the append-only ledger as of the day BEFORE `from`;
     * CLOSING is the reconciled `invoice_balances` projection (outstanding as of now/`to`), computed
     * INDEPENDENTLY of the bridge. This is the current-period management view (the wireframe's "as of
     * today"), so `to` is expected to be the present.
     *
     * THE TIE-OUT: `bridge_closing_minor` (opening ± the period movements) MUST equal `closing_minor`
     * (the independent reconciled outstanding), exactly — `ties` is true and `discrepancy_minor` is 0.
     * A non-tie is SURFACED (never papered over): it means an unmodeled movement changed AR, or the
     * projection drifted from the ledger — a reconcile failure to expose, not hide.
     *
     * @return array{from: string, to: string, opening_minor: int, charges_minor: int, collections_minor: int, adjustments_minor: int, write_offs_minor: int, closing_minor: int, bridge_closing_minor: int, discrepancy_minor: int, ties: bool}
     */
    public function arRollForward(User $actor, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $this->authorizeFinancial($actor);

        [$fromDate, $toDate] = $this->dateBounds($from, $to);
        $dayBeforeFrom = Carbon::parse($fromDate)->subDay()->toDateString();

        $opening = $this->outstandingAsOfMinor($dayBeforeFrom);
        $charges = $this->chargesBilledMinor($from, $to);
        $collections = $this->netCollectionsMinor($from, $to);
        $adjustments = $this->contractualAdjustmentsMinor($from, $to);
        $writeOffs = $this->writeOffsMinor($from, $to);

        // Closing computed INDEPENDENTLY of the bridge: the reconciled `invoice_balances` projection
        // (outstanding as of now/`to`). Comparing the movement-built bridge to the stored projection is
        // the reconcile — a projection drift or an unmodeled movement surfaces as a non-zero discrepancy.
        $closing = $this->outstandingBalanceMinor($actor);

        $bridgeClosing = $opening + $charges - $collections - $adjustments - $writeOffs;
        $discrepancy = $bridgeClosing - $closing;

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'opening_minor' => $opening,
            'charges_minor' => $charges,
            'collections_minor' => $collections,
            'adjustments_minor' => $adjustments,
            'write_offs_minor' => $writeOffs,
            'closing_minor' => $closing,
            'bridge_closing_minor' => $bridgeClosing,
            'discrepancy_minor' => $discrepancy,
            'ties' => $discrepancy === 0,
        ];
    }

    /**
     * FINANCIAL — DAYS SALES OUTSTANDING (DSO) for a period (BILLAR.P3): how many days of sales the
     * current receivables represent. Standard definition:
     *
     *     DSO = (AR outstanding ÷ credit sales in the period) × days in the period
     *
     * AR outstanding is the reconciled `invoice_balances` projection (as of now); credit sales are the
     * charges billed in the period (new invoices net of credit-note cancellations — the P2 definition).
     * The division is done in floating point and rounded to one decimal (the wireframe's "27.5 days")
     * — the underlying money is exact integer minor units; only the ratio is rounded, and the rounding
     * is documented here. A period with NO credit sales has an UNDEFINED DSO (no divide-by-zero): `dso`
     * is null so the page shows "—", never 0 or ∞.
     *
     * @return array{from: string, to: string, ar_minor: int, credit_sales_minor: int, days: int, dso: float|null}
     */
    public function daysSalesOutstanding(User $actor, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $this->authorizeFinancial($actor);

        [$fromDate, $toDate] = $this->dateBounds($from, $to);
        $ar = $this->outstandingBalanceMinor($actor);
        $creditSales = $this->chargesBilledMinor($from, $to);
        $days = (int) Carbon::parse($fromDate)->diffInDays(Carbon::parse($toDate)) + 1; // inclusive

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'ar_minor' => $ar,
            'credit_sales_minor' => $creditSales,
            'days' => $days,
            'dso' => $creditSales > 0 ? round($ar / $creditSales * $days, 1) : null,
        ];
    }

    /**
     * FINANCIAL — NET COLLECTION RATE for a period (BILLAR.P3): the share of what was collectible that
     * was actually collected. Standard definition:
     *
     *     net collection rate = collections ÷ collectible
     *     collectible         = charges billed − contractual adjustments
     *
     * COLLECTIBLE is honestly DERIVED, never fabricated: charges billed (new invoices net of credit-note
     * cancellations, the P2 definition) minus the P1 CONTRACTUAL adjustments (insurer-agreed reductions
     * that were never collectible). WRITE-OFFS are NOT subtracted — under the standard "net" definition
     * they are amounts that WERE collectible but went uncollected, so leaving them in the denominator is
     * what makes the rate reflect collection performance. COLLECTIONS are the net allocations actually
     * applied in the period (the P2 definition — what reduced AR, not gross cash).
     *
     * The rate is a fraction rounded to 4 decimals (the page formats it as a percentage). A period with
     * NO collectible (≤ 0) has an UNDEFINED rate (no divide-by-zero): `rate` is null so the page shows
     * "—", never a fabricated value. A rate above 1.0 is possible (e.g. collecting prior-period balances)
     * and is reported honestly, never capped.
     *
     * @return array{from: string, to: string, collections_minor: int, charges_minor: int, contractual_adjustments_minor: int, collectible_minor: int, rate: float|null}
     */
    public function netCollectionRate(User $actor, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $this->authorizeFinancial($actor);

        [$fromDate, $toDate] = $this->dateBounds($from, $to);
        $collections = $this->netCollectionsMinor($from, $to);
        $charges = $this->chargesBilledMinor($from, $to);
        $contractual = $this->contractualAdjustmentsMinor($from, $to);
        $collectible = $charges - $contractual;

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'collections_minor' => $collections,
            'charges_minor' => $charges,
            'contractual_adjustments_minor' => $contractual,
            'collectible_minor' => $collectible,
            'rate' => $collectible > 0 ? round($collections / $collectible, 4) : null,
        ];
    }

    /**
     * FINANCIAL — AR + collections + charges split BY PAYER for a period (BILLAR.P4), grouped over the
     * REAL `invoices.payer_type` dimension (the modeled payer: self-pay vs. private insurance). Each
     * group's figures reuse the same engine definitions as the roll-forward/DSO/rate (the reconciled
     * projection for AR; the shared period helpers for collections/charges), so the numbers agree.
     *
     * THE TIE: the split is a real PARTITION of the totals — every issued invoice carries exactly one
     * `payer_type`, so the group AR / collections / charges each SUM to the overall totals
     * ({@see outstandingBalanceMinor}, {@see netCollectionsMinor}, {@see chargesBilledMinor}); `ties`
     * confirms δ=0 on all three. No invoice is dropped or double-counted; an unexpected payer_type is
     * kept as its own group (labelled by the raw value), never folded away or invented.
     *
     * HONESTY / KNOWN GAP: the model has only two payer types (self-pay, private insurance). The
     * wireframe's finer Swiss taxonomy (supplementary insurance, accident SUVA/UVG, social/municipal)
     * is NOT modeled and is NOT fabricated here — a richer payer/insurer model is a separate future
     * gate. This method groups by the real dimension only.
     *
     * @return array{from: string, to: string, groups: list<array{payer_type: string, ar_minor: int, collections_minor: int, charges_minor: int}>, total_ar_minor: int, total_collections_minor: int, total_charges_minor: int, ties: bool}
     */
    public function arByPayer(User $actor, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $this->authorizeFinancial($actor);

        [$fromDate, $toDate] = $this->dateBounds($from, $to);
        $dateTimeBounds = $this->dateTimeBounds($from, $to);
        $cancelledIds = $this->cancelledByCreditNoteInPeriodIds($fromDate, $toDate);

        // Every payer_type present on an issued invoice — the exhaustive partition key (tenant-scoped).
        $payerTypes = Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereIn('status', $this->frozenStatuses())
            ->distinct()
            ->orderBy('payer_type')
            ->pluck('payer_type')
            ->all();

        $groups = [];
        foreach ($payerTypes as $payerType) {
            $payerInvoiceIds = Invoice::query()
                ->where('series', Invoice::SERIES_INVOICE)
                ->whereIn('status', $this->frozenStatuses())
                ->where('payer_type', $payerType)
                ->select('id');

            $ar = (int) InvoiceBalance::query()
                ->whereIn('invoice_id', $payerInvoiceIds)
                ->sum('open_balance_minor');

            $collections = (int) PaymentAllocation::query()
                ->whereBetween('allocated_at', $dateTimeBounds)
                ->whereIn('invoice_id', $payerInvoiceIds)
                ->sum('amount_minor');

            $grossCharges = (int) Invoice::query()
                ->where('series', Invoice::SERIES_INVOICE)
                ->whereIn('status', $this->frozenStatuses())
                ->where('payer_type', $payerType)
                ->whereBetween('issue_date', [$fromDate, $toDate])
                ->sum('total_minor');

            $creditedCharges = $cancelledIds === [] ? 0 : (int) Invoice::query()
                ->where('series', Invoice::SERIES_INVOICE)
                ->where('payer_type', $payerType)
                ->whereIn('id', $cancelledIds)
                ->sum('total_minor');

            $groups[] = [
                'payer_type' => $payerType,
                'ar_minor' => $ar,
                'collections_minor' => $collections,
                'charges_minor' => $grossCharges - $creditedCharges,
            ];
        }

        $totalAr = $this->outstandingBalanceMinor($actor);
        $totalCollections = $this->netCollectionsMinor($from, $to);
        $totalCharges = $this->chargesBilledMinor($from, $to);

        $ties = array_sum(array_column($groups, 'ar_minor')) === $totalAr
            && array_sum(array_column($groups, 'collections_minor')) === $totalCollections
            && array_sum(array_column($groups, 'charges_minor')) === $totalCharges;

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'groups' => $groups,
            'total_ar_minor' => $totalAr,
            'total_collections_minor' => $totalCollections,
            'total_charges_minor' => $totalCharges,
            'ties' => $ties,
        ];
    }

    /**
     * Charges billed in a period: new issued invoices, NET of credit-note cancellations (a full credit
     * note cancels an unpaid issued invoice, removing its total). Shared by the roll-forward (P2), DSO
     * and the net collection rate so every "charges billed" figure agrees.
     */
    private function chargesBilledMinor(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        [$fromDate, $toDate] = $this->dateBounds($from, $to);

        $gross = (int) Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereIn('status', $this->frozenStatuses())
            ->whereBetween('issue_date', [$fromDate, $toDate])
            ->sum('total_minor');

        $credited = (int) Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereIn('id', $this->cancelledByCreditNoteInPeriodIds($fromDate, $toDate))
            ->sum('total_minor');

        return $gross - $credited;
    }

    /** Net payment allocations applied in a period (reversals net out) — the collections that reduce AR. */
    private function netCollectionsMinor(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        return (int) PaymentAllocation::query()
            ->whereBetween('allocated_at', $this->dateTimeBounds($from, $to))
            ->sum('amount_minor');
    }

    /** Net P1 contractual-adjustment movements posted in a period. */
    private function contractualAdjustmentsMinor(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        return (int) InvoiceAdjustment::query()
            ->where('type', InvoiceAdjustment::TYPE_CONTRACTUAL)
            ->whereBetween('adjusted_at', $this->dateTimeBounds($from, $to))
            ->sum('amount_minor');
    }

    /** Net P1 write-off movements posted in a period. */
    private function writeOffsMinor(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        return (int) InvoiceAdjustment::query()
            ->where('type', InvoiceAdjustment::TYPE_WRITE_OFF)
            ->whereBetween('adjusted_at', $this->dateTimeBounds($from, $to))
            ->sum('amount_minor');
    }

    /**
     * FINANCIAL — outstanding AR derived from the append-only ledger AS OF a date (integer minor units):
     * the sum, over issued (series=INV) invoices existing by that date, of `total − allocations(≤date) −
     * adjustments(≤date)`, with an invoice fully cancelled by a credit note issued by that date counting
     * as 0. This is the historical analogue of the {@see outstandingBalanceMinor()} projection; at "today"
     * the two agree (the I2 reconcile invariant), so the roll-forward's closing ties to the unit.
     */
    private function outstandingAsOfMinor(CarbonInterface|string $asOf): int
    {
        $asOfDate = Carbon::parse($asOf instanceof CarbonInterface ? $asOf->toDateString() : $asOf)->toDateString();
        $asOfDateTime = Carbon::parse($asOfDate)->endOfDay()->toDateTimeString();

        $cancelledIds = $this->cancelledByCreditNoteInPeriodIds('1900-01-01', $asOfDate);

        $total = 0;

        $invoices = Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereIn('status', $this->frozenStatuses())
            ->whereDate('issue_date', '<=', $asOfDate)
            ->get(['id', 'total_minor']);

        foreach ($invoices as $invoice) {
            if (in_array($invoice->id, $cancelledIds, true)) {
                continue; // fully cancelled by a credit note by this date → 0 outstanding
            }

            $allocated = (int) PaymentAllocation::query()
                ->where('invoice_id', $invoice->id)
                ->where('allocated_at', '<=', $asOfDateTime)
                ->sum('amount_minor');

            $adjusted = (int) InvoiceAdjustment::query()
                ->where('invoice_id', $invoice->id)
                ->where('adjusted_at', '<=', $asOfDateTime)
                ->sum('amount_minor');

            $total += (int) $invoice->total_minor - $allocated - $adjusted;
        }

        return $total;
    }

    /**
     * Ids of invoices FULLY cancelled by a credit note whose `issue_date` falls in [from, to]. The
     * cancellation is recorded on the `invoice_balances` projection (status CANCELLED_BY_CREDIT_NOTE —
     * a full credit sets open to 0; the frozen `invoices.status` stays ISSUED), so a partial credit note
     * (which does NOT cancel or reduce the invoice balance) is correctly excluded.
     *
     * @return list<string>
     */
    private function cancelledByCreditNoteInPeriodIds(string $from, string $to): array
    {
        $sourceIds = Invoice::query()
            ->where('series', Invoice::SERIES_CREDIT_NOTE)
            ->whereNotNull('credit_note_for_invoice_id')
            ->whereBetween('issue_date', [$from, $to])
            ->pluck('credit_note_for_invoice_id')
            ->all();

        if ($sourceIds === []) {
            return [];
        }

        return InvoiceBalance::query()
            ->whereIn('invoice_id', $sourceIds)
            ->where('status', Invoice::STATUS_CANCELLED_BY_CREDIT_NOTE)
            ->pluck('invoice_id')
            ->all();
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
     * FINANCIAL — the overdue portion of the outstanding balance as of a reference
     * date: the sum of the past-due aging buckets (1-30 … 90+), excluding the
     * not-yet-due `current` bucket. The bucketing (and its calendar-day date math)
     * stays in {@see self::agingBuckets()}; this is only the past-due roll-up, so
     * callers never re-derive aging themselves. Factual — no "bad debt" labeling.
     */
    public function overdueBalanceMinor(User $actor, CarbonInterface|string $asOf): int
    {
        $buckets = $this->agingBuckets($actor, $asOf);

        return $buckets['days_1_30'] + $buckets['days_31_60'] + $buckets['days_61_90'] + $buckets['days_90_plus'];
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
