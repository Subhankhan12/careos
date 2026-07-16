<?php

namespace Modules\FrontDesk\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\FrontDesk\Services\CheckInService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Scheduling\Models\Appointment;

/**
 * Portal self check-in. Identity is the AUTHENTICATED portal account only (never
 * client input); it runs behind portal-tenant + portal-auth + portal-consent, so
 * a withdrawn portal.access consent locks it on the next request. A patient can
 * only check into their OWN appointment (ownership is re-checked in CheckInService).
 */
class PortalCheckInController
{
    public function checkIn(Request $request, CheckInService $checkIns): RedirectResponse
    {
        $patient = $this->patient($request);
        $data = $request->validate(['appointment_id' => ['required', 'string']]);

        $appointment = Appointment::query()
            ->whereKey($data['appointment_id'])
            ->where('patient_id', $patient->id)
            ->firstOrFail();

        $checkIns->checkIn($appointment, $patient, CheckInService::SOURCE_PORTAL);

        return redirect()->route('portal.appointments');
    }

    public function updateContact(Request $request, CheckInService $checkIns): RedirectResponse
    {
        $patient = $this->patient($request);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'array'],
            'address.line1' => ['nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.postal' => ['nullable', 'string', 'max:32'],
            'address.country' => ['nullable', 'string', 'max:2'],
        ]);

        $checkIns->updateContact($patient, [
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
        ], CheckInService::SOURCE_PORTAL);

        return redirect()->route('portal.appointments');
    }

    private function patient(Request $request): Patient
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        return Patient::query()->whereKey($account->patient_id)->firstOrFail();
    }
}
