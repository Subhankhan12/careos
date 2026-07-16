<?php

namespace Modules\FrontDesk\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Audit\Services\AuditService;
use Modules\FrontDesk\Exceptions\CheckInException;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Services\PatientService;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Services\AppointmentService;

/**
 * The single check-in service behind both entry paths (kiosk + portal). It never
 * mutates the appointment lifecycle directly — arrival goes through the existing
 * Scheduling AppointmentService — and it edits ONLY the patient's own contact
 * fields, through the existing PatientService. Every action is patient-scoped
 * audited.
 */
class CheckInService
{
    public const SOURCE_KIOSK = 'kiosk';

    public const SOURCE_PORTAL = 'portal';

    public const SOURCE_RECEPTION = 'reception';

    /** Contact types a patient may self-edit at check-in — nothing else. */
    private const EDITABLE_TYPES = [
        PatientContact::TYPE_PHONE,
        PatientContact::TYPE_EMAIL,
        PatientContact::TYPE_ADDRESS,
    ];

    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly PatientService $patients,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{appointment: Appointment, already_checked_in: bool}
     */
    public function checkIn(
        Appointment $appointment,
        Patient $patient,
        string $source,
        ?string $expectedBranchId = null,
    ): array {
        if ($appointment->patient_id !== $patient->id) {
            throw new AuthorizationException('This patient cannot check into this appointment.');
        }

        if ($expectedBranchId !== null && $appointment->branch_id !== $expectedBranchId) {
            throw CheckInException::wrongBranch();
        }

        if (! $appointment->starts_at->isToday()) {
            throw CheckInException::notToday();
        }

        // Idempotent: a second check-in is a no-op returning the existing state.
        if ($appointment->checked_in_at !== null) {
            return ['appointment' => $appointment, 'already_checked_in' => true];
        }

        if (! in_array($appointment->status, [
            Appointment::STATUS_BOOKED,
            Appointment::STATUS_CONFIRMED,
            Appointment::STATUS_ARRIVED,
        ], true)) {
            throw CheckInException::notCheckInable($appointment->status);
        }

        // Transition to 'arrived' through the existing Scheduling service (never
        // a direct status write). Already-arrived appointments skip the hop.
        if (in_array($appointment->status, [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED], true)) {
            $appointment = $this->appointments->arriveForPatient($appointment, $patient);
        }

        $appointment->forceFill([
            'checked_in_at' => now(),
            'check_in_source' => $source,
        ])->save();

        $this->audit->record([
            'actor_type' => 'patient',
            'actor_id' => $patient->id,
            'action' => 'appointment.checked_in',
            'patient_id' => $patient->id,
            'resource_type' => 'appointment',
            'resource_id' => $appointment->id,
            'context' => ['source' => $source, 'branch_id' => $appointment->branch_id],
        ]);

        return ['appointment' => $appointment->refresh(), 'already_checked_in' => false];
    }

    /**
     * A constrained self-update of the patient's OWN contact fields (phone,
     * email, address) — nothing else. Goes through PatientService so tenancy
     * applies; the change is patient-scoped audited. Non-editable contact types
     * (e.g. emergency) are preserved.
     *
     * @param  array{phone?: string|null, email?: string|null, address?: array<string, string|null>|null}  $data
     */
    public function updateContact(Patient $patient, array $data, string $source): Patient
    {
        $preserved = PatientContact::query()
            ->where('patient_id', $patient->id)
            ->whereNotIn('type', self::EDITABLE_TYPES)
            ->get()
            ->map(fn (PatientContact $contact): array => [
                'type' => $contact->type,
                'value' => $contact->value,
                'line1' => $contact->line1,
                'line2' => $contact->line2,
                'city' => $contact->city,
                'postal' => $contact->postal,
                'country' => $contact->country,
                'is_primary' => $contact->is_primary,
            ])
            ->all();

        $contacts = $preserved;

        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;
        if ($phone !== null && $phone !== '') {
            $contacts[] = ['type' => PatientContact::TYPE_PHONE, 'value' => $phone, 'is_primary' => true];
        }

        $email = isset($data['email']) ? trim((string) $data['email']) : null;
        if ($email !== null && $email !== '') {
            $contacts[] = ['type' => PatientContact::TYPE_EMAIL, 'value' => $email, 'is_primary' => true];
        }

        $address = $data['address'] ?? null;
        if (is_array($address)) {
            $addressFields = array_filter([
                'line1' => trim((string) ($address['line1'] ?? '')),
                'line2' => trim((string) ($address['line2'] ?? '')),
                'city' => trim((string) ($address['city'] ?? '')),
                'postal' => trim((string) ($address['postal'] ?? '')),
                'country' => trim((string) ($address['country'] ?? '')),
            ], fn (string $v): bool => $v !== '');

            if ($addressFields !== []) {
                $contacts[] = ['type' => PatientContact::TYPE_ADDRESS, 'is_primary' => true, ...$addressFields];
            }
        }

        // patient = [] -> no demographic field is writable at check-in.
        $this->patients->update($patient, [], $contacts);

        $this->audit->record([
            'actor_type' => 'patient',
            'actor_id' => $patient->id,
            'action' => 'patient.contact_updated',
            'patient_id' => $patient->id,
            'resource_type' => 'patient',
            'resource_id' => $patient->id,
            'context' => [
                'source' => $source,
                'fields' => array_values(array_filter([
                    $phone !== null && $phone !== '' ? 'phone' : null,
                    $email !== null && $email !== '' ? 'email' : null,
                    is_array($address) ? 'address' : null,
                ])),
            ],
        ]);

        return $patient->refresh()->load('contacts');
    }

    /**
     * The patient's own editable contact fields, for display after identity
     * verification (kiosk) or in the portal. No clinical data, ever.
     *
     * @return array{phone: ?string, email: ?string, address: array<string, ?string>}
     */
    public function contactSnapshot(Patient $patient): array
    {
        $contacts = PatientContact::query()->where('patient_id', $patient->id)->get();
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
