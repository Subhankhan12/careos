<?php

namespace Modules\Surgery\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Surgery\Exceptions\SurgicalInventoryException;
use Modules\Surgery\Models\CaseItemUsage;
use Modules\Surgery\Models\ImplantPlacement;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Services\SurgicalUsageService;

/**
 * A surgical case's SUPPLIES (SURGERY.G4) — PRESENTATIONAL over `SurgicalUsageService`. From a case, record
 * consumables used + implants placed (with lot/serial/UDI); see the case's usages/implants + the patient's
 * implant history. Using an item decrements stock ATOMICALLY. Gated `note.write` (the surgical team); the case
 * read is read-logged. String-id (FIX.1). Record-not-judge: traceability is a record, never a device verdict.
 */
class CaseSuppliesController
{
    public function show(Request $request, string $case, SurgicalUsageService $usage): Response
    {
        Gate::authorize('note.write');
        abort_unless($request->user() instanceof User, 403);

        $record = SurgicalCase::query()->with('patient')->whereKey($case)->firstOrFail();
        $record->auditRead(); // patient-scoped read log

        return Inertia::render('Surgery/CaseSupplies', [
            'surgicalCase' => [
                'id' => $record->id,
                'patient' => trim($record->patient->first_name.' '.$record->patient->last_name),
                'procedure' => $record->procedure_description,
                'case_url' => route('surgery.cases.show', $record->id),
            ],
            'usages' => $usage->usagesForCase($record)->map(fn (CaseItemUsage $u): array => [
                'id' => $u->id,
                'item' => $u->surgicalItem?->name,
                'quantity' => $u->quantity,
                'used_at' => $u->used_at->toIso8601String(),
            ])->all(),
            'implants' => $usage->implantsForCase($record)->map($this->presentImplant(...))->all(),
            // The patient's full implant history (across cases) — recall-relevant traceability.
            'patient_implants' => $usage->implantsForPatient($record->patient)->map($this->presentImplant(...))->all(),
            'items' => SurgicalItem::query()->where('active', true)->orderBy('name')->get()
                ->map(fn (SurgicalItem $i): array => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'is_implant' => $i->is_implant])->all(),
            'actions' => [
                'can_record' => Gate::allows('note.write'),
                'use_url' => route('surgery.cases.supplies.use', $record->id),
                'implant_url' => route('surgery.cases.supplies.implant', $record->id),
            ],
        ]);
    }

    public function recordUsage(Request $request, string $case, SurgicalUsageService $usage): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'surgical_item_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $record = SurgicalCase::query()->whereKey($case)->firstOrFail();
        $item = SurgicalItem::query()->whereKey($data['surgical_item_id'])->firstOrFail();

        try {
            $usage->recordUsage($actor, $record, $item, (int) $data['quantity']);
        } catch (SurgicalInventoryException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_supplies' => $e->getMessage()]);
        }

        return redirect()->route('surgery.cases.supplies', $record->id)->with('status', 'surgical-item-used');
    }

    public function placeImplant(Request $request, string $case, SurgicalUsageService $usage): RedirectResponse
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'surgical_item_id' => ['required', 'string'],
            'lot_number' => ['required', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'udi' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = SurgicalCase::query()->whereKey($case)->firstOrFail();
        $item = SurgicalItem::query()->whereKey($data['surgical_item_id'])->firstOrFail();

        try {
            $usage->placeImplant($actor, $record, $item, $data['lot_number'], $data['serial_number'] ?? null, $data['udi'] ?? null, $data['note'] ?? null);
        } catch (SurgicalInventoryException|CrossTenantReferenceException $e) {
            return back()->withErrors(['surgical_supplies' => $e->getMessage()]);
        }

        return redirect()->route('surgery.cases.supplies', $record->id)->with('status', 'implant-placed');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentImplant(ImplantPlacement $p): array
    {
        return [
            'id' => $p->id,
            'item' => $p->surgicalItem?->name,
            'lot_number' => $p->lot_number,
            'serial_number' => $p->serial_number,
            'udi' => $p->udi,
            'placed_at' => $p->placed_at->toIso8601String(),
        ];
    }
}
