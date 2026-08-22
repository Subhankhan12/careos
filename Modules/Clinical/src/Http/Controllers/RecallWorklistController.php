<?php

namespace Modules\Clinical\Http\Controllers;

use App\AiCore\Agents\FollowUpAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\RecallRule;
use Modules\Clinical\Services\RecallService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Platform\Models\User;

/**
 * The recall due list — a worklist over the EXISTING recall backend.
 *
 * ORDERING IS A DATE SORT, NOT A RANKING. Rows come back `due_on ASC`, which puts the longest
 * overdue first as a side effect of sorting a RECORDED DATE. That distinction is the whole fence
 * on this screen: "most overdue first" is arithmetic on a calendar, whereas a priority score is
 * the system deciding which patient matters more. There is no priority column in `recalls`, none
 * is computed here, and nothing on the page is tinted or banded by how overdue a row is (D-169).
 *
 * `due_in_days` is the SAME plain calendar interval the chart rail already uses (PC.P2): whole
 * days between two dates, negative once the date has passed. It carries no urgency and no colour.
 *
 * EVERY TRANSITION GOES THROUGH `RecallService`, which owns the legal state graph
 * (due → contacted|booked|completed|dismissed, contacted → booked|completed|dismissed,
 * booked → completed|dismissed), gates on `note.write` and audits each move. This controller
 * writes no status of its own. **Completing a recall closes the recall — it books nothing**, so
 * no scheduling path is touched here at all.
 *
 * CONTACTING A PATIENT IS A HUMAN ACT. The agent may DRAFT wording through the existing
 * `clinical.draft_recall_message` tool, whose ceiling is SUGGEST — so `AgentRuntime::runTool()`
 * can only ever `propose()` to the capped approval queue and return `pending`. Nothing is sent
 * from this screen, and no ceiling is raised. The clinician supplies the template; the tool
 * renders it, refuses medical advice, and blocks on missing comms consent.
 *
 * WHAT THE WIREFRAME DRAWS THAT IS REFUSED, AND WHY (stated on screen):
 *  - **"sends routine ones automatically at Level 1"** — auto-sending patient outreach. The tool's
 *    SUGGEST ceiling forbids it structurally; raising it is the only way to get there, and that is
 *    precisely the fence. Drafts wait for a human in the approval queue.
 *  - **"hands clinical cases to a person"** (perio maintenance, phone-only) — the agent deciding
 *    which recalls are clinically sensitive is a computed triage. Every row is handled by a human.
 *  - **A "needs review" / priority column** — no such field exists and none is computed.
 */
