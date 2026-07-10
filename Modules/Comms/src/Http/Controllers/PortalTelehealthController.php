<?php

namespace Modules\Comms\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Comms\Models\TelehealthSession;
use Modules\Comms\Services\TelehealthService;
use Modules\Patients\Models\PortalAccount;

/**
 * Portal telehealth join. The G.4 join token is issued ON DEMAND through
 * TelehealthService's three-way fail-closed patient path (active portal
 * account + portal.access consent + being the session's patient), returned
 * transiently, and never stored or logged.
 */
class PortalTelehealthController
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);

        $sessions = TelehealthSession::query()
            ->where('patient_id', $account->patient_id)
            ->whereIn('status', [TelehealthSession::STATUS_CREATED, TelehealthSession::STATUS_ACTIVE])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Portal/Telehealth', [
            'sessions' => $sessions->map(fn (TelehealthSession $session): array => [
                'id' => $session->id,
                'provider' => $session->provider,
                'status' => $session->status,
                'created_at' => $session->created_at?->toDateTimeString(),
                'token_url' => route('portal.telehealth.token', $session->id),
            ])->all(),
        ]);
    }

    public function token(string $session, Request $request, TelehealthService $telehealth): JsonResponse
    {
        $account = $this->account($request);
        $record = TelehealthSession::query()->whereKey($session)->firstOrFail();

        // Three-way fail-closed patient gate + audit + read-log live in the
        // service; the token exists only in this response.
        $token = $telehealth->joinTokenForPatient($record, $account);

        return response()->json([
            'token' => $token->token,
            'room' => $token->roomReference,
            'role' => $token->role,
            'expires_at' => $token->expiresAt->toIso8601String(),
        ]);
    }

    private function account(Request $request): PortalAccount
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        return $account;
    }
}
