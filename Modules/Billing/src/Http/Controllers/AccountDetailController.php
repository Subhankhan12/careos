<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Billing\Models\DebtEnforcementEscalation;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentPlan;
use Modules\Billing\Models\PaymentPlanInstallment;
use Modules\Billing\Services\DebtEnforcementService;
use Modules\Billing\Services\DunningService;
use Modules\Billing\Services\PaymentPlanService;
use Modules\Billing\Services\PaymentService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Reporting\Services\MetricsService;

/**
 * AR Account Detail (BILLAR.P7) — the drill target for the report's top-overdue table:
 * a per-account (patient-keyed) AR ledger. THIS gate wires the drill destination and a
 * minimal account header over ENGINE figures (the account's overdue total + invoice count
 * from `MetricsService::topOverdueAccounts`, filtered to this account); the full ledger
 * content is the NEXT gate. billing.view; the patient (account) is resolved from a STRING
 * id in-controller (FIX.1 — implicit route-model binding of a tenant model 500s), so a
 * cross-tenant id 404s via the tenant-scoped query.
 *
 * ARDETAIL.P4 adds the page's FIRST consequential write — {@see self::recordPayment()}. It
 * owns NO money logic: it resolves the operator's chosen targets and hands them to the
 * EXISTING {@see PaymentService} (`record` then `allocate`), which is the single authority
 * for what an allocation may not exceed (the invoice open balance, the payment remainder),
 * writes only append-only rows, refreshes the reconciled `invoice_balances` projection and
 * audits every movement. Reads stay billing.view; the write is billing.manage.
 */
