<?php

namespace App\Http\Controllers;

use App\AiCore\Support\ClinicalSummarySourceValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\AiCore\Models\AgentAction;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;

class ClinicalSummaryInsertController
{
    public function store(Request $request, string $patient, ClinicalSummarySourceValidator $validator): RedirectResponse
    {
        Gate::authorize('note.write');

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $data = $request->validate([
            'action_id' => ['required', 'string'],
        ]);

        $action = AgentAction::query()
            ->whereKey((string) $data['action_id'])
            ->where('tool_key', 'clinical.summarize_since_last_visit')
            ->firstOrFail();

        $output = $action->proposed_output;
        if (($output['patient_id'] ?? null) !== $record->id) {
            abort(404);
        }

        $lines = $output['lines'] ?? [];
        if (! is_array($lines)) {
            throw new InvalidArgumentException('Clinical summary draft lines are missing.');
        }

        $validator->validate($record->id, $lines);
        $note = $this->editableDraftForCurrentClinician($record);
        $note->forceFill([
            'plan' => trim(implode("\n", array_filter([
                $note->plan,
                $this->acceptedSummaryText($lines),
            ]))),
        ])->save();

        return redirect()->route('clinical.notes.edit', $note->id);
    }

    private function editableDraftForCurrentClinician(Patient $patient): ClinicalNote
    {
        $profile = StaffProfile::query()
            ->where('user_id', auth()->id())
            ->firstOrFail();

        return ClinicalNote::query()
            ->where('patient_id', $patient->id)
            ->where('author_id', $profile->id)
            ->where('status', ClinicalNote::STATUS_DRAFT)
            ->latest('updated_at')
            ->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function acceptedSummaryText(array $lines): string
    {
        return collect($lines)
            ->map(function (array $line): string {
                $source = $line['source'] ?? [];
                $label = is_array($source) ? (string) ($source['label'] ?? '') : '';

                return '- '.trim((string) $line['text']).' ['.trim($label).']';
            })
            ->implode("\n");
    }
}
