<?php

namespace Modules\Scheduling\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Models\PortalAccount;
use Modules\Platform\Models\Branch;
use Modules\Platform\Services\SettingsService;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\AvailableSlotFinder;
use Modules\Scheduling\Services\BookingService;

/**
 * Portal self-service appointments. Identity comes from the AUTHENTICATED
 * portal account only — never from client input. Booking reuses the C.6 path
 * (BookingService::bookOnline, the no-double-book locked path) and exposes only
 * active bookable_online services; cancellation enforces the tenant's
 * cancel-window policy server-side (P0D.GU).
 */
class PortalAppointmentController
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);

        $appointments = Appointment::query()
            ->where('patient_id', $account->patient_id)
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get();

        $services = Service::query()
            ->where('active', true)
            ->where('bookable_online', true)
            ->orderBy('name')
            ->get(['id', 'name', 'default_duration_minutes']);

        return Inertia::render('Portal/Appointments', [
            'upcoming' => $appointments
                ->filter(fn (Appointment $appointment): bool => $appointment->starts_at->isFuture()
                    && in_array($appointment->status, [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED], true))
                ->sortBy('starts_at')
                ->map(fn (Appointment $appointment): array => $this->summary($appointment))
                ->values()
                ->all(),
            'past' => $appointments
                ->reject(fn (Appointment $appointment): bool => $appointment->starts_at->isFuture()
                    && in_array($appointment->status, [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED], true))
                ->map(fn (Appointment $appointment): array => $this->summary($appointment))
                ->values()
                ->all(),
            'services' => $services->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'duration' => $service->default_duration_minutes,
            ])->all(),
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'cancelMinHours' => $this->cancelMinHours(),
            'contact' => $this->contactSnapshot($account->patient_id),
            'actions' => [
                'slotsUrl' => route('portal.appointments.slots'),
                'storeUrl' => route('portal.appointments.store'),
                'cancelUrl' => route('portal.appointments.cancel'),
                'checkInUrl' => route('portal.check-in'),
                'updateContactUrl' => route('portal.check-in.contact'),
            ],
        ]);
    }

    public function slots(Request $request, AvailableSlotFinder $finder): JsonResponse
    {
        $this->account($request);

        $data = $request->validate([
            'service_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'date' => ['required', 'date'],
        ]);

        $service = Service::query()
            ->whereKey($data['service_id'])
            ->where('active', true)
            ->where('bookable_online', true)
            ->firstOrFail();

        return response()->json([
            'slots' => $finder->forServiceBranchDate($service, $data['branch_id'], $data['date'], 12),
        ]);
    }

    public function store(Request $request, BookingService $bookings): RedirectResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'service_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'resource_ids' => ['required', 'array', 'min:1'],
            'resource_ids.*' => ['required', 'string'],
        ]);

        // Same exposure rule as C.6 public booking.
        $service = Service::query()
            ->whereKey($data['service_id'])
            ->where('active', true)
            ->where('bookable_online', true)
            ->firstOrFail();

        // The SAFE booking path: BookingService's locked no-double-book flow,
        // authenticated variant. The patient is the session's patient, always.
        $bookings->bookOnline(
            $service->id,
            $account->patient_id,
            $data['branch_id'],
            $data['starts_at'],
            array_values($data['resource_ids']),
            'portal self-booking',
        );

        return redirect()->route('portal.appointments');
    }

    public function cancel(Request $request, AppointmentService $appointments): RedirectResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'appointment_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $appointment = Appointment::query()
            ->whereKey($data['appointment_id'])
            ->where('patient_id', $account->patient_id)
            ->firstOrFail();

        // Cancel-window policy, enforced server-side: the appointment must
        // start at least N hours from now (tenant-configurable, default 24).
        if (now()->addHours($this->cancelMinHours())->greaterThan($appointment->starts_at)) {
            throw ValidationException::withMessages([
                'appointment_id' => 'This appointment can no longer be cancelled online. Please contact the practice.',
            ]);
        }

        $patient = Patient::query()->whereKey($account->patient_id)->firstOrFail();
        $appointments->cancelForPatient($appointment, $patient, $data['reason']);

        return redirect()->route('portal.appointments');
    }

    private function cancelMinHours(): int
    {
        return (int) app(SettingsService::class)->get('scheduling.portal.cancel_min_hours', 24);
    }

    private function account(Request $request): PortalAccount
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Appointment $appointment): array
    {
        $service = Service::query()->find($appointment->service_id);

        return [
            'id' => $appointment->id,
            'service' => $service?->name,
            'starts_at' => $appointment->starts_at->toDateTimeString(),
            'ends_at' => $appointment->ends_at->toDateTimeString(),
            'status' => $appointment->status,
            'checked_in' => $appointment->checked_in_at !== null,
            'can_check_in' => $appointment->checked_in_at === null
                && $appointment->starts_at->isToday()
                && in_array($appointment->status, [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED], true),
        ];
    }

    /**
     * The patient's own contact fields for the portal check-in edit form. No
     * clinical data — reads only PatientContact rows.
     *
     * @return array<string, mixed>
     */
    private function contactSnapshot(string $patientId): array
    {
        $contacts = PatientContact::query()->where('patient_id', $patientId)->get();
        $address = $contacts->firstWhere('type', PatientContact::TYPE_ADDRESS);

        return [
            'phone' => $contacts->firstWhere('type', PatientContact::TYPE_PHONE)?->value,
            'email' => $contacts->firstWhere('type', PatientContact::TYPE_EMAIL)?->value,
            'address' => [
                'line1' => $address?->line1,
                'line2' => $address?->line2,
                'city' => $address?->city,
                'postal' => $address?->postal,
                'country' => $address?->country,
            ],
        ];
    }
}
