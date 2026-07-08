<?php

namespace Modules\Scheduling\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\AvailableSlotFinder;
use Modules\Scheduling\Services\BookingService;

class DayBoardActionController
{
    public function transition(Request $request, AppointmentService $appointments): RedirectResponse
    {
        $data = $request->validate([
            'appointment_id' => ['required', 'string'],
            'action' => ['required', 'string', 'in:arrive,start,complete,cancel,no_show'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $appointment = Appointment::query()->findOrFail($data['appointment_id']);
        Gate::authorize('appointment.manage', ['branch_id' => $appointment->branch_id]);
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        $action = (string) $data['action'];

        if ($action === 'arrive') {
            $appointment->status === Appointment::STATUS_BOOKED
                ? $appointments->arrive($appointments->confirm($appointment, $actor), $actor)
                : $appointments->arrive($appointment, $actor);
        } elseif ($action === 'start') {
            $appointments->start($appointment, $actor);
        } elseif ($action === 'complete') {
            $appointments->complete($appointment, $actor);
        } elseif ($action === 'cancel') {
            $appointments->cancel($appointment, $actor, $data['reason'] ?? 'Cancelled from day-board');
        } else {
            $appointments->noShow($appointment, $actor, $data['reason'] ?? null);
        }

        return back();
    }

    public function quickBook(Request $request, BookingService $bookings): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'string'],
            'patient_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'resource_ids' => ['required', 'array', 'min:1'],
            'resource_ids.*' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        Gate::authorize('appointment.manage', ['branch_id' => $data['branch_id']]);
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        $bookings->book(
            $data['service_id'],
            $data['patient_id'],
            $data['branch_id'],
            $data['starts_at'],
            array_values($data['resource_ids']),
            $actor,
            Appointment::SOURCE_STAFF,
            $data['notes'] ?? null,
        );

        return back();
    }

    public function slots(Request $request, AvailableSlotFinder $slots): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'date' => ['required', 'date'],
        ]);

        Gate::authorize('appointment.manage', ['branch_id' => $data['branch_id']]);
        $service = Service::query()->findOrFail($data['service_id']);

        return response()->json([
            'slots' => $slots->forServiceBranchDate($service, $data['branch_id'], $data['date']),
        ]);
    }
}
