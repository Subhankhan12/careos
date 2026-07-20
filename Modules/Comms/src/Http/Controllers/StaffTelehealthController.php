<?php

namespace Modules\Comms\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Comms\Models\TelehealthSession;
use Modules\Comms\Services\TelehealthService;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;

/**
 * Staff telehealth join (CLINIC.W10) — the clinician side of the SAME telehealth
 * sessions the portal patient joins (W3 wired the patient side, `PortalTelehealth
 * Controller`). It lists the clinician's created/active sessions and issues the
 * EXISTING staff join token via `TelehealthService::joinTokenForStaff`.
 *
 * No new telehealth logic: media never touches CareOS servers, recording stays
 * DISABLED at the provider (grants pin roomRecord/roomAdmin/recorder = false), the
 * token is short-lived (<= 600s) + never stored/logged, and the "not recorded"
 * discipline is displayed. The service re-authorizes per session (encounter.manage /
 * appointment.manage), asserts tenant, audits, and read-logs. The page gate is
 * `encounter.manage` (the clinician permission); tenant-scoped; the token is returned
 * transiently in the response only.
 */
class StaffTelehealthController
{
    public function index(Request $request): Response
    {
        Gate::authorize('encounter.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        // Surface only the CURRENT clinician's own sessions (they are the practitioner). No
        // staff profile → a sentinel practitioner id that matches nothing, keeping the query typed.
        $profile = StaffProfile::query()->where('user_id', $actor->id)->first();

        $sessions = TelehealthSession::query()
            ->where('practitioner_id', $profile === null ? '__none__' : $profile->id)
            ->whereIn('status', [TelehealthSession::STATUS_CREATED, TelehealthSession::STATUS_ACTIVE])
            ->orderByDesc('created_at')
            ->get();

        // Resolve patient names in one typed query (the belongsTo relation is untyped for L5).
        $patientNames = Patient::query()
            ->whereKey($sessions->pluck('patient_id')->all())
            ->get()
            ->mapWithKeys(fn (Patient $patient): array => [$patient->id => trim($patient->first_name.' '.$patient->last_name)]);

        return Inertia::render('Telehealth/Sessions', [
            'sessions' => $sessions->map(fn (TelehealthSession $session): array => [
                'id' => $session->id,
                'patientName' => $patientNames->get($session->patient_id) ?: null,
                'provider' => $session->provider,
                'status' => $session->status,
                'createdAt' => $session->created_at?->toIso8601String(),
                'tokenUrl' => route('telehealth.token', $session->id),
            ])->all(),
        ]);
    }

    public function token(string $session, Request $request, TelehealthService $telehealth): JsonResponse
    {
        Gate::authorize('encounter.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = TelehealthSession::query()->whereKey($session)->firstOrFail();

        // Existing staff path: re-authorizes against the session's own permission,
        // asserts tenant, issues a recording-disabled short-lived token, audits +
        // read-logs. The token exists only in this response — never stored, never logged.
        $token = $telehealth->joinTokenForStaff($record, $actor);

        return response()->json([
            'token' => $token->token,
            'room' => $token->roomReference,
            'role' => $token->role,
            'expires_at' => $token->expiresAt->toIso8601String(),
        ]);
    }
}
