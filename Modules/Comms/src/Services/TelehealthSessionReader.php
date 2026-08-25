<?php

namespace Modules\Comms\Services;

use Illuminate\Support\Collection;
use Modules\Comms\Models\TelehealthParticipant;
use Modules\Comms\Models\TelehealthSession;
use Modules\Patients\Models\Patient;
use Modules\Scheduling\Models\Appointment;

/**
 * COMMS.P2 — what the telehealth surfaces may say about a session.
 *
 * ── WHAT THE BACKEND ACTUALLY RECORDS, AND WHAT IT DOES NOT ─────────────────────────────────────
 *
 * RECORDED: that a session was created; that a join token was ISSUED (an audit row); that a
 * participant JOINED (`telehealth_participants.joined_at`); that one LEFT (`left_at`, settable
 * exactly once); that the session STARTED (`started_at`, stamped by the first join) and ENDED
 * (`ended_at`).
 *
 * **NOT RECORDED: whether anyone is connected RIGHT NOW.** `left_at` is only written when something
 * calls `TelehealthService::recordLeave()`. A participant whose connection simply dropped — the
 * common case — leaves `left_at` null forever. So "joined and has not left" is NOT the same claim as
 * "is currently in the call", and this reader never makes the second one. The wireframe's pulsing
 * "patient waiting" indicator, its wait-time threshold and its amber escalation all rest on live
 * presence, which no query here can answer; they are omitted and the omission is stated on screen.
 *
 * **A TOKEN IS NOT A JOIN (D-179).** Issuing a token means someone asked to join, not that they did.
 * The two are audited separately and this reader counts only the participant rows. Nothing here
 * infers a join from a token.
 *
 * ── WHAT THIS READER NEVER PRODUCES ─────────────────────────────────────────────────────────────
 *
 * No recording state, no transcript, no AI summary of a consultation, no connection-quality score or
 * grade, no wait-time band or urgency tint (D-169), and no "live" flag. Ordering is by the
 * appointment's own time — a date sort over a recorded value, never a priority.
 */
class TelehealthSessionReader
{
    /** Created, and nobody has joined yet. */
    public const STATE_SCHEDULED = 'scheduled';

    /** At least one participant has a recorded join, and the session has not been ended. */
    public const STATE_JOINED = 'joined';

    /** Ended through the real path. */
    public const STATE_ENDED = 'ended';

