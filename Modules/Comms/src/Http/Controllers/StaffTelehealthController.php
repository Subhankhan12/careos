<?php

namespace Modules\Comms\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Comms\Models\TelehealthSession;
use Modules\Comms\Services\TelehealthService;
use Modules\Comms\Services\TelehealthSessionReader;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;

/**
 * Staff telehealth (CLINIC.W10; parity + presence honesty in COMMS.P2) — the clinician side of the
 * SAME sessions the portal patient joins.
 *
 * No new telehealth logic: media never touches CareOS servers, recording stays DISABLED at the
 * provider, the token is short-lived (<= 600s) and never stored or logged, and participant rows are
 * append-only. This controller READS and DISPLAYS; the only write it can cause is the audit row the
 * existing token path already made.
 *
 * COMMS.P2 added: ENDED sessions (previously filtered out, so a clinician could not see what had
 * happened), the recorded participant joins, the linked appointment's real time, plain counts, a
 * pre-join surface, and an honest statement of what presence the backend can and cannot report.
 */
class StaffTelehealthController
{
    public function index(Request $request, TelehealthSessionReader $reader): Response
    {
        Gate::authorize('encounter.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $state = in_array($request->query('state'), [
            TelehealthSessionReader::STATE_SCHEDULED,
            TelehealthSessionReader::STATE_JOINED,
            TelehealthSessionReader::STATE_ENDED,
        ], true) ? (string) $request->query('state') : null;

        // Only the CURRENT clinician's own sessions (they are the practitioner). No staff profile
        // means no sessions — fail-closed, never "everyone's".
        $practitionerId = StaffProfile::query()->where('user_id', $actor->id)->value('id');

        return Inertia::render('Telehealth/Sessions', [
            'sessions' => $reader->forPractitioner($practitionerId, $state),
            'counts' => $reader->countsForPractitioner($practitionerId),
            'filters' => ['state' => $state],
            // Stated rather than discovered at the moment of failure (D-176).
            'providerConfigured' => $reader->providerConfigured(),
        ]);
    }

    /**
     * The pre-join surface. Everything the device check does happens in the BROWSER: it reports to
     * the clinician and is never sent here, never recorded, and never gates the join — the server's
     * answer to "may this person join?" is the same whether or not a camera was found.
     */
    public function show(string $session, Request $request, TelehealthSessionReader $reader): Response
    {
        Gate::authorize('encounter.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        // Tenant-scoped by the model's own global scope: another tenant's session is a 404 here,
        // not a 403, so the surface cannot even confirm that the id exists.
        $record = TelehealthSession::query()->whereKey($session)->firstOrFail();

        return Inertia::render('Telehealth/Join', [
            'session' => $reader->presentOne($record),
            'providerConfigured' => $reader->providerConfigured(),
        ]);
    }

    public function token(string $session, Request $request, TelehealthService $telehealth): JsonResponse
    {
        Gate::authorize('encounter.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = TelehealthSession::query()->whereKey($session)->firstOrFail();

        /*
         * The EXISTING staff path, unchanged: it re-authorizes against the session's own permission,
         * asserts tenant, refuses an ended session, issues a recording-disabled short-lived token,
         * audits and read-logs. The token exists only in this response.
         *
         * NOTE the request body is deliberately ignored. A client can claim its pre-check "passed";
         * the server neither reads nor trusts that claim, so a forged one changes nothing.
         */
        $token = $telehealth->joinTokenForStaff($record, $actor);

        return response()->json([
            'token' => $token->token,
            'room' => $token->roomReference,
            'role' => $token->role,
            'expires_at' => $token->expiresAt->toIso8601String(),
        ]);
    }
}
