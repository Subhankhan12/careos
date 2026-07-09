<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\ClinicalNote;

class ClinicalNoteShowController
{
    public function __invoke(string $note): JsonResponse
    {
        Gate::authorize('patient.view');

        $record = ClinicalNote::query()->whereKey($note)->firstOrFail();
        $record->auditRead(['surface' => 'clinical_note']);

        return response()->json([
            'note' => [
                'id' => $record->id,
                'encounter_id' => $record->encounter_id,
                'patient_id' => $record->patient_id,
                'author_id' => $record->author_id,
                'status' => $record->status,
                'version' => $record->version,
                'supersedes_id' => $record->supersedes_id,
            ],
        ]);
    }
}
