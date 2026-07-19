<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\IssueService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;

/**
 * Assemble a draft invoice from a patient's already-validated, not-yet-invoiced
 * charges and issue it. WRITES gate on `billing.manage` and go through
 * IssueService (createDraftFromCharges → issue) — the gapless-numbering + PDF
 * path. The charges were priced by the TariffResolver at capture time, so nothing
 * here prices or sums anything: individual charge line totals are shown as-is and
 * the invoice total is whatever IssueService computes on issue.
 */
class InvoiceDraftController
{
    public function create(Request $request, SettingsService $settings): Response
    {
        Gate::authorize('billing.manage');
        abort_unless($request->user() instanceof User, 403);

        // The tenant's settlement currency — the same one IssueService will stamp on
        // the issued invoice — so the preview never mislabels a non-EUR (e.g. CHF) tenant.
        $currency = (string) $settings->get('currency', 'EUR');

        $patientId = $request->query('patient');
        $patient = is_string($patientId) ? Patient::query()->find($patientId) : null;

        if ($patient instanceof Patient) {
            $charges = Charge::query()
                ->where('patient_id', $patient->id)
                ->where('status', Charge::STATUS_VALIDATED)
                ->whereNull('invoice_id')
                ->orderBy('service_date')
                ->orderBy('id')
                ->get();

            return Inertia::render('Billing/Invoices/New', [
                'patient' => [
                    'id' => $patient->id,
                    'name' => trim($patient->first_name.' '.$patient->last_name),
                    'mrn' => $patient->mrn,
                ],
                'charges' => $charges->map(fn (Charge $charge): array => [
                    'id' => $charge->id,
                    'code' => $charge->code,
                    'description' => $charge->description,
                    'service_date' => $charge->service_date->toDateString(),
                    'quantity' => $charge->quantity,
                    'unit_price_minor' => $charge->unit_price_minor,
                    'vat_rate_bp' => $charge->vat_rate_bp,
                    'line_total_minor' => $charge->line_total_minor,
                    'currency' => $currency,
                ])->all(),
                'payerTypes' => [Invoice::PAYER_SELF_PAY, Invoice::PAYER_PRIVATE_INSURANCE],
                'candidates' => [],
                'storeUrl' => route('billing.invoices.store'),
                'invoicesUrl' => route('billing.invoices.index'),
            ]);
        }

        // No patient chosen yet: list the patients who HAVE invoiceable charges. A
        // per-patient charge COUNT only (never a money sum — totals belong to the engine).
        $invoiceable = Charge::query()
            ->where('status', Charge::STATUS_VALIDATED)
            ->whereNull('invoice_id')
            ->get();

        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($invoiceable as $charge) {
            $counts[$charge->patient_id] = ($counts[$charge->patient_id] ?? 0) + 1;
        }

        $patients = Patient::query()->whereIn('id', array_keys($counts))->get();

        return Inertia::render('Billing/Invoices/New', [
            'patient' => null,
            'charges' => [],
            'payerTypes' => [Invoice::PAYER_SELF_PAY, Invoice::PAYER_PRIVATE_INSURANCE],
            'candidates' => $patients->map(fn (Patient $p): array => [
                'id' => $p->id,
                'name' => trim($p->first_name.' '.$p->last_name),
                'mrn' => $p->mrn,
                'charge_count' => $counts[$p->id] ?? 0,
                'create_url' => route('billing.invoices.create', ['patient' => $p->id]),
            ])->all(),
            'storeUrl' => route('billing.invoices.store'),
            'invoicesUrl' => route('billing.invoices.index'),
        ]);
    }

    public function store(Request $request, IssueService $issueService): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $validated = $request->validate([
            'patient_id' => ['required', 'string', 'exists:patients,id'],
            'charge_ids' => ['required', 'array', 'min:1'],
            'charge_ids.*' => ['string', 'exists:charges,id'],
            'payer_type' => ['required', 'string', 'in:'.Invoice::PAYER_SELF_PAY.','.Invoice::PAYER_PRIVATE_INSURANCE],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $patient = Patient::query()->findOrFail($validated['patient_id']);

        // Only this patient's still-invoiceable charges — the service re-checks too.
        $charges = Charge::query()
            ->whereIn('id', $validated['charge_ids'])
            ->where('patient_id', $patient->id)
            ->where('status', Charge::STATUS_VALIDATED)
            ->whereNull('invoice_id')
            ->get();

        if ($charges->isEmpty()) {
            return redirect()
                ->route('billing.invoices.create', ['patient' => $patient->id])
                ->withErrors(['charge_ids' => 'Select at least one open, validated charge to invoice.']);
        }

        try {
            $draft = $issueService->createDraftFromCharges(
                $patient,
                $charges->all(),
                $actor,
                $validated['payer_type'],
                null,
                now(),
                now()->addDays($validated['due_in_days'] ?? 30),
            );
            $invoice = $issueService->issue($draft, $actor);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('billing.invoices.create', ['patient' => $patient->id])
                ->withErrors(['charge_ids' => $e->getMessage()]);
        }

        return redirect()->route('billing.invoices.show', $invoice->id);
    }
}
