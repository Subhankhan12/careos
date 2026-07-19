<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentAllocation;
use Modules\Billing\Models\Refund;
use Modules\Billing\Services\PaymentService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * Staff payments UI: record a received payment, allocate it against invoices, and
 * reverse an allocation. READS gate on `billing.view`; WRITES gate on
 * `billing.manage` AND go exclusively through PaymentService — every money
 * movement is an APPEND-ONLY row and no remainder/open-balance math is ever
 * recomputed here (the view formats integer minor units, it never derives them).
 * This RECORDS money already received (bank transfer / cash / card at the desk) —
 * there is NO card capture / PSP; the service is the source of truth for what an
 * allocation may not exceed (payment remainder, invoice open balance).
 */
class PaymentController
{
    public function index(Request $request, PaymentService $payments): Response
    {
        Gate::authorize('billing.view');
        abort_unless($request->user() instanceof User, 403);

        $records = Payment::query()
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $patients = Patient::query()
            ->whereIn('id', $records->pluck('patient_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return Inertia::render('Billing/Payments/Index', [
            'payments' => $records->map(function (Payment $payment) use ($patients, $payments): array {
                $patient = $patients->get($payment->patient_id);

                return [
                    'id' => $payment->id,
                    'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
                    'method' => $payment->method,
                    'amount_minor' => $payment->amount_minor,
                    // Remainder is DERIVED by the tested service, never summed in the view.
                    'unallocated_minor' => $payments->unallocated($payment),
                    'currency' => $payment->currency,
                    'received_on' => $payment->received_on->toDateString(),
                    'reference' => $payment->reference,
                    'show_url' => route('billing.payments.show', $payment->id),
                ];
            })->all(),
            'recordUrl' => route('billing.payments.create'),
            'methods' => Payment::METHODS,
            'canManage' => Gate::allows('billing.manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        $invoiceId = $request->query('invoice');
        $invoice = is_string($invoiceId)
            ? Invoice::query()->where('series', Invoice::SERIES_INVOICE)->whereKey($invoiceId)->first()
            : null;

        $target = null;
        $patient = null;
        if ($invoice instanceof Invoice) {
            $balance = InvoiceBalance::query()->where('invoice_id', $invoice->id)->first();
            $patient = Patient::query()->find($invoice->patient_id);
            $target = [
                'id' => $invoice->id,
                'number' => $invoice->number !== null ? $invoice->series.'-'.$invoice->number : null,
                'currency' => $invoice->currency,
                'open_balance_minor' => $balance !== null ? $balance->open_balance_minor : $invoice->open_balance_minor,
                'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
            ];
        }

        return Inertia::render('Billing/Payments/Record', [
            'methods' => Payment::METHODS,
            'invoice' => $target,
            'patientId' => $patient?->id,
            'storeUrl' => route('billing.payments.store'),
            'paymentsUrl' => route('billing.payments.index'),
        ]);
    }

    public function store(Request $request, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        // amount_minor arrives already in integer minor units (the form normalises
        // the major-unit input) — the service validates and owns all money math.
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:'.implode(',', Payment::METHODS)],
            'received_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'patient_id' => ['nullable', 'string', 'exists:patients,id'],
            'invoice_id' => ['nullable', 'string', 'exists:invoices,id'],
            'allocate_amount_minor' => ['nullable', 'integer', 'min:1'],
        ]);

        $patient = isset($validated['patient_id'])
            ? Patient::query()->find($validated['patient_id'])
            : null;

        $invoice = isset($validated['invoice_id'])
            ? Invoice::query()->where('series', Invoice::SERIES_INVOICE)->whereKey($validated['invoice_id'])->first()
            : null;

        $payment = $payments->record(
            $validated['amount_minor'],
            $validated['method'],
            $actor,
            $patient,
            null,
            $invoice?->currency,
            $validated['received_on'],
            $validated['reference'] ?? null,
        );

        // Optional immediate allocation. The payment is already recorded (money WAS
        // received) even if the allocation is refused, so a bad allocation surfaces
        // as an error on the payment detail rather than losing the receipt.
        if ($invoice instanceof Invoice && isset($validated['allocate_amount_minor'])) {
            try {
                $payments->allocate($payment, $invoice, $validated['allocate_amount_minor'], $actor);
            } catch (InvalidArgumentException $e) {
                return redirect()
                    ->route('billing.payments.show', $payment->id)
                    ->withErrors(['allocate' => $e->getMessage()]);
            }
        }

        return redirect()->route('billing.payments.show', $payment->id);
    }

    public function show(string $payment, Request $request, PaymentService $payments): Response
    {
        Gate::authorize('billing.view');
        abort_unless($request->user() instanceof User, 403);
        // Resolve inside the action (not via implicit binding, which runs before the tenant
        // context is set); the BelongsToTenant scope makes a missing/cross-tenant id 404.
        $payment = Payment::query()->whereKey($payment)->firstOrFail();

        $allocations = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->orderBy('allocated_at')
            ->orderBy('id')
            ->get();

        $invoices = Invoice::query()
            ->whereIn('id', $allocations->pluck('invoice_id')->unique()->all())
            ->get()
            ->keyBy('id');

        // An original allocation is "reversed" when a reversal row points back at it.
        $reversedIds = $allocations
            ->pluck('reverses_allocation_id')
            ->filter()
            ->unique()
            ->all();

        $refunds = Refund::query()
            ->where('payment_id', $payment->id)
            ->orderBy('refunded_at')
            ->orderBy('id')
            ->get();

        $patient = Patient::query()->find($payment->patient_id);
        $canManage = Gate::allows('billing.manage');

        return Inertia::render('Billing/Payments/Show', [
            'payment' => [
                'id' => $payment->id,
                'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
                'method' => $payment->method,
                'amount_minor' => $payment->amount_minor,
                'unallocated_minor' => $payments->unallocated($payment),
                'currency' => $payment->currency,
                'received_on' => $payment->received_on->toDateString(),
                'reference' => $payment->reference,
                'payer_reference' => $payment->payer_reference,
            ],
            'allocations' => $allocations->map(function (PaymentAllocation $allocation) use ($invoices, $reversedIds): array {
                $invoice = $invoices->get($allocation->invoice_id);

                return [
                    'id' => $allocation->id,
                    'invoice_number' => $invoice instanceof Invoice && $invoice->number !== null
                        ? $invoice->series.'-'.$invoice->number
                        : null,
                    'invoice_url' => $invoice instanceof Invoice ? route('billing.invoices.show', $invoice->id) : null,
                    'amount_minor' => $allocation->amount_minor,
                    'is_reversal' => $allocation->isReversal(),
                    'reversed' => in_array($allocation->id, $reversedIds, true),
                    'reason' => $allocation->reason,
                    'allocated_on' => $allocation->allocated_at->toDateString(),
                ];
            })->all(),
            'refunds' => $refunds->map(fn (Refund $refund): array => [
                'id' => $refund->id,
                'amount_minor' => $refund->amount_minor,
                'reason' => $refund->reason,
                'refunded_on' => $refund->refunded_at->toDateString(),
            ])->all(),
            'openInvoices' => $this->openInvoicesFor($payment->currency, $payment->patient_id),
            'actions' => [
                'can_manage' => $canManage,
                'allocate_url' => route('billing.payments.allocate', $payment->id),
                'reverse_url' => route('billing.payments.reverse', $payment->id),
                'paymentsUrl' => route('billing.payments.index'),
            ],
        ]);
    }

    public function allocate(string $payment, Request $request, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $payment = Payment::query()->whereKey($payment)->firstOrFail();

        $validated = $request->validate([
            'invoice_id' => ['required', 'string', 'exists:invoices,id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        $invoice = Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereKey($validated['invoice_id'])
            ->firstOrFail();

        try {
            $payments->allocate($payment, $invoice, $validated['amount_minor'], $actor);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('billing.payments.show', $payment->id)->withErrors(['allocate' => $e->getMessage()]);
        }

        return redirect()->route('billing.payments.show', $payment->id);
    }

    public function reverse(string $payment, Request $request, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $payment = Payment::query()->whereKey($payment)->firstOrFail();

        $validated = $request->validate([
            'allocation_id' => ['required', 'string', 'exists:payment_allocations,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $allocation = PaymentAllocation::query()->whereKey($validated['allocation_id'])->firstOrFail();
        abort_unless($allocation->payment_id === $payment->id, 404);

        try {
            $payments->reverseAllocation($allocation, $validated['reason'], $actor);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('billing.payments.show', $payment->id)->withErrors(['reverse' => $e->getMessage()]);
        }

        return redirect()->route('billing.payments.show', $payment->id);
    }

    /**
     * Open invoices (issued / partially paid, positive open balance) a remainder
     * could be allocated to — scoped to the payment's patient when it has one, and
     * to the payment currency (the service refuses a currency mismatch anyway).
     *
     * @return list<array<string, mixed>>
     */
    private function openInvoicesFor(string $currency, ?string $patientId): array
    {
        $openBalances = InvoiceBalance::query()
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->where('open_balance_minor', '>', 0)
            ->get()
            ->keyBy('invoice_id');

        $invoices = Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->where('currency', $currency)
            ->whereIn('id', $openBalances->keys()->all())
            ->when($patientId !== null, fn ($query) => $query->where('patient_id', $patientId))
            ->orderByDesc('issue_date')
            ->limit(50)
            ->get();

        return $invoices->map(function (Invoice $invoice) use ($openBalances): array {
            $balance = $openBalances->get($invoice->id);

            return [
                'id' => $invoice->id,
                'number' => $invoice->number !== null ? $invoice->series.'-'.$invoice->number : null,
                'open_balance_minor' => $balance instanceof InvoiceBalance ? $balance->open_balance_minor : 0,
                'currency' => $invoice->currency,
            ];
        })->all();
    }
}
