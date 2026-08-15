<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Services\DunningService;
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
 */
class AccountDetailController
{
    public function show(Request $request, string $account, MetricsService $metrics, SettingsService $settings): Response
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
            // only displays it (the running balance + the tie come from the engine).
            'ledger' => $metrics->accountLedger($actor, (string) $patient->id, now()),
            // ARDETAIL.P2 — the account's dunning timeline, a READ-ONLY display of the real
            // state machine. The billing policy (which the dunning service owns) maps each level
            // to its fee_code, so the engine can attribute each event's real captured fee charge.
            'dunning' => $metrics->accountDunning($actor, (string) $patient->id, $this->dunningFeeCodeByLevel($settings), now()),
            'links' => [
                'report' => route('billing.report'),
                'dunning' => route('billing.dunning.index'),
            ],
        ]);
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
