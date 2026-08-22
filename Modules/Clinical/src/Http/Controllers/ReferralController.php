<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\Referral;
use Modules\Clinical\Services\ReferralService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;

/**
 * Referral Out — the patient-scoped referral surface over the EXISTING referral backend.
 *
 * NET-NEW UI, NOT A NEW MECHANISM. Every write goes through `ReferralService`, which owns the
 * state machine (draft → sent → accepted|declined → completed), enforces `note.write` and audits
 * each transition. This controller validates input and calls it; it never writes a status itself.
 *
 * THE REFERRAL IS A DISCLOSURE OF PHI, so opening this screen is a read of the patient's record
 * and writes exactly ONE audit row through the existing `auditRead()` path — the same single-row
 * rule as Patient 360 (PC.P1) and the access log (PC.P5), which is why the row shows up in that
 * log. There is no second audit path here: the transitions are audited by the service, as they
 * already were before this screen existed.
 *
 * THE CLINICIAN AUTHORS THE REFERRAL. The reason is their free text; the specialty and the
 * recipient are what they typed or chose. CareOS computes NO urgency, NO priority, NO triage
 * level, and never suggests or ranks a specialist — deciding whom to refer to and why is the
 * clinical judgment this system does not make. Referrals are ordered by when they were created,
 * never by a computed importance.
 *
 * THREE THINGS THE WIREFRAME DRAWS THAT HAVE NO BACKEND, OMITTED AND STATED ON SCREEN (D-170):
 *  - an URGENCY field ("soon · within 1 week"): `referrals` has no urgency column and nothing
 *    records one, so no urgency is shown. Inventing one would also hand the UI a value to tint by.
 *  - a SHARED/WITHHELD DOCUMENT PACKET: referrals have no attachment relation at all.
 *  - a PROVIDER DIRECTORY with known partners and triage times: `to_provider_name` is free text.
 *
 * AND THE BIGGEST ONE — CAREOS DOES NOT TRANSMIT ANYTHING. `ReferralService::send()` sets the
 * status and `sent_at`; there is no channel, no message, no document and no integration anywhere
 * in the codebase. "Sent" therefore means "the clinician recorded that they sent it". The screen
 * says so in those words rather than implying a transmission that does not happen.
 */
class ReferralController
{
    public function index(string $patient, ReferralService $referrals): Response
    {
        $record = $this->patient($patient);

        // ONE row per render, existing path: this screen discloses the patient's referrals.
        $record->auditRead(['surface' => 'referrals']);

        return Inertia::render('Clinical/Referrals', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'name' => trim($record->first_name.' '.$record->last_name),
                'date_of_birth' => $record->date_of_birth->toDateString(),
                'age' => $record->date_of_birth->age,
                'sex' => $record->sex,
                'chart_url' => route('clinical.chart', $record->id),
            ],
            /*
             * The patient's REAL recorded allergies, displayed as facts (the ALLERGY.P1 pattern):
             * a clinician writing a referral should see them. Ordered by substance, never by
             * severity, and the chips are styled identically whatever was recorded.
             */
            'allergies' => Allergy::query()
                ->where('patient_id', $record->id)
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
            // Newest first — a RECORDED timestamp, never a computed importance.
            'referrals' => Referral::query()
                ->where('patient_id', $record->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Referral $referral): array => $this->payload($referral))
                ->all(),
            /*
             * Internal branches are a REAL modelled destination (`to_branch_id`). External
             * recipients are free text, because that is what the backend records — no provider
             * directory is invented to fill the wireframe's "known referral partner" card.
             */
            'branches' => Branch::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])
                ->all(),
            'actions' => [
                'store_url' => route('clinical.referrals.store', $record->id),
                'can_write' => Gate::allows('note.write'),
            ],
        ]);
    }

    public function store(string $patient, Request $request, ReferralService $referrals): RedirectResponse
    {
        $record = $this->patient($patient);
        $actor = $this->actor($request);

        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'to_provider_name' => ['nullable', 'string', 'max:255'],
            'to_branch_id' => ['nullable', 'string'],
            'specialty' => ['nullable', 'string', 'max:255'],
            // The clinical reason is REQUIRED and is the clinician's own words.
            'reason' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $referrals->create($record, $actor, [
            'direction' => Referral::DIRECTION_OUTBOUND,
            'to_provider_name' => $data['to_provider_name'] ?? null,
            'to_branch_id' => $data['to_branch_id'] ?? null,
            'specialty' => $data['specialty'] ?? null,
            'reason' => $data['reason'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('clinical.referrals', $record->id);
    }

    public function send(string $referral, Request $request, ReferralService $referrals): RedirectResponse
    {
        $record = $this->referral($referral);
        $referrals->send($record, $this->actor($request));

        return redirect()->route('clinical.referrals', $record->patient_id);
    }

    public function respond(string $referral, Request $request, ReferralService $referrals): RedirectResponse
    {
        $record = $this->referral($referral);

        /** @var array{status: string, notes?: string|null} $data */
        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.Referral::STATUS_ACCEPTED.','.Referral::STATUS_DECLINED],
            'notes' => ['nullable', 'string'],
        ]);

        $referrals->respond($record, $data['status'], $this->actor($request), $data['notes'] ?? null);

        return redirect()->route('clinical.referrals', $record->patient_id);
    }

    public function complete(string $referral, Request $request, ReferralService $referrals): RedirectResponse
    {
        $record = $this->referral($referral);
        $referrals->complete($record, $this->actor($request));

        return redirect()->route('clinical.referrals', $record->patient_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Referral $referral): array
    {
        return [
            'id' => $referral->id,
            'direction' => $referral->direction,
            // The RECORDED lifecycle status. Displayed as a word; nothing is ranked or tinted by it.
            'status' => $referral->status,
            'specialty' => $referral->specialty,
            'reason' => $referral->reason,
            'to_provider_name' => $referral->to_provider_name,
            'from_provider_name' => $referral->from_provider_name,
            'to_branch_id' => $referral->to_branch_id,
            'sent_at' => $referral->sent_at?->toDateTimeString(),
            'responded_at' => $referral->responded_at?->toDateTimeString(),
            'notes' => $referral->notes,
            'created_at' => $referral->created_at?->toDateTimeString(),
            /*
             * WHICH TRANSITIONS THE SERVICE WOULD ALLOW, mirrored here so the page can render the
             * right buttons. This is DISPLAY ONLY and grants nothing: `ReferralService` re-checks
             * both the permission and the current status on every call, so hiding or showing a
             * button changes an affordance, not a rule (D-168).
             */
            'can_send' => $referral->status === Referral::STATUS_DRAFT,
            'can_respond' => $referral->status === Referral::STATUS_SENT,
            'can_complete' => $referral->status === Referral::STATUS_ACCEPTED,
            'urls' => [
                'send' => route('clinical.referrals.send', $referral->id),
                'respond' => route('clinical.referrals.respond', $referral->id),
                'complete' => route('clinical.referrals.complete', $referral->id),
            ],
        ];
    }

    /**
     * Resolved from a STRING, never route-model binding: implicit binding of a tenant-scoped
     * model runs before the tenant is identified and 500s (FIX.1). A patient from another tenant
     * is simply not found — fail closed, 404.
     */
    private function patient(string $patient): Patient
    {
        Gate::authorize('patient.view');

        return Patient::query()->whereKey($patient)->firstOrFail();
    }

    private function referral(string $referral): Referral
    {
        Gate::authorize('patient.view');

        return Referral::query()->whereKey($referral)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
