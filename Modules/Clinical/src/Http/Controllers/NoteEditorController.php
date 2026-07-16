<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NoteTemplate;
use Modules\Clinical\Models\TextSnippet;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\SnippetService;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;

class NoteEditorController
{
    public function edit(string $note, Request $request, ClinicalNoteService $notes, SnippetService $snippets): Response
    {
        Gate::authorize('patient.view');

        $record = ClinicalNote::query()->whereKey($note)->firstOrFail();
        $record->auditRead(['surface' => 'note_editor']);
        $encounter = $this->encounterFor($record);
        $template = $this->templateFor($record);

        return Inertia::render('Clinical/NoteEditor', [
            'note' => $this->notePayload($record),
            'encounter' => $this->encounterPayload($encounter),
            'patient' => $this->patientPayload($encounter),
            'template' => $this->templatePayload($template),
            'versions' => $notes->versionsFor($record)->map(fn (ClinicalNote $version): array => $this->versionPayload($version))->all(),
            // ADDITIVE (P0P.G10): the current clinician's dot-phrases, pre-expanded
            // server-side with the whitelisted non-clinical placeholders only.
            'snippets' => $this->snippetPayload($request, $snippets, $encounter),
            'actions' => [
                'save_url' => route('clinical.notes.update', $record->id),
                'sign_url' => route('clinical.notes.sign', $record->id),
                'amend_url' => route('clinical.notes.amend', $record->id),
                'chart_url' => route('clinical.chart', $record->patient_id),
                'can_write' => Gate::allows('note.write'),
                'can_sign' => Gate::allows('note.sign'),
            ],
        ]);
    }

    public function store(string $encounter, Request $request, ClinicalNoteService $notes): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Encounter::query()->whereKey($encounter)->firstOrFail();
        Gate::authorize('note.write');

        $template = $this->templateFromRequest($request);
        $note = $notes->saveDraft(
            $record,
            $this->practitionerFor($record),
            $this->validatedSections($request),
            $actor,
            null,
            $template,
        );

