<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\Allergy;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Scheduling\Exceptions\BookingConflictException;
use Modules\Scheduling\Exceptions\BookingUnavailableException;
use Modules\Scheduling\Exceptions\IllegalAppointmentTransitionException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentReminder;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\AvailableSlotFinder;

/**
 * APPT.P1 — the staff APPOINTMENT DETAIL page: the drill-in from the day-board tile.
 *
 * A pure DISPLAY surface over the already-complete scheduling backend. It composes four modules
 * (Scheduling + Patients + Clinical + Audit), so it lives in the APP LAYER — modules never depend on
 * each other (D-017); Scheduling stays free of Clinical and Audit.
 *
 * EVERYTHING SHOWN IS REAL:
 *  - the status pill renders the appointment's TRUE `status` — all EIGHT states the machine defines,
 *    not the four the wireframe happened to draw;
 *  - the source badge is the real `source` (staff / online / agent);
 *  - the duration is the SERVICE's configured `default_duration_minutes` — a recorded fact, never a
 *    predicted or computed length;
 *  - resources are the real linked `Resource` rows (type + name). The wireframe's room capability
 *    chips ("scanner · X-ray") are DELIBERATELY OMITTED: no capability field exists on `Resource`, and
 *    inventing one would be fabrication (deferred to its own gate);
 *  - the timeline is the real append-only `audit_events` for this appointment plus its real
 *    `AppointmentReminder` rows, whose channel is labelled HONESTLY (only email exists today — SMS
 *    drivers are deferred, so the page never claims one), and the confirmation provenance is the real
 *    recorded actor/time, never a fabricated "patient replied" line.
 *
 * NO computed judgment (no no-show risk, no priority, no predicted duration), NO money, and NO
 * actions — the action row is APPT.P2 and the reschedule modal APPT.P3. `appointment.manage`; the
 * appointment is resolved from a STRING id in-controller (FIX.1), so a cross-tenant id 404s through
 * the tenant-scoped query.
 */
class AppointmentDetailController extends Controller
{
    /**
     * The action verbs this page accepts, mapped to the status each one moves TO. `rescheduled` is
     * absent on purpose — it needs the slot finder + overlap guard (APPT.P3).
     *
     * @var array<string, string>
     */
    private const ACTION_TO_STATUS = [
        'confirm' => Appointment::STATUS_CONFIRMED,
        'arrive' => Appointment::STATUS_ARRIVED,
        'start' => Appointment::STATUS_IN_PROGRESS,
        'complete' => Appointment::STATUS_COMPLETED,
        'cancel' => Appointment::STATUS_CANCELLED,
        'no_show' => Appointment::STATUS_NO_SHOW,
    ];

    /** How far ahead the reschedule modal scans for conflict-free slots, and how many it offers. */
    private const RESCHEDULE_SCAN_DAYS = 14;

    private const SLOTS_PER_DAY = 4;

    private const MAX_SLOTS = 12;

    public function show(Request $request, string $appointment, AvailableSlotFinder $slots): Response
    {
        $record = Appointment::query()->whereKey($appointment)->firstOrFail();

        // Branch-scoped permission, exactly as the day-board gates it.
        Gate::authorize('appointment.manage', ['branch_id' => $record->branch_id]);
        abort_unless($request->user() instanceof User, 403);

        $service = Service::query()->find($record->service_id);
        $patient = $record->patient_id !== null ? Patient::query()->find($record->patient_id) : null;
        $branch = Branch::query()->find($record->branch_id);

        return Inertia::render('Scheduling/AppointmentDetail', [
            'appointment' => [
                'id' => $record->id,
                // The TRUE status — the page labels all eight, including the ones the wireframe omits.
                'status' => $record->status,
                'source' => $record->source,
                'starts_at' => $record->starts_at->toDateTimeString(),
                'ends_at' => $record->ends_at->toDateTimeString(),
                // The SERVICE's configured duration (a recorded fact), not a computed/predicted one.
                'duration_minutes' => $service?->default_duration_minutes,
                'service' => $service?->name,
                'branch' => $branch?->name,
                'notes' => $record->notes,
                // Why/when/by whom the status last moved — recorded provenance.
                'status_reason' => $record->status_reason,
                'status_changed_at' => $record->status_changed_at?->toDateTimeString(),
                'status_changed_by' => $this->userName($record->status_changed_by),
                'rescheduled_from_id' => $record->rescheduled_from_id,
            ],
            'resources' => $this->resources($record),
            'patient' => $patient === null ? null : [
                'id' => (string) $patient->id,
                'name' => trim($patient->first_name.' '.$patient->last_name),
                'mrn' => $patient->mrn,
                // Date-only: the page renders it through the shared formatDateOnly/ageFromDateOnly
                // helpers (local-midnight parse, no timezone day-shift — M-2/FIX.3).
                'date_of_birth' => $patient->date_of_birth->toDateString(),
                'chart_url' => route('patients.show', (string) $patient->id),
                // RECORDED clinical facts (ALLERGY.P1): substance/reaction/severity as documented.
                // Displayed, never graded — the system computes no allergy judgment.
                'allergies' => Allergy::query()
                    ->where('patient_id', $patient->id)
                    ->where('status', Allergy::STATUS_ACTIVE)
                    ->orderBy('substance')
                    ->get()
                    ->map(fn (Allergy $allergy): array => [
                        'id' => $allergy->id,
                        'substance' => $allergy->substance,
                        'reaction' => $allergy->reaction,
                        'severity' => $allergy->severity,
                    ])
                    ->all(),
            ],
            'timeline' => $this->timeline($record),
            // APPT.P2 — the action row: the REAL legal transitions for this appointment's ACTUAL
            // status, straight from the machine. Never a hardcoded list.
            'actions' => $this->legalActions($record),
            // APPT.P3 — the reschedule modal. Offered only when `rescheduled` is a LEGAL move from
            // this status; the slots are the REAL finder's conflict-free output, never page-computed.
            'reschedule' => $this->reschedulePanel($record, $service, $slots),
            'links' => [
                'day_board' => route('scheduling.day-board', ['date' => $record->starts_at->toDateString()]),
            ],
        ]);
    }

