<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NoteTemplate;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\EncounterService;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentResource;
use Modules\Scheduling\Models\Resource;

class OpenEncounterFromAppointmentController
{
    public function __invoke(
        Request $request,
        EncounterService $encounters,
        ClinicalNoteService $notes,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        /** @var array{appointment_id: string, type?: string|null, reason_for_visit?: string|null} $data */
        $data = $request->validate([
            'appointment_id' => ['required', 'string'],
            'type' => ['nullable', 'string', 'in:'.implode(',', Encounter::types())],
            'reason_for_visit' => ['nullable', 'string', 'max:500'],
        ]);

        $appointment = Appointment::query()->whereKey($data['appointment_id'])->firstOrFail();
        $patient = Patient::query()->whereKey($appointment->patient_id)->firstOrFail();
        $branch = Branch::query()->whereKey($appointment->branch_id)->firstOrFail();
        $practitioner = $this->practitionerForAppointment($appointment);

        $encounter = $encounters->open(
            $patient,
            $practitioner,
            $branch,
            $appointment,
            $data['type'] ?? Encounter::TYPE_CONSULTATION,
            $actor,
            $data['reason_for_visit'] ?? null,
        );

        $template = NoteTemplate::query()->where('active', true)->orderBy('name')->first();
        $note = $notes->saveDraft($encounter, $practitioner, [], $actor, null, $template);

        return redirect()->route('clinical.notes.edit', $note->id);
    }

    private function practitionerForAppointment(Appointment $appointment): StaffProfile
    {
        $resourceIds = AppointmentResource::query()
            ->where('appointment_id', $appointment->id)
            ->pluck('resource_id')
            ->all();

        $resource = Resource::query()
            ->whereIn('id', $resourceIds)
            ->where('type', Resource::TYPE_PRACTITIONER)
            ->whereNotNull('staff_profile_id')
            ->orderBy('id')
            ->first();

        if (! $resource instanceof Resource || $resource->staff_profile_id === null) {
            throw ValidationException::withMessages([
                'appointment_id' => 'Appointment has no practitioner resource linked to a staff profile.',
            ]);
        }

        return StaffProfile::query()->whereKey($resource->staff_profile_id)->firstOrFail();
    }
}
