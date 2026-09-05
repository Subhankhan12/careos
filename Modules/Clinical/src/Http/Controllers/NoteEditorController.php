<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\Allergy;
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

        $chain = $notes->versionsFor($record);
        $current = $chain->last();

        return Inertia::render('Clinical/NoteEditor', [
            'note' => $this->notePayload($record),
            'encounter' => $this->encounterPayload($encounter),
            'patient' => $this->patientPayload($encounter),
            'template' => $this->templatePayload($template),
            'versions' => $chain->map(fn (ClinicalNote $version): array => $this->versionPayload($version))->all(),
            /*
             * PC.P4 — where this version sits in the chain, computed SERVER-side from the
             * existing append-only chain. It is the difference between "this is the note" and
             * "this is an older version of the note", which a clinician reading a superseded
             * v1 cannot otherwise see. DISPLAY ONLY: it re-implements nothing and bypasses
             * nothing — the chain, the immutability and the amend path are exactly as they were.
             */
            'chain' => [
                'is_superseded' => $current instanceof ClinicalNote && $current->id !== $record->id,
                'current' => $current instanceof ClinicalNote ? [
                    'id' => $current->id,
                    'version' => $current->version,
                    'status' => $current->status,
                    'edit_url' => route('clinical.notes.edit', $current->id),
                ] : null,
            ],
            /*
             * The patient's RECORDED active allergies (PC.P4, the B1 pattern).
             *
             * `NoteEditor.vue` has carried a dormant `allergies` prop and a hidden mini-banner
             * since it was built — "not part of the note-editor payload today" — the same gap
             * PC.P1 closed on Patient 360. Landing it lights the banner with no page rewrite.
             * No boundary question arises here: `Allergy` is a `Modules\Clinical` model and this
             * controller is Clinical's own, so there is no cross-module read (D-017).
             *
             * DISPLAY-ONLY, ordered by SUBSTANCE and never by severity: the recorded substance,
             * reaction and clinician-recorded severity, shown as facts. No cross-reactivity, no
             * ranking, no interaction check — that remains the certified `MedicationSafetyProvider`
             * seam, whose only implementation is a null object.
             */
            'allergies' => $this->allergies($record),
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

        // AN AMENDMENT IS A NEW VERSION, AND ITS AUTHOR IS WHOEVER IS WRITING IT (QA-FIX.2a, D-195).
        // This used to pass $this->authorFor($record) — the SUPERSEDED version's author — so a
        // correction written by Dr. B was recorded as Dr. A's work. The original version keeps its
        // own author untouched; that is what the version chain is for.
        $author = StaffProfile::forUser($actor);

        if (! $author instanceof StaffProfile) {
            return back()->withErrors([
                'reason' => 'Your user account has no staff profile, so an amendment cannot record who wrote it.',
            ]);
        }

        $amendment = $notes->amend($record, [], $data['reason'], $author, $actor);

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
     * The patient's ACTIVE recorded allergies, as documented facts.
     *
     * Ordered by substance — deliberately NOT by severity, because ordering by badness would be
     * the system asserting a priority it has no business asserting. Mirrors the composition
     * `PatientShowController` and `AppointmentDetailController` already use, so no two surfaces
     * can disagree about what a patient is allergic to.
     *
     * @return list<array<string, mixed>>
     */
    private function allergies(ClinicalNote $note): array
    {
        return Allergy::query()
            ->where('patient_id', $note->patient_id)
            ->where('status', Allergy::STATUS_ACTIVE)
            ->orderBy('substance')
            ->get()
            ->map(fn (Allergy $allergy): array => [
                'id' => $allergy->id,
                'substance' => $allergy->substance,
                'reaction' => $allergy->reaction,
                'severity' => $allergy->severity,
            ])
            ->all();
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
            // THE SIGNATURE NAMES THE SIGNATORY, NOT THE AUTHOR (QA-FIX.2a, D-195). The lock line
            // used to render author_name under a "Signed ·" label, so the screen asserted a
            // signature by whoever the note was attributed to. These are genuinely different people
            // in real data — the seeded radiology reports are authored by the radiologist and signed
            // by another clinician — so both are surfaced and the view shows them distinctly.
            'signed_by_name' => $this->signatoryName($note),
            'signed_by_is_author' => $this->signatoryIsAuthor($note),
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
     * The name of the person who SIGNED the note, resolved from `signed_by`.
     *
     * `signed_by` holds a `users.id` while `author_id` holds a `staff_profiles.id` — two identity
     * namespaces on one row (D-196 records that, and this gate deliberately does not deepen it).
     * So the signatory is resolved through the user, preferring their staff profile's display name
     * so both lines read the same way, and falling back to the user's own name.
     */
    private function signatoryName(ClinicalNote $note): ?string
    {
        if ($note->signed_by === null) {
            return null;
        }

        $user = User::query()->whereKey($note->signed_by)->first();

        if (! $user instanceof User) {
            return null;
        }

        $profile = StaffProfile::forUser($user);

        return $profile instanceof StaffProfile ? $this->staffName($profile) : $user->name;
    }

    /**
     * Whether the signatory is the same person as the author.
     *
     * Compared across the namespace split: the author's `staff_profiles.user_id` against the note's
     * `signed_by` (`users.id`). When they differ the view names both, because a note drafted by one
     * clinician and signed by another is a real, legitimate state — the seeded radiology reports are
     * exactly that — and collapsing them is how `P2-C1` presented a signature nobody had made.
     */
    private function signatoryIsAuthor(ClinicalNote $note): bool
    {
        if ($note->signed_by === null) {
            return true;
        }

        $author = StaffProfile::query()->whereKey($note->author_id)->first();

        return $author instanceof StaffProfile
            && $author->user_id !== null
            && (string) $author->user_id === (string) $note->signed_by;
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