        return redirect()->route('clinical.notes.edit', $note->id);
    }

    public function update(string $note, Request $request, ClinicalNoteService $notes): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = ClinicalNote::query()->whereKey($note)->firstOrFail();
        Gate::authorize('note.write');

        if ($record->status !== ClinicalNote::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'note' => 'Only draft clinical notes are editable.',
            ]);
        }

        $notes->saveDraft(
            $this->encounterFor($record),
            $this->authorFor($record),
            $this->validatedSections($request),
            $actor,
            $record,
            $this->templateFor($record),
        );

        return redirect()->route('clinical.notes.edit', $record->id);
    }

    public function sign(string $note, Request $request, ClinicalNoteService $notes): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = ClinicalNote::query()->whereKey($note)->firstOrFail();
        Gate::authorize('note.sign');

        $signed = $notes->sign($record, $actor);

        return redirect()->route('clinical.notes.edit', $signed->id);
    }

    public function amend(string $note, Request $request, ClinicalNoteService $notes): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = ClinicalNote::query()->whereKey($note)->firstOrFail();
        Gate::authorize('note.write');

        /** @var array{reason: string} $data */
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $amendment = $notes->amend($record, [], $data['reason'], $this->authorFor($record), $actor);

        return redirect()->route('clinical.notes.edit', $amendment->id);
    }

    /**
     * @return array{subjective: string|null, objective: string|null, assessment: string|null, plan: string|null}
     */
    private function validatedSections(Request $request): array
    {
        /** @var array{subjective?: string|null, objective?: string|null, assessment?: string|null, plan?: string|null} $data */
        $data = $request->validate([
            'subjective' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'assessment' => ['nullable', 'string'],
            'plan' => ['nullable', 'string'],
        ]);

        return [
            'subjective' => $data['subjective'] ?? null,
            'objective' => $data['objective'] ?? null,
            'assessment' => $data['assessment'] ?? null,
            'plan' => $data['plan'] ?? null,
        ];
    }

    private function templateFromRequest(Request $request): ?NoteTemplate
    {
        $templateId = $request->input('template_id');

        if (! is_string($templateId) || trim($templateId) === '') {
            return null;
        }

        return NoteTemplate::query()->whereKey($templateId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function notePayload(ClinicalNote $note): array
    {
        return [
            'id' => $note->id,
            'encounter_id' => $note->encounter_id,
            'patient_id' => $note->patient_id,
            'author_id' => $note->author_id,
            'author_name' => $this->staffName($this->authorFor($note)),
            'subjective' => $note->subjective,
            'objective' => $note->objective,
            'assessment' => $note->assessment,
            'plan' => $note->plan,
            'status' => $note->status,
            'signed_at' => $note->signed_at?->toDateTimeString(),
            'signed_by' => $note->signed_by,
            'version' => $note->version,
            'supersedes_id' => $note->supersedes_id,
            'amendment_reason' => $note->amendment_reason,
            'is_read_only' => $note->status === ClinicalNote::STATUS_SIGNED,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionPayload(ClinicalNote $note): array
    {
        return [
            'id' => $note->id,
            'version' => $note->version,
            'status' => $note->status,
            'author_name' => $this->staffName($this->authorFor($note)),
            'created_at' => $note->created_at?->toDateTimeString(),
            'signed_at' => $note->signed_at?->toDateTimeString(),
            'amendment_reason' => $note->amendment_reason,
            'edit_url' => route('clinical.notes.edit', $note->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function encounterPayload(Encounter $encounter): array
    {
        return [
            'id' => $encounter->id,
            'status' => $encounter->status,
            'type' => $encounter->type,
            'started_at' => $encounter->started_at->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function patientPayload(Encounter $encounter): array
    {
        $patient = Patient::query()->whereKey($encounter->patient_id)->firstOrFail();

        return [
            'id' => $patient->id,
            'mrn' => $patient->mrn,
            'name' => trim($patient->first_name.' '.$patient->last_name),
            'chart_url' => route('clinical.chart', $patient->id),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function templatePayload(?NoteTemplate $template): ?array
    {
        if (! $template instanceof NoteTemplate) {
            return null;
        }

        return [
            'id' => $template->id,
            'name' => $template->name,
            'required_sections' => $template->required_sections,
        ];
    }

    private function staffName(StaffProfile $profile): string
    {
        return $profile->display_name !== '' ? $profile->display_name : trim($profile->first_name.' '.$profile->last_name);
    }

    /**
     * The current clinician's snippet list, each rendered with the whitelisted
     * non-clinical placeholders. The component only inserts `body` — the server
     * owns all (safe) substitution.
     *
     * @return list<array{trigger: string, title: string, scope: string, body: string}>
     */
    private function snippetPayload(Request $request, SnippetService $snippets, Encounter $encounter): array
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return [];
        }

        $staff = $snippets->staffFor($actor);
        $context = $this->snippetContext($encounter, $actor, $staff);

        return $snippets->list($staff)->map(fn (TextSnippet $snippet): array => [
            'trigger' => $snippet->trigger,
            'title' => $snippet->title,
            'scope' => $snippet->scope,
            'body' => $snippets->expand($snippet, $context),
        ])->all();
    }

    /**
     * The FIXED whitelist placeholder context — NON-clinical only.
     *
     * @return array<string, string>
     */
    private function snippetContext(Encounter $encounter, User $actor, ?StaffProfile $staff): array
    {
        $patient = Patient::query()->whereKey($encounter->patient_id)->firstOrFail();
        $branch = Branch::query()->whereKey($encounter->branch_id)->first();

        return [
            'date' => Carbon::now()->toDateString(),
            'patient_first_name' => $patient->first_name,
            'patient_dob' => $patient->date_of_birth->toDateString(),
            'clinician_name' => $staff !== null ? $this->staffName($staff) : (string) $actor->name,
            'branch_name' => $branch !== null ? $branch->name : '',
        ];
    }

    private function encounterFor(ClinicalNote $note): Encounter
    {
        return Encounter::query()->whereKey($note->encounter_id)->firstOrFail();
    }

    private function authorFor(ClinicalNote $note): StaffProfile
    {
        return StaffProfile::query()->whereKey($note->author_id)->firstOrFail();
    }

    private function practitionerFor(Encounter $encounter): StaffProfile
    {
        return StaffProfile::query()->whereKey($encounter->practitioner_id)->firstOrFail();
    }

    private function templateFor(ClinicalNote $note): ?NoteTemplate
    {
        if ($note->template_id === null) {
            return null;
        }

        return NoteTemplate::query()->whereKey($note->template_id)->firstOrFail();
    }
}
