<?php

namespace Modules\Surgery\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Surgery\Exceptions\SurgicalCaseException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalCaseEncounter;
use Modules\Surgery\Models\SurgicalCaseEvent;
use Modules\Surgery\Models\SurgicalCaseTeamMember;
use Modules\Surgery\Services\SurgicalCaseService;

/**
 * Surgical cases (SURGERY.G2) — PRESENTATIONAL over `SurgicalCaseService`. The OR case board + a case detail
 * that drives the legal-only lifecycle (pre_op → in_progress → completed → post_op / cancel), records the
 * surgical team + the anesthetist-ASSIGNED ASA/Mallampati, and authors pre-op / operative / post-op notes by
 * REUSING the existing sign-and-lock clinical note editor. String-id (FIX.1). Managing a case is gated
 * `surgery.manage`; authoring a note is `note.write`; the case read is read-logged (`patient.view`-scoped
 * records). Record-not-judge: nothing here computes a surgical risk.
 */
class SurgicalCaseController
{
    public function index(Request $request): Response
    {
        Gate::authorize('surgery.manage');
        abort_unless($request->user() instanceof User, 403);

        return Inertia::render('Surgery/CaseBoard', [
            'cases' => SurgicalCase::query()->with(['patient', 'primarySurgeon'])->orderByDesc('scheduled_at')->get()
                ->map(fn (SurgicalCase $case): array => [
                    'id' => $case->id,
                    'patient' => trim($case->patient->first_name.' '.$case->patient->last_name),
                    'surgeon' => $case->primarySurgeon?->display_name,
                    'procedure' => $case->procedure_description,
                    'status' => $case->status,
                    'scheduled_at' => $case->scheduled_at->toIso8601String(),
                    'show_url' => route('surgery.cases.show', $case->id),
                ])->all(),
            'patients' => Patient::query()->orderBy('last_name')->limit(200)->get()
                ->map(fn (Patient $p): array => ['id' => $p->id, 'name' => trim($p->first_name.' '.$p->last_name)])->all(),
            'surgeons' => $this->staffOptions(),
            'actions' => [
                'can_schedule' => Gate::allows('surgery.manage'),
                'store_url' => route('surgery.cases.store'),
            ],
        ]);
    }

    public function store(Request $request, SurgicalCaseService $cases): RedirectResponse
    {
        Gate::authorize('surgery.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'patient_id' => ['required', 'string'],
            'primary_surgeon_id' => ['required', 'string'],
            'procedure_description' => ['required', 'string', 'max:500'],
            'scheduled_at' => ['required', 'date'],
            'stay_id' => ['nullable', 'string'],
        ]);

        $patient = Patient::query()->whereKey($data['patient_id'])->firstOrFail();
        $surgeon = StaffProfile::query()->whereKey($data['primary_surgeon_id'])->firstOrFail();

        try {
            $case = $cases->schedule($actor, $patient, $surgeon, $data['procedure_description'], Carbon::parse($data['scheduled_at']), $data['stay_id'] ?? null);
        } catch (SurgicalCaseException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_case' => $e->getMessage()]);
        }

