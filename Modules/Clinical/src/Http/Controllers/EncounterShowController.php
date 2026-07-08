<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\Encounter;

class EncounterShowController
{
    public function __invoke(string $encounter): JsonResponse
    {
        $record = Encounter::query()->whereKey($encounter)->firstOrFail();

        Gate::authorize('encounter.manage', ['branch_id' => $record->branch_id]);

        $record->auditRead(['surface' => 'encounter']);

        return response()->json([
            'encounter' => [
                'id' => $record->id,
                'patient_id' => $record->patient_id,
                'practitioner_id' => $record->practitioner_id,
                'branch_id' => $record->branch_id,
                'appointment_id' => $record->appointment_id,
                'type' => $record->type,
                'status' => $record->status,
                'started_at' => $record->started_at->toDateTimeString(),
                'ended_at' => $record->ended_at?->toDateTimeString(),
            ],
        ]);
    }
}
