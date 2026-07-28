<?php

namespace Modules\Surgery\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Surgery\Exceptions\SurgicalChecklistException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Services\SurgicalChecklistService;

/**
 * The WHO Surgical Safety Checklist for a case (SURGERY.G3) — PRESENTATIONAL over `SurgicalChecklistService`.
 * The three WHO phases (sign-in / time-out / sign-out); the team confirms items; a FACTUAL "checked / total"
 * count is shown. String-id (FIX.1). Read + confirm are `note.write` (the surgical team); the case read is
 * read-logged.
 *
 * THE FENCE (the crux): this surface RECORDS completion. It does NOT block or gate the case — there is no
 * "proceed" guard here, no computed safety verdict; the team owns the safety decision. A count is a fact.
 */
class SurgicalChecklistController
{
    public function show(Request $request, string $case, SurgicalChecklistService $checklists): Response
    {
        Gate::authorize('note.write');
        abort_unless($request->user() instanceof User, 403);

        $record = SurgicalCase::query()->with('patient')->whereKey($case)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        return Inertia::render('Surgery/Checklist', [
            'surgicalCase' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'procedure' => $record->procedure_description,
                'status' => $record->status,
                'case_url' => route('surgery.cases.show', $record->id),
            ],
            // The factual read model — active WHO items per phase + latest check state + a plain count. No verdict.
            'checklist' => $checklists->forCase($record),
            'actions' => [
                'can_confirm' => Gate::allows('note.write'),
                'confirm_url' => route('surgery.cases.checklist.confirm', $record->id),
            ],
        ]);
    }

    public function confirm(Request $request, string $case, SurgicalChecklistService $checklists): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'template_item_id' => ['required', 'string'],
            'checked' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = SurgicalCase::query()->whereKey($case)->firstOrFail();

        try {
            $checklists->confirmItem($actor, $record, $data['template_item_id'], (bool) $data['checked'], $data['note'] ?? null);
        } catch (SurgicalChecklistException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_checklist' => $e->getMessage()]);
        }

        return redirect()->route('surgery.cases.checklist', $record->id)->with('status', 'checklist-item-confirmed');
    }
}
