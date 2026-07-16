<?php

namespace Modules\FrontDesk\Services;

use Illuminate\Support\Carbon;
use Modules\Patients\Models\Patient;
use Modules\Scheduling\Models\Appointment;

/**
 * Kiosk identity resolution. Returns EXACTLY ONE today-booked patient/appointment
 * on an exact name + date-of-birth + check-in-code match at this branch, or a
 * generic "not found". It NEVER returns a candidate list — an ambiguous or failed
 * match reveals nothing (no PHI leak); the patient is told to see reception.
 */
class KioskCheckInService
{
    /**
     * @return array{found: bool, appointment: ?Appointment, patient: ?Patient}
     */
    public function resolve(string $name, string $dob, string $code, string $branchId): array
    {
        $normalizedName = $this->normalize($name);
        $normalizedCode = strtoupper(trim($code));
        $dobDate = $this->parseDob($dob);

        if ($normalizedName === '' || $normalizedCode === '' || $dobDate === null) {
            return $this->notFound();
        }

        $candidates = Appointment::query()
            ->where('branch_id', $branchId)
            ->whereDate('starts_at', Carbon::today()->toDateString())
            ->whereIn('status', [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED])
            ->whereRaw('UPPER(check_in_code) = ?', [$normalizedCode])
            ->whereNotNull('patient_id')
            ->get()
            ->map(function (Appointment $appointment): array {
                $patient = Patient::query()->find($appointment->patient_id);

                return ['appointment' => $appointment, 'patient' => $patient];
            })
            ->filter(function (array $row) use ($normalizedName, $dobDate): bool {
                $patient = $row['patient'];

                if (! $patient instanceof Patient) {
                    return false;
                }

                return $this->normalize($patient->first_name.' '.$patient->last_name) === $normalizedName
                    && $patient->date_of_birth->toDateString() === $dobDate;
            })
            ->values();

        // Exactly one match, or nothing. Never a list.
        if ($candidates->count() !== 1) {
            return $this->notFound();
        }

        /** @var array{appointment: Appointment, patient: Patient} $match */
        $match = $candidates->first();

        return ['found' => true, 'appointment' => $match['appointment'], 'patient' => $match['patient']];
    }

    /**
     * @return array{found: false, appointment: null, patient: null}
     */
    private function notFound(): array
    {
        return ['found' => false, 'appointment' => null, 'patient' => null];
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function parseDob(string $dob): ?string
    {
        $dob = trim($dob);

        if ($dob === '') {
            return null;
        }

        try {
            return Carbon::parse($dob)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