    /**
     * The sessions this clinician is the practitioner for.
     *
     * @return list<array<string, mixed>>
     */
    public function forPractitioner(?string $practitionerId, ?string $state = null): array
    {
        if ($practitionerId === null) {
            return [];
        }

        $sessions = TelehealthSession::query()
            ->where('practitioner_id', $practitionerId)
            ->when($state === self::STATE_ENDED, fn ($query) => $query->where('status', TelehealthSession::STATUS_ENDED))
            ->when(
                $state === self::STATE_SCHEDULED || $state === self::STATE_JOINED,
                fn ($query) => $query->whereIn('status', [TelehealthSession::STATUS_CREATED, TelehealthSession::STATUS_ACTIVE]),
            )
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $rows = $this->present($sessions);

        // `scheduled` vs `joined` is derived from the participant rows, not from a column, so the
        // filter has to be applied after presentation rather than in SQL.
        if ($state === self::STATE_SCHEDULED || $state === self::STATE_JOINED) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['state'] === $state));
        }

        return $rows;
    }

    /**
     * Plain counts of rows that exist — counted over the whole record, never over a capped list.
     *
     * @return array{scheduled: int, joined: int, ended: int}
     */
    public function countsForPractitioner(?string $practitionerId): array
    {
        if ($practitionerId === null) {
            return ['scheduled' => 0, 'joined' => 0, 'ended' => 0];
        }

        $rows = $this->present(
            TelehealthSession::query()->where('practitioner_id', $practitionerId)->get()
        );

        return [
            'scheduled' => count(array_filter($rows, fn (array $r): bool => $r['state'] === self::STATE_SCHEDULED)),
            'joined' => count(array_filter($rows, fn (array $r): bool => $r['state'] === self::STATE_JOINED)),
            'ended' => count(array_filter($rows, fn (array $r): bool => $r['state'] === self::STATE_ENDED)),
        ];
    }

    /**
     * One session, presented — used by the pre-join surface.
     *
     * @return array<string, mixed>
     */
    public function presentOne(TelehealthSession $session): array
    {
        return $this->present(new Collection([$session]))[0];
    }

    /**
     * @param  Collection<int, TelehealthSession>  $sessions
     * @return list<array<string, mixed>>
     */
    private function present(Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        $patientNames = Patient::query()
            ->whereKey($sessions->pluck('patient_id')->filter()->unique()->all())
            ->get()
            ->mapWithKeys(fn (Patient $p): array => [$p->id => trim($p->first_name.' '.$p->last_name)]);

        $appointments = Appointment::query()
            ->whereKey($sessions->pluck('appointment_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        /*
         * Presented up front into a plain `session_id => list<array>` map. The rows are already the
         * shape the page receives, so nothing downstream re-reads a model — and the types stay
         * concrete rather than a nested generic collection.
         *
         * @var array<string, list<array<string, mixed>>> $participants
         */
        $participants = [];

        foreach (
            TelehealthParticipant::query()
                ->whereIn('session_id', $sessions->pluck('id')->all())
                ->orderBy('joined_at')
                ->get() as $leg
        ) {
            $participants[$leg->session_id][] = [
                'id' => $leg->id,
                'type' => $leg->participant_type,
                'joinedAt' => $leg->joined_at->toIso8601String(),
                /*
                 * Null BOTH when someone is still in the call and when their connection dropped
                 * without reporting — which is why the page renders "joined at HH:MM" and never
                 * "currently connected".
                 */
                'leftAt' => $leg->left_at?->toIso8601String(),
            ];
        }

        return $sessions->map(function (TelehealthSession $session) use ($patientNames, $appointments, $participants): array {
            $legs = $participants[$session->id] ?? [];
            $appointment = $session->appointment_id === null ? null : $appointments->get($session->appointment_id);

            return [
                'id' => $session->id,
                'patientName' => $patientNames->get($session->patient_id),
                'provider' => $session->provider,
                // The RAW recorded status, alongside the derived state, so the page never has to
                // guess and a reader can see both.
                'status' => $session->status,
                'state' => $this->stateOf($session, $legs),
                'createdAt' => $session->created_at?->toIso8601String(),
                'startedAt' => $session->started_at?->toIso8601String(),
                'endedAt' => $session->ended_at?->toIso8601String(),
                // The appointment this session belongs to — its real scheduled time.
                'appointmentAt' => $appointment?->starts_at?->toIso8601String(),
                'hasEncounter' => $session->encounter_id !== null,
                // The recorded joins, presented above.
                'participants' => $legs,
                'joinUrl' => route('telehealth.token', $session->id),
                // Joining an ended session is refused by the service; the page must not offer it.
                'joinable' => $session->status !== TelehealthSession::STATUS_ENDED,
            ];
        })->values()->all();
    }

    /**
     * The state is DERIVED — from the recorded status and the recorded joins — never read from a
     * single column that something could have written directly.
     *
     * @param  list<array<string, mixed>>  $legs
     */
    private function stateOf(TelehealthSession $session, array $legs): string
    {
        if ($session->status === TelehealthSession::STATUS_ENDED) {
            return self::STATE_ENDED;
        }

        return $legs === [] ? self::STATE_SCHEDULED : self::STATE_JOINED;
    }

    /**
     * Whether the configured provider can actually mint a token.
     *
     * `LiveKitProvider` throws when its secret is empty, and the deploy template ships
     * `livekit.invalid` with no credentials — so on an unconfigured install every Join would fail at
     * the moment of use. Offering a control that cannot work is exactly the unbacked affordance
     * D-176 forbids, so the surface asks this first and says so plainly instead.
     */
    public function providerConfigured(): bool
    {
        $provider = (string) config('telehealth.provider', 'livekit');

        if ($provider === 'fake') {
            return true;
        }

        $settings = (array) config('telehealth.providers.'.$provider, []);

        return trim((string) ($settings['api_key'] ?? '')) !== ''
            && trim((string) ($settings['api_secret'] ?? '')) !== '';
    }
}
