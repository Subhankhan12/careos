<?php

namespace Modules\Comms\Services;

use Illuminate\Support\Facades\Gate;
use Modules\Billing\Services\PatientBalanceReader;
use Modules\Clinical\Models\Allergy;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Appointment;

/**
 * COMMS.P1 — the facts a correspondent needs beside a patient thread.
 *
 * THIS CLASS IS THE RISK ON THIS SCREEN, so its rules are written down rather than assumed.
 *
 * WHAT IT MAY RETURN: facts somebody RECORDED. A name a clerk typed, an allergy a clinician
 * documented, an appointment that is booked, a balance the billing engine computed. Every value
 * here can be traced to a row and a person.
 *
 * WHAT IT MAY NEVER RETURN, however useful it would look on an inbox screen:
 *   - a CLINICAL JUDGMENT of any kind — no diagnosis, acuity, severity grading, risk or triage.
 *     The recorded severity of an allergy travels as the WORD A CLINICIAN WROTE; this class never
 *     grades, ranks or orders by it (allergies come back ordered by SUBSTANCE, per D-169/D-173,
 *     because ordering by severity would be the system asserting a priority nobody recorded).
 *   - a COMPUTED SUMMARY of the patient or the conversation. The only summarisation this product
 *     performs is the extractive, suggest-ceilinged `comms.draft_reply`, which produces a DRAFT a
 *     human sends — not a sidebar caption that appears without anyone approving it.
 *   - a PRIORITY, URGENCY, SLA or overdue marker. Nothing records those (D-169).
 *
 * PERMISSION SCOPING IS PER ELEMENT AND FAIL-CLOSED. Holding `comms.manage` gets you into the
 * inbox; it does not get you a patient's clinical record or their finances. Each element is gated
 * on the permission that owns that data, and a viewer who lacks it receives `visible: false` with
 * NO value — never a zero that reads as "nothing recorded", and never another element's data.
 * The distinction matters: "no allergies recorded" and "you may not see allergies" are different
 * claims, and a screen that conflates them lies to one of the two readers.
 */
class InboxPatientContextReader
{
    /**
     * Identity is the least a correspondent needs to know who they are writing to.
     */
    public const PERM_IDENTITY = 'patient.view';

    /**
     * Recorded allergies are CLINICAL content. The chart shows them at `patient.view`, but the
     * chart is a clinical surface a clinician opens deliberately; the inbox is an operational one
     * that reception also works in. Where the right gate is arguable the rule established by
     * GOV.P5 applies — the stricter choice, because a disclosure cannot be recalled. So this is
     * gated on `encounter.manage`: the clinician answering a clinical thread sees them, and the
     * receptionist rescheduling an appointment does not.
     */
    public const PERM_ALLERGIES = 'encounter.manage';

    public const PERM_APPOINTMENT = 'appointment.manage';

    public const PERM_BALANCE = 'billing.view';

    public function __construct(
        private readonly PatientBalanceReader $balances,
        private readonly ConsentService $consents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Patient $patient, User $viewer): array
    {
        return [
            'identity' => $this->identity($patient, $viewer),
            'allergies' => $this->allergies($patient, $viewer),
            'nextAppointment' => $this->nextAppointment($patient, $viewer),
            'balance' => $this->balance($patient, $viewer),
            // Not permission-gated beyond the inbox itself: whether this patient may be EMAILED is
            // a fact about the correspondence the viewer is already conducting.
            'emailContact' => $this->emailContact($patient),
            'links' => $this->links($patient, $viewer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(Patient $patient, User $viewer): array
    {
        if (! Gate::forUser($viewer)->allows(self::PERM_IDENTITY)) {
            return ['visible' => false];
        }

        return [
            'visible' => true,
            'name' => trim($patient->first_name.' '.$patient->last_name),
            // A date-only value crosses as a date-only STRING; the page parses it at local midnight
            // through the shared helper. Never `new Date(dateOnly)` (D-091).
            'dateOfBirth' => $patient->date_of_birth->toDateString(),
            'mrn' => $patient->mrn,
        ];
    }

    /**
     * Recorded allergies, as facts. Ordered by SUBSTANCE — never by severity (D-169/D-173).
     *
     * @return array<string, mixed>
     */
    private function allergies(Patient $patient, User $viewer): array
    {
        if (! Gate::forUser($viewer)->allows(self::PERM_ALLERGIES)) {
            return ['visible' => false, 'items' => []];
        }

        $items = Allergy::query()
            ->where('patient_id', $patient->id)
            ->where('status', Allergy::STATUS_ACTIVE)
            ->orderBy('substance')
            ->get()
            ->map(fn (Allergy $allergy): array => [
                'id' => $allergy->id,
                'substance' => $allergy->substance,
                'reaction' => $allergy->reaction,
                // The clinician's own recorded word, carried verbatim and never re-graded.
                'severity' => $allergy->severity,
            ])
            ->values()
            ->all();

        return ['visible' => true, 'items' => $items];
    }

    /**
     * The soonest appointment still ahead of now. One row, not a schedule — the inbox is not the
     * day-board, and a correspondent needs "are they already booked?" answered.
     *
     * @return array<string, mixed>
     */
    private function nextAppointment(Patient $patient, User $viewer): array
    {
        if (! Gate::forUser($viewer)->allows(self::PERM_APPOINTMENT)) {
            return ['visible' => false, 'appointment' => null];
        }

        $appointment = Appointment::query()
            ->where('patient_id', $patient->id)
            ->whereIn('status', [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        return [
            'visible' => true,
            'appointment' => $appointment === null ? null : [
                'id' => $appointment->id,
                'startsAt' => $appointment->starts_at->toIso8601String(),
                'status' => $appointment->status,
            ],
        ];
    }

    /**
     * The open balance, from THE one source (`PatientBalanceReader`) — the same figure the AR
     * account detail shows for this patient. No page-side sum, and no judgment about whether the
     * figure is large, overdue or worrying.
     *
     * @return array<string, mixed>
     */
    private function balance(Patient $patient, User $viewer): array
    {
        if (! Gate::forUser($viewer)->allows(self::PERM_BALANCE)) {
            return ['visible' => false, 'formatted' => null];
        }

        $present = $this->balances->present($patient->id);

        return [
            'visible' => true,
            'formatted' => $present['formatted'],
            'minor' => $present['minor'],
        ];
    }

    /**
     * Whether this patient may be emailed — and the carve-out that makes the answer honest.
     *
     * `NotificationService` gates non-legal mail on the `comms.email` scope and **never** gates the
     * LEGAL category on it. Saying "this patient cannot be emailed" full stop would therefore be an
     * over-claim (D-184): a dunning notice still goes out. The page states both halves.
     *
     * @return array<string, mixed>
     */
    private function emailContact(Patient $patient): array
    {
        return [
            // The one enforced outbound-comms scope in this product. There is no per-topic and no
            // per-channel consent, because there is no second channel and no topic model.
            'consented' => $this->consents->has($patient, 'comms.email'),
        ];
    }

    /**
     * Links only to surfaces this viewer may actually open — an offered link that 403s is a worse
     * answer than no link.
     *
     * @return array<string, string>
     */
    private function links(Patient $patient, User $viewer): array
    {
        $links = [];

        if (Gate::forUser($viewer)->allows(self::PERM_IDENTITY)) {
            $links['patient'] = route('patients.show', $patient->id);
        }

        return $links;
    }
}