class AccountDetailController
{
    public function show(Request $request, string $account, MetricsService $metrics, SettingsService $settings, PaymentPlanService $plans, DebtEnforcementService $enforcement): Response
    {
        Gate::authorize('billing.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $patient = Patient::query()->whereKey($account)->firstOrFail();

        // The account's overdue figures come straight from the engine (no page math): find
        // this account in the engine's overdue rollup (a large limit so it is never truncated).
        $overdue = $metrics->topOverdueAccounts($actor, now(), 10000);
        $entry = null;
        foreach ($overdue['accounts'] as $candidate) {
            if ($candidate['patient_id'] === (string) $patient->id) {
                $entry = $candidate;
                break;
            }
        }

        $ledger = $this->withInvoicePdfLinks($metrics->accountLedger($actor, (string) $patient->id, now()));
        $liveEscalation = $enforcement->currentFor((string) $patient->id);

        return Inertia::render('Billing/AccountDetail', [
            'account' => [
                'id' => (string) $patient->id,
                'name' => trim($patient->first_name.' '.$patient->last_name),
                'mrn' => $patient->mrn,
            ],
            'currency' => $settings->get('currency', 'EUR'),
            // Engine figures for this account (null when the account has nothing overdue).
            'overdue' => $entry === null ? null : [
                'total_overdue_minor' => $entry['total_overdue_minor'],
                'invoice_count' => $entry['invoice_count'],
                'max_days_overdue' => $entry['max_days_overdue'],
                'max_stage' => $entry['max_stage'],
                'ties' => $entry['ties'],
            ],
            // ARDETAIL.P1 — the per-account running-balance ledger, engine-computed; the page
            // only displays it. ARDETAIL.P3 decorates each row with a link to the EXISTING invoice
            // PDF generator (no new figure, no new mechanism).
            'ledger' => $ledger,
            // ARDETAIL.P2 — the account's dunning timeline, a READ-ONLY display of the real
            // state machine. The billing policy (which the dunning service owns) maps each level
            // to its fee_code, so the engine can attribute each event's real captured fee charge.
            'dunning' => $metrics->accountDunning($actor, (string) $patient->id, $this->dunningFeeCodeByLevel($settings), now()),
            // ARDETAIL.P4 — the record-payment action. `open_invoices` is a SELECTION of the engine
            // ledger rows already computed above (those the projection still reports as open), not a
            // recomputation: the page needs no figure the engine has not already produced. The
            // control is reflect-only — the server Gate below is what actually decides.
            'payment' => [
                'can_record' => Gate::allows('billing.manage'),
                'store_url' => route('billing.accounts.payments.store', (string) $patient->id),
                'methods' => Payment::METHODS,
                'open_invoices' => $this->openInvoiceTargets($ledger),
            ],
            // ARDETAIL.P5 — the installment payment plan. Every figure is a recorded fact or an
            // engine total from PaymentPlanService::present(); the page computes no money and does
            // NOT split the schedule (the engine partitions the total exactly).
            'plan' => [
                'can_manage' => Gate::allows('billing.manage'),
                'store_url' => route('billing.accounts.plans.store', (string) $patient->id),
                'current' => $this->withPlanActionUrls($plans->present($plans->currentOrLatestFor((string) $patient->id), now()), (string) $patient->id),
            ],
            // ARDETAIL.P6 — debt enforcement (Betreibung). Recorded facts + the REAL eligibility
            // evidence (terminal vs. reached dunning stage); `can_escalate` is the dedicated
            // billing.escalate permission, reflect-only — the server Gate is what refuses.
            'enforcement' => array_merge($enforcement->present((string) $patient->id), [
                'can_escalate' => Gate::allows(DebtEnforcementService::PERMISSION),
                'store_url' => route('billing.accounts.enforcement.store', (string) $patient->id),
                'withdraw_url' => $liveEscalation === null
                    ? null
                    : route('billing.accounts.enforcement.withdraw', [(string) $patient->id, $liveEscalation->id]),
            ]),
            'links' => [
                'report' => route('billing.report'),
                'dunning' => route('billing.dunning.index'),
                // ARDETAIL.P3 — the correctly-more-real patient-chart link (the existing patient 360).
                'chart' => route('patients.show', (string) $patient->id),
            ],
        ]);
    }

    /**
     * Record a payment received for this account and (optionally) allocate it to the account's
     * open invoices — the wireframe's "Record payment" action.
     *
     * THE FENCE: this method performs NO money math and writes NO payment/allocation row itself.
     * It validates shapes, resolves the operator's chosen invoices (tenant-scoped AND restricted to
     * THIS account, so a forged foreign/other-account invoice id 404s before any service call), and
     * then calls the EXISTING PaymentService — `record()` for the receipt and `allocate()` per
     * target. The service owns the guard: an allocation may exceed neither the invoice open balance
     * nor the payment's unallocated remainder (it throws), only issued/partially-paid invoices
     * receive allocations, every row is append-only, the reconciled projection is refreshed under a
     * row lock, and each movement is audited. A forged over-allocation POST is therefore refused by
     * the service, not by anything here.
     *
     * On a refused allocation the payment itself STANDS (money WAS received) and the error is
     * surfaced — the existing PaymentController::store discipline; the remainder is simply left
     * unallocated, which is exactly how the service models an overpayment. A correction is a
     * reversal row (`PaymentService::reverseAllocation`), never a mutation.
     */
    public function recordPayment(Request $request, string $account, PaymentService $payments): RedirectResponse
    {
        // OPERATOR-GATED. The agent has no path here: no registered AI tool is a payment capability
        // (the only financial tools are the advisory suggest-charge-codes / preflight-invoice drafts,
        // both capped at "approve"), and no AiCore code references PaymentService or this route —
        // the agent drafts, a human commits; it never commits money (D-151).
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $patient = Patient::query()->whereKey($account)->firstOrFail();

        // Amounts arrive already in integer minor units (the form normalises the major-unit input,
        // the existing Payments/Record.vue idiom); the service validates and owns all money math.
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:'.implode(',', Payment::METHODS)],
            'received_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoice_id' => ['required', 'string'],
            'allocations.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        /** @var list<array{0: Invoice, 1: int}> $targets */
        $targets = [];
        foreach (($validated['allocations'] ?? []) as $line) {
            // Tenant-scoped (global scope) AND account-scoped: an invoice belonging to another
            // account — or another tenant — is never an allocation target from this page.
            $invoice = Invoice::query()
                ->where('series', Invoice::SERIES_INVOICE)
                ->where('patient_id', $patient->id)
                ->whereKey($line['invoice_id'])
                ->firstOrFail();

            $targets[] = [$invoice, (int) $line['amount_minor']];
        }

        // ONE OPERATION, ONE TRANSACTION (QA-FIX.3a, D-199, closing `P3-C1`).
        //
        // The record and the allocations used to run unwrapped: `record()` committed the payment
        // immediately, and a refused allocation returned the guard's message afterwards — so the
        // operator was told the write had failed while a real payment row survived on the account.
        // `payments` is APPEND-ONLY at the model level (`Payment::updating`/`deleting` both throw),
        // so a compensating delete is impossible: a rollback is the only way to leave nothing behind.
        //
        // This matches the discipline the payment-plan path on this same page already uses —
        // `PaymentPlanService::create()` wraps its whole operation in `DB::transaction`, which is why
        // its refusal leaves no orphan plan.
        //
        // A LEGITIMATELY UNALLOCATED PAYMENT IS PRESERVED, and the distinction is structural rather
        // than a flag: with no allocation lines `$targets` is empty, `allocate()` is never called,
        // nothing throws and the transaction commits — money received today and applied tomorrow, or
        // an overpayment whose remainder stays unallocated, both still work exactly as before. Only
        // an allocation that is ATTEMPTED AND REFUSED unwinds the payment with it.
        //
        // The audit row rolls back with it, deliberately: `PaymentService::record()` writes
        // `payment.recorded` inline and `AuditService::record()` runs on the same connection, so the
        // ledger cannot claim a payment that does not exist. The hash chain stays gapless because the
        // append re-reads the tenant's latest COMMITTED row under `FOR UPDATE`.
        try {
            DB::transaction(function () use ($payments, $validated, $actor, $patient, $targets): void {
                $payment = $payments->record(
                    (int) $validated['amount_minor'],
                    $validated['method'],
                    $actor,
                    $patient,
                    null,
                    // Match the target invoices' currency when allocating (the service refuses a
                    // mismatch); otherwise the service stamps the tenant settlement currency.
                    $targets === [] ? null : $targets[0][0]->currency,
                    $validated['received_on'],
                    $validated['reference'] ?? null,
                );

                foreach ($targets as [$invoice, $amountMinor]) {
                    $payments->allocate($payment, $invoice, $amountMinor, $actor);
                }
            });
        } catch (InvalidArgumentException $e) {
            // The guard fired (over-allocation / remainder / not-open invoice). The transaction has
            // rolled back, so NOTHING was posted — not the allocation and not the payment. Surface
            // the service's own message on the account page.
            return redirect()
                ->route('billing.accounts.show', (string) $patient->id)
                ->withErrors(['record_payment' => $e->getMessage()]);
        }

        return redirect()->route('billing.accounts.show', (string) $patient->id);
    }

    /**
     * Create an installment payment plan for this account — the wireframe's "Set up payment plan".
     *
     * THE FENCE: no money math and no schedule arithmetic here. The controller passes the operator's
     * agreed total, installment count and start date to {@see PaymentPlanService::create()}, which
     * refuses a total above the account's REAL outstanding (and a second active plan), partitions the
     * total in integer minor units so the installments sum to it EXACTLY, and audits the agreement.
     * A plan schedules money; it moves none.
     */
    public function storePlan(Request $request, string $account, PaymentPlanService $plans): RedirectResponse
    {
        // OPERATOR-GATED — the agent never creates a payment plan (D-151/D-152).
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $patient = Patient::query()->whereKey($account)->firstOrFail();

        // total_minor arrives already in integer minor units (the form normalises the major-unit
        // input); the service validates it against the real outstanding and owns the split.
        $validated = $request->validate([
            'total_minor' => ['required', 'integer', 'min:1'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:'.PaymentPlanService::MAX_INSTALLMENTS],
            'start_date' => ['required', 'date'],
        ]);

        try {
            $plans->create(
                $patient,
                (int) $validated['total_minor'],
                (int) $validated['installment_count'],
                $validated['start_date'],
                $actor,
            );
        } catch (InvalidArgumentException $e) {
            // The tie refused it (over the outstanding / a second active plan / an impossible split).
            return redirect()
                ->route('billing.accounts.show', (string) $patient->id)
                ->withErrors(['payment_plan' => $e->getMessage()]);
        }

        return redirect()->route('billing.accounts.show', (string) $patient->id);
    }

    /**
     * Settle one installment. The plan writes NO money: the service records the payment through the
     * SAME guarded {@see PaymentService} path as ARDETAIL.P4 (over-allocation-guarded, append-only,
     * reconciling) and then records which payment settled the installment.
     */
    public function payInstallment(Request $request, string $account, string $installment, PaymentPlanService $plans): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $patient = Patient::query()->whereKey($account)->firstOrFail();

        $validated = $request->validate([
            'method' => ['required', 'string', 'in:'.implode(',', Payment::METHODS)],
            'received_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        // Tenant-scoped AND account-scoped: an installment of another account's plan is never
        // settleable from this page (it 404s before any money is recorded).
        $target = PaymentPlanInstallment::query()
            ->whereKey($installment)
            ->whereIn('payment_plan_id', PaymentPlan::query()->where('patient_id', $patient->id)->select('id'))
            ->firstOrFail();

        try {
            $plans->payInstallment($target, $validated['method'], $validated['received_on'], $actor, $validated['reference'] ?? null);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('billing.accounts.show', (string) $patient->id)
                ->withErrors(['payment_plan' => $e->getMessage()]);
        }

        return redirect()->route('billing.accounts.show', (string) $patient->id);
    }

    /** Cancel a running plan (an agreement that ended). Reason required; audited by the service. */
    public function cancelPlan(Request $request, string $account, string $plan, PaymentPlanService $plans): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $patient = Patient::query()->whereKey($account)->firstOrFail();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $target = PaymentPlan::query()->whereKey($plan)->where('patient_id', $patient->id)->firstOrFail();

        try {
            $plans->cancel($target, $validated['reason'], $actor);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('billing.accounts.show', (string) $patient->id)
                ->withErrors(['payment_plan' => $e->getMessage()]);
        }

        return redirect()->route('billing.accounts.show', (string) $patient->id);
    }

    /**
     * Start debt-enforcement (Betreibung) proceedings — the wireframe's "Approve Betreibung".
     *
     * THE SHARPEST FENCE ON THIS PAGE. A legal proceeding, so: gated on the dedicated
     * `billing.escalate` (NARROWER than billing.manage — charge-capturing clinical roles hold that
     * and must not be able to file); requiring the operator's EXPLICIT confirmation plus a recorded
     * reason (validated `accepted` — a missing confirmation is refused, never defaulted); allowed
     * only once the dunning process is EXHAUSTED (the service re-checks eligibility inside its own
     * transaction, so the page cannot talk it into an early escalation); recorded append-only and
     * audited with the operator's identity.
     *
     * This action and {@see self::withdrawEnforcement()} are the ONLY callers of
     * {@see DebtEnforcementService} in the codebase — no tool, job or schedule can reach it, which is
     * what makes "the agent never auto-escalates" structural rather than a claim.
     */
    public function initiateEnforcement(Request $request, string $account, DebtEnforcementService $enforcement): RedirectResponse
    {
        Gate::authorize(DebtEnforcementService::PERMISSION);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $patient = Patient::query()->whereKey($account)->firstOrFail();

        $validated = $request->validate([
            // The operator's deliberate confirmation of a legal act — must be explicitly true.
            'confirmed' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $enforcement->initiate(
                $patient,
                $validated['reason'],
                true,
                $actor,
                $validated['reference'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            // Ineligible (dunning not exhausted / nothing owed / already escalated) — surfaced verbatim.
            return redirect()
                ->route('billing.accounts.show', (string) $patient->id)
                ->withErrors(['enforcement' => $e->getMessage()]);
        }

        return redirect()->route('billing.accounts.show', (string) $patient->id);
    }

    /** Withdraw a live escalation. Appends a superseding record (never a mutation); reason required. */
    public function withdrawEnforcement(Request $request, string $account, string $escalation, DebtEnforcementService $enforcement): RedirectResponse
    {
        Gate::authorize(DebtEnforcementService::PERMISSION);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $patient = Patient::query()->whereKey($account)->firstOrFail();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        // Tenant-scoped AND account-scoped: another account's escalation 404s before anything happens.
        $target = DebtEnforcementEscalation::query()
            ->whereKey($escalation)
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        try {
            $enforcement->withdraw($target, $validated['reason'], $actor);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('billing.accounts.show', (string) $patient->id)
                ->withErrors(['enforcement' => $e->getMessage()]);
        }

        return redirect()->route('billing.accounts.show', (string) $patient->id);
    }

    /**
     * Decorate the engine's presented plan with its action routes (the `withInvoicePdfLinks`
     * pattern). Routes only — no figure is added, changed or recomputed here.
     *
     * @param  array<string, mixed>|null  $plan
     * @return array<string, mixed>|null
     */
    private function withPlanActionUrls(?array $plan, string $patientId): ?array
    {
        if ($plan === null) {
            return null;
        }

        $plan['cancel_url'] = route('billing.accounts.plans.cancel', [$patientId, $plan['id']]);
        $plan['installments'] = array_map(function (array $installment) use ($patientId): array {
            $installment['pay_url'] = route('billing.accounts.plans.pay', [$patientId, $installment['id']]);

            return $installment;
        }, $plan['installments']);

        return $plan;
    }

    /**
     * The account's still-open invoices, SELECTED from the engine ledger rows (never recomputed):
     * the projection's own status says which may receive an allocation, and `balance_minor` is the
     * projected open balance the service will cap against. Presentation input only — the authority
     * on what an allocation may not exceed stays PaymentService.
     *
     * @param  array<string, mixed>  $ledger
     * @return list<array{invoice_id: string, number: string|null, open_balance_minor: int}>
     */
    private function openInvoiceTargets(array $ledger): array
    {
        $open = [];
        foreach ($ledger['rows'] as $row) {
            if ($row['balance_minor'] > 0 && in_array($row['status'], [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID], true)) {
                $open[] = [
                    'invoice_id' => $row['invoice_id'],
                    'number' => $row['number'],
                    'open_balance_minor' => $row['balance_minor'],
                ];
            }
        }

        return $open;
    }

    /**
     * Decorate each ledger row with a link to the EXISTING invoice-PDF generator
     * (`billing.invoices.download`). No new figure or payment mechanism — just a link.
     *
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function withInvoicePdfLinks(array $ledger): array
    {
        $ledger['rows'] = array_map(function (array $row): array {
            $row['pdf_url'] = route('billing.invoices.download', $row['invoice_id']);

            return $row;
        }, $ledger['rows']);

        return $ledger;
    }

    /**
     * The billing.dunning policy's level ⇒ fee_code map (the real state-machine parameters),
     * so the engine can match each dunning event to its captured fee charge. Empty when no
     * policy / no fees are configured (then the timeline honestly shows zero fees).
     *
     * @return array<int, string>
     */
    private function dunningFeeCodeByLevel(SettingsService $settings): array
    {
        $policy = $settings->get(DunningService::SETTINGS_KEY, []);
        $levels = is_array($policy) && is_array($policy['levels'] ?? null) ? $policy['levels'] : [];

        $map = [];
        foreach ($levels as $level) {
            if (is_array($level) && isset($level['level'], $level['fee_code'])) {
                $map[(int) $level['level']] = (string) $level['fee_code'];
            }
        }

        return $map;
    }
}
