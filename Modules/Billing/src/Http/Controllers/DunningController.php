<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\DunningEvent;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Services\DunningService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;

/**
 * Staff dunning worklist: overdue invoices with an open balance and the dunning
 * level each has reached. READS gate on `billing.view`. The "send reminders"
 * action gates on `billing.manage` and dispatches to DunningService::evaluate —
 * the single, idempotent, settings-policy-driven engine (re-running the same day
 * is a no-op; a dunning fee is a NEW charge; the original invoice is untouched;
 * dunning is legal-comms, NOT consent-gated). No overdue/level math is computed
 * here — open balances are the stored projection and levels are the persisted
 * dunning_events; the view only formats integer minor units.
 */
class DunningController
{
    public function index(Request $request): Response
    {
        Gate::authorize('billing.view');
        abort_unless($request->user() instanceof User, 403);

        $today = now()->toDateString();

        $balances = InvoiceBalance::query()
            ->where('open_balance_minor', '>', 0)
            ->get()
            ->keyBy('invoice_id');

        $invoices = Invoice::query()
            ->where('series', Invoice::SERIES_INVOICE)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->whereIn('id', $balances->keys()->all())
            ->orderBy('due_date')
            ->limit(100)
            ->get();

        $patients = Patient::query()
            ->whereIn('id', $invoices->pluck('patient_id')->filter()->all())
            ->get()
            ->keyBy('id');

        // Persisted dunning events, folded per invoice into {max level, latest event}.
        $events = DunningEvent::query()
            ->whereIn('invoice_id', $invoices->pluck('id')->all())
            ->orderBy('triggered_on')
            ->orderBy('id')
            ->get();

        /** @var array<string, array{level: int, status: string, on: string}> $latest */
        $latest = [];
        /** @var array<string, int> $maxLevel */
        $maxLevel = [];
        foreach ($events as $event) {
            $maxLevel[$event->invoice_id] = max($maxLevel[$event->invoice_id] ?? 0, $event->level);
            $latest[$event->invoice_id] = [
                'level' => $event->level,
                'status' => $event->status,
                'on' => $event->triggered_on->toDateString(),
            ];
        }

        $rows = $invoices->map(function (Invoice $invoice) use ($balances, $patients, $latest, $maxLevel): array {
            $balance = $balances->get($invoice->id);
            $patient = $patients->get($invoice->patient_id);

            return [
                'id' => $invoice->id,
                'number' => $invoice->number !== null ? $invoice->series.'-'.$invoice->number : null,
                'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
                'due_date' => $invoice->due_date?->toDateString(),
                'open_balance_minor' => $balance instanceof InvoiceBalance ? $balance->open_balance_minor : 0,
                'currency' => $invoice->currency,
                'dunning_paused' => $balance instanceof InvoiceBalance ? $balance->dunning_paused : false,
                'current_level' => $maxLevel[$invoice->id] ?? 0,
                'last_event' => $latest[$invoice->id] ?? null,
                'show_url' => route('billing.invoices.show', $invoice->id),
            ];
        })->all();

        return Inertia::render('Billing/Dunning/Index', [
            'rows' => $rows,
            'counters' => [
                'overdue' => count($rows),
                'no_reminder' => count(array_filter($rows, fn (array $r): bool => $r['current_level'] === 0)),
            ],
            'actions' => [
                'can_manage' => Gate::allows('billing.manage'),
                'run_url' => route('billing.dunning.run'),
            ],
        ]);
    }

    public function run(Request $request, DunningService $dunning): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        // The whole idempotent, policy-driven engine lives in DunningService; this
        // just triggers the tenant-wide run as of today. Re-running is a no-op.
        $tenant = Tenant::query()->findOrFail($actor->tenant_id);
        $dunning->evaluate($tenant, now(), $actor);

        return redirect()->route('billing.dunning.index');
    }
}
