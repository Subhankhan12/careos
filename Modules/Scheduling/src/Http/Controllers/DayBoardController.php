<?php

namespace Modules\Scheduling\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Branch;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailableSlotFinder;

class DayBoardController
{
    public function __invoke(Request $request, AvailableSlotFinder $slots): Response
    {
        Gate::authorize('appointment.manage', ['branch_id' => $request->query('branch_id')]);

        $date = Carbon::parse($request->query('date', Carbon::today()->toDateString()))->toDateString();
        $branch = Branch::query()
            ->when($request->query('branch_id'), fn ($query, $branchId) => $query->whereKey($branchId))
            ->orderBy('name')
            ->firstOrFail();

        $services = Service::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('Scheduling/DayBoard', [
            'filters' => ['date' => $date, 'branch_id' => $branch->id],
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->all(),
            'resources' => Resource::query()
                ->where('branch_id', $branch->id)
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Resource $resource): array => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'type' => $resource->type,
                ])
                ->all(),
            'appointments' => Appointment::query()
                ->with(['resourceLinks'])
                ->where('branch_id', $branch->id)
                ->whereDate('starts_at', $date)
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Appointment $appointment): array => $this->appointmentSummary($appointment))
                ->all(),
            'services' => $services->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'duration' => $service->default_duration_minutes,
            ])->all(),
            'patients' => Patient::query()
                ->orderBy('last_name')
                ->limit(20)
                ->get()
                ->map(fn (Patient $patient): array => [
                    'id' => $patient->id,
                    'name' => trim($patient->first_name.' '.$patient->last_name),
                    'mrn' => $patient->mrn,
                ])
                ->all(),
            'slotPreview' => $services->first() !== null
                ? $slots->forServiceBranchDate($services->first(), $branch->id, $date, 12)
                : [],
            'actions' => [
                'transitionUrl' => route('scheduling.day-board.transition'),
                'quickBookUrl' => route('scheduling.day-board.quick-book'),
                'slotsUrl' => route('scheduling.day-board.slots'),
                'openEncounterUrl' => route('scheduling.day-board.open-encounter'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentSummary(Appointment $appointment): array
    {
        $patient = $appointment->patient_id !== null
            ? Patient::query()->find($appointment->patient_id)
            : null;
        $service = Service::query()->find($appointment->service_id);

        return [
            'id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
            'service' => $service?->name,
            'starts_at' => $appointment->starts_at->toDateTimeString(),
            'ends_at' => $appointment->ends_at->toDateTimeString(),
            'status' => $appointment->status,
            'resource_ids' => $appointment->resourceLinks->pluck('resource_id')->all(),
        ];
    }
}