class RecallWorklistController
{
    public function index(Request $request, ConsentService $consents): Response
    {
        Gate::authorize('patient.view');

        $status = $this->status($request);
        $ruleId = $this->ruleId($request);
        $withinDays = $this->withinDays($request);

        $query = Recall::query();

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($ruleId !== null) {
            $query->where('rule_id', $ruleId);
        }

        if ($withinDays !== null) {
            $query->where('due_on', '<=', Carbon::now()->startOfDay()->addDays($withinDays)->toDateString());
        }

        /*
         * `due_on` ASCENDING — a sort on a RECORDED DATE. The longest-overdue row surfaces first
         * because its date is earliest, not because anything scored it.
         */
        $recalls = $query->orderBy('due_on')->orderBy('id')->get();

        $patients = Patient::query()
            ->whereIn('id', $recalls->pluck('patient_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $rules = RecallRule::query()->get()->keyBy('id');

        /*
         * ONE read row PER PATIENT DISCLOSED — not one per render.
         *
         * This worklist shows many patients at once, so a single row for the whole page would
         * record the disclosure against one patient and leave every other patient's access log
         * (PC.P5) silent about a real disclosure of their record. The single-row rule from
         * PC.P1/P5/P6 is about not adding a SECOND audit mechanism, which this does not: it is
         * the same `auditRead()` path, called once per patient shown.
         */
        foreach ($patients as $patient) {
            $patient->auditRead(['surface' => 'recall_worklist']);
        }

        $today = Carbon::now()->startOfDay();

        return Inertia::render('Clinical/Recalls', [
            'recalls' => $recalls
                ->map(function (Recall $recall) use ($patients, $rules, $today, $consents): array {
                    $patient = $patients->get($recall->patient_id);
                    $rule = $rules->get($recall->rule_id);

                    return [
                        'id' => $recall->id,
                        'status' => $recall->status,
                        'due_on' => $recall->due_on->toDateString(),
                        /*
                         * The plain calendar interval (PC.P2's formulation, unchanged): whole days
                         * between two dates, negative once passed. Arithmetic on a date — it is
                         * not a judgment, carries no urgency and must tint nothing.
                         */
                        'due_in_days' => (int) $today->diffInDays($recall->due_on->startOfDay(), false),
                        'rule_id' => $recall->rule_id,
                        'rule_name' => $rule?->name,
                        'patient' => $patient === null ? null : [
                            'id' => $patient->id,
                            'mrn' => $patient->mrn,
                            'name' => trim($patient->first_name.' '.$patient->last_name),
                            'chart_url' => route('clinical.chart', $patient->id),
                        ],
                        /*
                         * A RECORDED consent fact, shown so a clinician understands why a draft
                         * would be blocked. It is read from the consent register, not inferred.
                         */
                        'has_comms_consent' => $patient !== null && $consents->has($patient, 'comms.email'),
                        'urls' => [
                            'transition' => route('clinical.recalls.transition', $recall->id),
                            'draft' => route('clinical.recalls.draft', $recall->id),
                        ],
                    ];
                })
                ->all(),
            // Filter options built from the tenant's REAL rules, never a hardcoded taxonomy.
            'rules' => $rules->values()
                ->map(fn (RecallRule $rule): array => ['id' => $rule->id, 'name' => $rule->name])
                ->all(),
            'filters' => [
                'status' => $status,
                'rule_id' => $ruleId,
                'within_days' => $withinDays,
            ],
            'statuses' => [
                Recall::STATUS_DUE,
                Recall::STATUS_CONTACTED,
                Recall::STATUS_BOOKED,
                Recall::STATUS_COMPLETED,
                Recall::STATUS_DISMISSED,
            ],
            'totals' => ['shown' => $recalls->count()],
            'actions' => [
                'can_write' => Gate::allows('note.write'),
                'approvals_url' => route('governance.approvals.index'),
            ],
        ]);
    }

    /**
     * A real transition through the existing service. The service owns the legal graph and
     * re-checks `note.write`; this method validates the requested status and calls it.
     */
    public function transition(string $recall, Request $request, RecallService $recalls): RedirectResponse
    {
        Gate::authorize('patient.view');
        $record = Recall::query()->whereKey($recall)->firstOrFail();

        /** @var array{status: string} $data */
        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', [
                Recall::STATUS_CONTACTED,
                Recall::STATUS_BOOKED,
                Recall::STATUS_COMPLETED,
                Recall::STATUS_DISMISSED,
            ])],
        ]);

        $recalls->transition($record, $data['status'], $this->actor($request));

        return redirect()->back();
    }

    /**
     * Ask the EXISTING recall agent to draft outreach wording.
     *
     * The clinician supplies the template. The tool's ceiling is SUGGEST, so the runtime can only
     * `propose()` to the capped approval queue — this returns `pending` and a human approves and
     * sends. NOTHING is sent here, and no ceiling is changed.
     */
    public function draft(string $recall, Request $request, FollowUpAgent $agent): RedirectResponse
    {
        Gate::authorize('patient.view');
        $record = Recall::query()->whereKey($recall)->firstOrFail();

        /** @var array{template: string} $data */
        $data = $request->validate([
            // The clinician's OWN wording. There is no template library, so nothing is prefilled.
            'template' => ['required', 'string', 'max:2000'],
        ]);

        $agent->draftRecallMessage($record->id, $data['template'], $this->actor($request));

        return redirect()->back();
    }

    private function status(Request $request): ?string
    {
        $status = $request->query('status');

        return is_string($status) && in_array($status, [
            Recall::STATUS_DUE,
            Recall::STATUS_CONTACTED,
            Recall::STATUS_BOOKED,
            Recall::STATUS_COMPLETED,
            Recall::STATUS_DISMISSED,
        ], true) ? $status : null;
    }

    private function ruleId(Request $request): ?string
    {
        $ruleId = $request->query('rule_id');

        return is_string($ruleId) && trim($ruleId) !== '' ? $ruleId : null;
    }

    private function withinDays(Request $request): ?int
    {
        $within = $request->query('within_days');

        if (! is_string($within) || ! in_array($within, ['0', '7', '30', '90'], true)) {
            return null;
        }

        return (int) $within;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