    /**
     * APPT.P2 — perform a state transition through the REAL {@see AppointmentService}.
     *
     * The controller chooses NOTHING about legality: it maps the submitted verb to the service method
     * and the service's {@see AppointmentService::transition()} authorizes, asserts legality, re-asserts
     * it inside the row lock and dispatches the event the audit listener records. A forged illegal move
     * (e.g. booked → arrived, or booked → completed) therefore throws and is refused here — the machine
     * is never widened to accommodate a UI.
     *
     * Rescheduling is deliberately NOT one of these verbs: it needs the slot finder and the overlap
     * guard, and is APPT.P3.
     */
    public function transition(Request $request, string $appointment, AppointmentService $appointments): RedirectResponse
    {
        $record = Appointment::query()->whereKey($appointment)->firstOrFail();

        Gate::authorize('appointment.manage', ['branch_id' => $record->branch_id]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', array_keys(self::ACTION_TO_STATUS))],
            // Cancelling REQUIRES a reason — the service itself throws without one, so the rule is the
            // server's, not this page's. A no-show reason is optional (the service permits null), and
            // is recorded verbatim when given.
            'reason' => ['nullable', 'string', 'max:500', 'required_if:action,cancel'],
        ]);

        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;

        try {
            match ($validated['action']) {
                'confirm' => $appointments->confirm($record, $actor),
                'arrive' => $appointments->arrive($record, $actor),
                'start' => $appointments->start($record, $actor),
                'complete' => $appointments->complete($record, $actor),
                'cancel' => $appointments->cancel($record, $actor, (string) $reason),
                default => $appointments->noShow($record, $actor, $reason === '' ? null : $reason),
            };
        } catch (IllegalAppointmentTransitionException|InvalidArgumentException $e) {
            // The machine (or the reason guard) refused it. Nothing moved.
            return redirect()
                ->route('scheduling.appointments.show', $record->id)
                ->withErrors(['appointment_action' => $e->getMessage()]);
        }