        return redirect()->route('surgery.cases.show', $case->id)->with('status', 'surgical-case-scheduled');
    }

    public function show(Request $request, string $case): Response
    {
        Gate::authorize('surgery.manage');
        abort_unless($request->user() instanceof User, 403);

        $record = SurgicalCase::query()->with(['patient', 'primarySurgeon', 'teamMembers.staffProfile', 'events', 'caseEncounters'])
            ->whereKey($case)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        return Inertia::render('Surgery/Case', [
            'surgicalCase' => [
                'id' => $record->id,
                'patient' => ['id' => $record->patient_id, 'name' => trim($record->patient->first_name.' '.$record->patient->last_name)],
                'surgeon' => $record->primarySurgeon?->display_name,
                'procedure' => $record->procedure_description,
                'status' => $record->status,
                'status_reason' => $record->status_reason,
                'scheduled_at' => $record->scheduled_at->toIso8601String(),
                'stay_id' => $record->stay_id,
                'phase_times' => [
                    'pre_op_at' => $record->pre_op_at?->toIso8601String(),
                    'in_progress_at' => $record->in_progress_at?->toIso8601String(),
                    'completed_at' => $record->completed_at?->toIso8601String(),
                    'post_op_at' => $record->post_op_at?->toIso8601String(),
                    'cancelled_at' => $record->cancelled_at?->toIso8601String(),
                ],
                'asa' => ['class' => $record->asa_class, 'mallampati' => $record->mallampati, 'assessed_at' => $record->asa_assessed_at?->toIso8601String()],
            ],
            'team' => $record->teamMembers->map(fn (SurgicalCaseTeamMember $m): array => [
                'id' => $m->id,
                'name' => $m->staffProfile?->display_name,
                'team_role' => $m->team_role,
            ])->all(),
            'notes' => $this->notesFor($record),
            // The legal next statuses from the current state (record-not-judge: a fixed map, not a suggestion).
            'available_transitions' => SurgicalCase::TRANSITIONS[$record->status] ?? [],
            'events' => $record->events->map(fn (SurgicalCaseEvent $e): array => [
                'event_type' => $e->event_type,
                'reason' => $e->reason,
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ])->all(),
            'options' => [
                'staff' => $this->staffOptions(),
                'team_roles' => SurgicalCaseTeamMember::ROLES,
                'asa_classes' => SurgicalCase::ASA_CLASSES,
                'mallampati_classes' => SurgicalCase::MALLAMPATI_CLASSES,
                'phases' => SurgicalCaseEncounter::PHASES,
            ],
            'actions' => [
                'can_manage' => Gate::allows('surgery.manage'),
                'can_write_note' => Gate::allows('note.write'),
                'transition_url' => route('surgery.cases.transition', $record->id),
                'team_url' => route('surgery.cases.team', $record->id),
                'anesthesia_url' => route('surgery.cases.anesthesia', $record->id),
                'note_url' => route('surgery.cases.notes', $record->id),
                'checklist_url' => route('surgery.cases.checklist', $record->id),
            ],
        ]);
    }

    public function transition(Request $request, string $case, SurgicalCaseService $cases): RedirectResponse
    {
        Gate::authorize('surgery.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', SurgicalCase::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = SurgicalCase::query()->whereKey($case)->firstOrFail();

        try {
            $cases->transition($actor, $record, $data['status'], $data['reason'] ?? null);
        } catch (SurgicalCaseException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_case' => $e->getMessage()]);
        }

        return redirect()->route('surgery.cases.show', $record->id)->with('status', 'surgical-case-updated');
    }

    public function team(Request $request, string $case, SurgicalCaseService $cases): RedirectResponse
    {
        Gate::authorize('surgery.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'staff_profile_id' => ['required', 'string'],
            'team_role' => ['required', 'string', 'in:'.implode(',', SurgicalCaseTeamMember::ROLES)],
        ]);

        $record = SurgicalCase::query()->whereKey($case)->firstOrFail();
        $staff = StaffProfile::query()->whereKey($data['staff_profile_id'])->firstOrFail();

        try {
            $cases->addTeamMember($actor, $record, $staff, $data['team_role']);
        } catch (SurgicalCaseException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_case' => $e->getMessage()]);
        }

        return redirect()->route('surgery.cases.show', $record->id)->with('status', 'surgical-team-updated');
    }

    public function anesthesia(Request $request, string $case, SurgicalCaseService $cases): RedirectResponse
    {
        Gate::authorize('surgery.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'asa_class' => ['required', 'string', 'in:'.implode(',', SurgicalCase::ASA_CLASSES)],
            'mallampati' => ['nullable', 'string', 'in:'.implode(',', SurgicalCase::MALLAMPATI_CLASSES)],
            'anesthetist_id' => ['required', 'string'],
        ]);

        $record = SurgicalCase::query()->whereKey($case)->firstOrFail();
        $anesthetist = StaffProfile::query()->whereKey($data['anesthetist_id'])->firstOrFail();

        try {
            $cases->recordAnesthesiaAssessment($actor, $record, $data['asa_class'], $data['mallampati'] ?? null, $anesthetist);
        } catch (SurgicalCaseException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_case' => $e->getMessage()]);
        }

        return redirect()->route('surgery.cases.show', $record->id)->with('status', 'anesthesia-recorded');
    }

    public function startNote(Request $request, string $case, SurgicalCaseService $cases): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'phase' => ['required', 'string', 'in:'.implode(',', SurgicalCaseEncounter::PHASES)],
        ]);

        $record = SurgicalCase::query()->whereKey($case)->firstOrFail();

        try {
            $note = $cases->startNote($actor, $record, $data['phase']);
        } catch (SurgicalCaseException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_case' => $e->getMessage()]);
        }

        // Reuse the EXISTING sign-and-lock note editor — the op note is written + signed there, unchanged.
        return redirect()->route('clinical.notes.edit', $note->id);
    }

    /**
     * The sign-and-lock notes for the case, resolved through the Surgery-side case↔encounter links.
     *
     * @return list<array<string, mixed>>
     */
    private function notesFor(SurgicalCase $case): array
    {
        $notes = [];
        foreach ($case->caseEncounters as $link) {
            foreach (ClinicalNote::query()->where('encounter_id', $link->encounter_id)->orderBy('version')->get() as $note) {
                $notes[] = [
                    'id' => $note->id,
                    'phase' => $link->phase,
                    'status' => $note->status,
                    'version' => $note->version,
                    'edit_url' => route('clinical.notes.edit', $note->id),
                ];
            }
        }

        return $notes;
    }

    /**
     * @return list<array<string, string>>
     */
    private function staffOptions(): array
    {
        return StaffProfile::query()->orderBy('display_name')->limit(200)->get()
            ->map(fn (StaffProfile $s): array => ['id' => $s->id, 'name' => (string) $s->display_name])->all();
    }
}
