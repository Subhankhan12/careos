<?php

namespace App\Http\Controllers;

use App\AiCore\Agents\ClinicalSummaryAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\AiCore\Models\AgentAction;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

class ClinicalSummaryDraftController
{
    public function store(Request $request, string $patient, ClinicalSummaryAgent $agent): RedirectResponse
    {
        Gate::authorize('note.write');

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'request' => ['nullable', 'string', 'max:500'],
        ]);

        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        $result = $agent->summarize(
            $record->id,
            (string) $data['from'],
            (string) $data['to'],
            $actor,
            (string) ($data['request'] ?? ''),
        );

        $draft = [
            'status' => $result['status'],
            'label' => $result['label'],
            'human_handoff' => $result['human_handoff'],
            'lines' => [],
            'action_id' => null,
            'patient_id' => $record->id,
            'insert_url' => route('clinical.summary.insert', $record->id),
        ];

        if (($result['action'] ?? null) instanceof AgentAction) {
            /** @var AgentAction $action */
            $action = $result['action'];
            $draft['action_id'] = $action->id;
            $draft['lines'] = $action->proposed_output['lines'] ?? [];
        }

        return redirect()
            ->route('clinical.chart', $record->id)
            ->with('clinical_summary_draft', $draft);
    }
}