        return redirect()->route('scheduling.appointments.show', $record->id);
    }

    /**
     * APPT.P3 — move the appointment to a new slot through the REAL
     * {@see AppointmentService::reschedule()}.
     *
     * THE GUARD, TWICE OVER. The page submits only the chosen START TIME and the operator's reason —
     * never the resources. The controller re-runs the REAL {@see AvailableSlotFinder} at confirm time
     * and requires the chosen slot to still be conflict-free right now; the finder's own
     * `resource_ids` for that slot are what get booked. Then `reschedule()` does the actual move:
     * reason-required, `assertLegal(→ rescheduled)`, one transaction with the old row `lockForUpdate`,
     * re-booked via `BookingService::book` → `lockResource` → `assertNoOverlap`. So a slot that was
     * taken between display and confirm is refused — first by the finder re-check, and in any race
     * that slips past it by the overlap guard itself (`BookingConflictException`). **A reschedule
     * cannot double-book**, and "availability re-checked server-side at confirm" is literally true.
     *
     * On success the OLD appointment is terminal (`rescheduled`) and a NEW one exists, so the operator
     * is redirected to the new appointment's page.
     */
    public function reschedule(
        Request $request,
        string $appointment,
        AppointmentService $appointments,
        AvailableSlotFinder $slots,
    ): RedirectResponse {
        $record = Appointment::query()->whereKey($appointment)->firstOrFail();

        Gate::authorize('appointment.manage', ['branch_id' => $record->branch_id]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            // The real service throws without a reason; the rule is the server's, not this page's.
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $service = Service::query()->find($record->service_id);
        abort_unless($service instanceof Service, 404);

        $startsAt = Carbon::parse($validated['starts_at'])->toDateTimeString();

        // RE-CHECK at confirm through the REAL finder — and take the resources from ITS answer.
        $match = null;
        foreach ($slots->forServiceBranchDate($service, $record->branch_id, substr($startsAt, 0, 10), 200) as $slot) {
            if ($slot['starts_at'] === $startsAt) {
                $match = $slot;
                break;
            }
        }

        if ($match === null) {
            // Re-checked at confirm and it is no longer conflict-free (or never was). Surfaced in the
            // same plain form as the service's own refusals — there is no PHP translation catalogue,
            // so a translation key here would leak to the operator.
            return redirect()
                ->route('scheduling.appointments.show', $record->id)
                ->withErrors(['reschedule' => 'That slot is no longer available. Pick another one.']);
        }

        try {
            $moved = $appointments->reschedule(
                $record,
                $startsAt,
                $match['resource_ids'],
                $actor,
                $validated['reason'],
                // A person picked this slot, so it may not land in the past. THIS IS THE PATH THE
                // AUDIT REPRODUCED: confirming an offered morning slot late in the day moved a
                // patient 742 minutes backwards (P1-H3). The finder no longer offers such a slot;
                // this refuses it anyway, for a stale or forged submission.
                allowPastStart: false,
            );
        } catch (BookingConflictException|BookingUnavailableException|IllegalAppointmentTransitionException|InvalidArgumentException $e) {
            // The overlap guard (or the machine, or the reason rule) refused it. Nothing moved.
            return redirect()
                ->route('scheduling.appointments.show', $record->id)
                ->withErrors(['reschedule' => $e->getMessage()]);
        }

        // The old appointment is now terminal — send the operator to the appointment that exists.
        return redirect()->route('scheduling.appointments.show', $moved->id);
    }

    /**
     * The reschedule panel: whether the move is legal from here, plus the REAL finder's conflict-free
     * slots for THIS appointment's own service and branch.
     *
     * The finder is per-date by design, so this MERGES its per-date answers across the next fortnight —
     * a merge of engine results, never a page-side availability computation. The constraint chips the
     * wireframe draws are simply a description of what the finder already applies (the same service,
     * hence the same duration and required resource types).
     *
     * The wireframe's "Dr. Weber only" toggle is DELIBERATELY ABSENT: `AvailableSlotFinder` takes no
     * preferred-resource parameter (it picks the first free resource of each required type), so
     * offering the control would be fabricating a filter the engine cannot honour. It is its own gate.
     *
     * @return array<string, mixed>
     */
    private function reschedulePanel(Appointment $record, ?Service $service, AvailableSlotFinder $finder): array
    {
        $legal = in_array(
            Appointment::STATUS_RESCHEDULED,
            AppointmentService::legalTransitionsFrom($record->status),
            true,
        );

        if (! $legal || ! $service instanceof Service) {
            return ['can_reschedule' => false, 'slots' => [], 'store_url' => null, 'scan_days' => self::RESCHEDULE_SCAN_DAYS];
        }

        $current = $record->starts_at->toDateTimeString();
        $names = Resource::query()->pluck('name', 'id');
        $offered = [];
        $day = CarbonImmutable::parse(now()->toDateString());

        for ($i = 0; $i < self::RESCHEDULE_SCAN_DAYS && count($offered) < self::MAX_SLOTS; $i++) {
            $date = $day->addDays($i)->toDateString();

            foreach ($finder->forServiceBranchDate($service, $record->branch_id, $date, self::SLOTS_PER_DAY) as $slot) {
                if ($slot['starts_at'] === $current || count($offered) >= self::MAX_SLOTS) {
                    continue;
                }

                $offered[] = [
                    'starts_at' => $slot['starts_at'],
                    'ends_at' => $slot['ends_at'],
                    // Which real resources the finder would use — display only; the server re-derives
                    // them at confirm.
                    'resources' => array_values(array_filter(array_map(
                        fn (string $id): ?string => $names[$id] ?? null,
                        $slot['resource_ids'],
                    ))),
                ];
            }
        }

        return [
            'can_reschedule' => true,
            'slots' => $offered,
            'store_url' => route('scheduling.appointments.reschedule', $record->id),
            'scan_days' => self::RESCHEDULE_SCAN_DAYS,
            'duration_minutes' => $service->default_duration_minutes,
            'service' => $service->name,
        ];
    }

    /**
     * The action row for the appointment's ACTUAL status, derived from the machine's own map.
     *
     * THE RECONCILIATION (the audit's option (a)): the page shows the TRUE status, so it offers exactly
     * that status's legal moves — a genuinely BOOKED appointment offers **Confirm**, never "Mark
     * arrived", because `booked → arrived` is not an edge. Arrive appears once the appointment IS
     * confirmed, where it is a single legal edge. No shortcut is composed here and no edge is added.
     *
     * `rescheduled` is filtered out: it is a legal target, but reaching it needs the slot finder and the
     * overlap guard, so it belongs to the reschedule modal (APPT.P3), not this row.
     *
     * @return list<array{action: string, to_status: string, requires_reason: bool, url: string}>
     */
    private function legalActions(Appointment $record): array
    {
        $statusToAction = array_flip(self::ACTION_TO_STATUS);
        $actions = [];

        foreach (AppointmentService::legalTransitionsFrom($record->status) as $toStatus) {
            if ($toStatus === Appointment::STATUS_RESCHEDULED || ! isset($statusToAction[$toStatus])) {
                continue;
            }

            $actions[] = [
                'action' => $statusToAction[$toStatus],
                'to_status' => $toStatus,
                // Only cancellation actually requires one (the service throws without it).
                'requires_reason' => $toStatus === Appointment::STATUS_CANCELLED,
                'url' => route('scheduling.appointments.transition', $record->id),
            ];
        }

        return $actions;
    }

    /**
     * The appointment's REAL linked resources (practitioner / room / chair / vehicle). Only recorded
     * fields — `Resource` carries no capability/equipment data, so none is shown or invented.
     *
     * @return list<array{id: string, name: string, type: string}>
     */
    private function resources(Appointment $record): array
    {
        $resourceIds = $record->resourceLinks()->orderBy('resource_id')->pluck('resource_id')->all();

        if ($resourceIds === []) {
            return [];
        }

        return Resource::query()
            ->whereIn('id', $resourceIds)
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Resource $resource): array => [
                'id' => $resource->id,
                'name' => $resource->name,
                'type' => $resource->type,
            ])
            ->values()
            ->all();
    }

    /**
     * The appointment's REAL history, newest last: the append-only `audit_events` recorded for this
     * appointment (booked / confirmed / arrived / … with their from→to statuses and actor) merged with
     * its real `AppointmentReminder` rows.
     *
     * HONEST LABELS: a reminder's channel is whatever the row actually says — today the only channel
     * that exists is email (`AppointmentReminder::CHANNEL_EMAIL`; SMS/WhatsApp drivers are deferred),
     * so the page can never claim an SMS was sent. Likewise the confirmation line carries the REAL
     * recorded actor, not an invented "patient replied" provenance.
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(Appointment $record): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach (AuditEvent::query()
            ->where('resource_type', 'appointment')
            ->where('resource_id', $record->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get() as $event) {
            $context = is_array($event->context) ? $event->context : [];
            $reason = $event->getAttribute('reason');

            $rows[] = [
                'kind' => 'status',
                'action' => $event->action,
                'from_status' => isset($context['from_status']) ? (string) $context['from_status'] : null,
                'to_status' => isset($context['to_status']) ? (string) $context['to_status'] : null,
                'reason' => is_string($reason) ? $reason : null,
                // The RECORDED actor kind. A portal action is attributed to the patient, a scheduled
                // job to the system — the page never resolves a patient id against `users`.
                'actor_type' => $event->actor_type,
                'actor' => $event->actor_type === 'user' ? $this->userName($event->actor_id) : null,
                'occurred_at' => $event->occurred_at,
            ];
        }

        foreach (AppointmentReminder::query()
            ->where('appointment_id', $record->id)
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get() as $reminder) {
            $rows[] = [
                'kind' => 'reminder',
                'action' => 'appointment.reminder',
                'reminder_type' => $reminder->type,
                // The REAL channel recorded on the row — never embellished.
                'channel' => $reminder->channel,
                'status' => $reminder->status,
                'failure_reason' => $reminder->failure_reason,
                'occurred_at' => ($reminder->sent_at ?? $reminder->scheduled_for)->toDateTimeString(),
            ];
        }

        usort($rows, fn (array $a, array $b): int => (string) ($a['occurred_at'] ?? '') <=> (string) ($b['occurred_at'] ?? ''));

        return $rows;
    }

    private function userName(int|string|null $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        return User::query()->whereKey($userId)->value('name');
    }
}
