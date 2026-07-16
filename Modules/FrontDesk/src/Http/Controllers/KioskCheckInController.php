<?php

namespace Modules\FrontDesk\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;
use Modules\FrontDesk\Models\KioskDevice;
use Modules\FrontDesk\Services\CheckInService;
use Modules\FrontDesk\Services\KioskCheckInService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Branch;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Service;

/**
 * The unauthenticated (kiosk-token-scoped) check-in flow. It exposes ONLY:
 * identity resolution, the confirm/check-in action, and the patient's own
 * contact fields — never clinical data, never patient search/browsing. A
 * successful resolve returns a short-lived encrypted verification handle that
 * binds the follow-up actions to that one appointment/patient, so the kiosk
 * token can never read or act on an arbitrary patient.
 */
class KioskCheckInController
{
    /** Seconds the post-resolve verification handle stays valid. */
    private const VERIFICATION_TTL = 300;

    public function page(Request $request): Response
    {
        $device = $this->device($request);
        $branch = Branch::query()->find($device->branch_id);

        return Inertia::render('Kiosk/CheckIn', [
            'branch' => ['name' => $branch?->name],
            'urls' => [
                'resolve' => route('kiosk.resolve', $request->route('kioskToken')),
                'checkIn' => route('kiosk.check-in', $request->route('kioskToken')),
                'updateContact' => route('kiosk.contact', $request->route('kioskToken')),
            ],
        ]);
    }

    public function resolve(Request $request, KioskCheckInService $kiosk, CheckInService $checkIns): JsonResponse
    {
        $device = $this->device($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $result = $kiosk->resolve($data['name'], $data['date_of_birth'], $data['code'], $device->branch_id);

        // Generic not-found: reveals nothing (no candidate list, no PHI).
        if (! $result['found'] || $result['appointment'] === null || $result['patient'] === null) {
            return response()->json(['found' => false]);
        }

        $device->forceFill(['last_used_at' => now()])->save();

        $appointment = $result['appointment'];
        $patient = $result['patient'];
        $service = Service::query()->find($appointment->service_id);

        $verification = Crypt::encrypt([
            'appointment_id' => $appointment->id,
            'patient_id' => $patient->id,
            'branch_id' => $device->branch_id,
            'exp' => now()->addSeconds(self::VERIFICATION_TTL)->timestamp,
        ]);

        return response()->json([
            'found' => true,
            'verification' => $verification,
            'appointment' => [
                'service' => $service?->name,
                'starts_at' => $appointment->starts_at->toDateTimeString(),
                'checked_in' => $appointment->checked_in_at !== null,
            ],
            'contact' => $checkIns->contactSnapshot($patient),
        ]);
    }

    public function checkIn(Request $request, CheckInService $checkIns): JsonResponse
    {
        $device = $this->device($request);
        $request->validate(['verification' => ['required', 'string']]);

        [$appointment, $patient] = $this->fromVerification($request, $device);

        $result = $checkIns->checkIn($appointment, $patient, CheckInService::SOURCE_KIOSK, $device->branch_id);

        return response()->json([
            'checked_in' => true,
            'already_checked_in' => $result['already_checked_in'],
        ]);
    }

    public function updateContact(Request $request, CheckInService $checkIns): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate($this->contactRules());

        [, $patient] = $this->fromVerification($request, $device);

        $checkIns->updateContact($patient, $this->contactPayload($data), CheckInService::SOURCE_KIOSK);

        return response()->json(['updated' => true]);
    }

    private function device(Request $request): KioskDevice
    {
        $device = $request->attributes->get('kiosk_device');
        abort_unless($device instanceof KioskDevice, 403);

        return $device;
    }

    /**
     * @return array{0: Appointment, 1: Patient}
     */
    private function fromVerification(Request $request, KioskDevice $device): array
    {
        try {
            $payload = Crypt::decrypt((string) $request->input('verification'));
        } catch (DecryptException) {
            abort(422, 'Verification expired. Please start again.');
        }

        if (! is_array($payload)
            || ($payload['branch_id'] ?? null) !== $device->branch_id
            || (int) ($payload['exp'] ?? 0) < now()->timestamp) {
            abort(422, 'Verification expired. Please start again.');
        }

        $appointment = Appointment::query()->find((string) $payload['appointment_id']);
        $patient = Patient::query()->find((string) $payload['patient_id']);

        abort_unless($appointment instanceof Appointment && $patient instanceof Patient, 422);

        return [$appointment, $patient];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function contactRules(): array
    {
        return [
            'verification' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'array'],
            'address.line1' => ['nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.postal' => ['nullable', 'string', 'max:32'],
            'address.country' => ['nullable', 'string', 'max:2'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{phone: ?string, email: ?string, address: array<string, string|null>|null}
     */
    private function contactPayload(array $data): array
    {
        return [
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
        ];
    }
}
