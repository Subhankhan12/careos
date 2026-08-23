<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Services\PortalAccessService;

/**
 * The patient-facing invite landing page (PT.P6) — the missing half of a flow that already
 * existed. Staff could issue an invitation and the backend could redeem one, but the emailed token
 * had no page to land on: enrolment was reachable only by POSTing JSON. This is that page.
 *
 * It is a GUEST surface reached from an email, so the posture is the staff-invite one
 * (SETTINGS.P6), not the portal one:
 *
 *  - the token is the ONLY credential that gets you here, and it is single-use, expiring and
 *    tenant-bound — the tenant is taken FROM the token, never from the session;
 *  - **every dead token looks the same.** Unknown, expired, already used, or bound to an account
 *    that is not in its own tenant: one generic page, identical in status and body, echoing
 *    nothing — not the token, not a patient, not a reason. A prober learns nothing they did not
 *    already know;
 *  - no clinical data appears here at all. The page shows the practice that invited you and the
 *    address the invitation was sent to (which the reader, holding that email, already has).
 *
 * Redemption runs through the EXISTING {@see PortalAccessService::acceptInvite} — the same path
 * the JSON route uses — so the account, the consent re-check, the single-use consumption and the
 * audit rows are the ones that were already there. Nothing is re-implemented here.
 */
class PortalInviteAcceptController
{
    public function show(string $token, PortalAccessService $portal): Response
    {
        $invite = $portal->previewInvite($token);

        if ($invite === null) {
            return $this->refusal();
        }

        return Inertia::render('Portal/AcceptInvite', [
            'valid' => true,
            'token' => $token,
            'email' => $invite['email'],
            'practiceName' => $invite['practiceName'],
            // The REAL expiry of this token. The wireframe's "expires 7 days after it was sent" is
            // not true of this product — a portal invite token lives 30 minutes — so the page
            // states what the row says rather than repeating the mock.
            'expiresAt' => $invite['expiresAt'],
        ]);
    }

    public function accept(Request $request, string $token, PortalAccessService $portal): RedirectResponse|Response
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'max:16'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $portal->acceptInvite($token, $data['otp'], $data['password']);
        } catch (ValidationException $exception) {
            /*
             * A DEAD TOKEN and a WRONG CODE are different situations and are answered differently
             * — deliberately.
             *
             * The token is refused with the same generic page the GET shows, because the four ways
             * a token can be dead must stay indistinguishable to someone probing tokens.
             *
             * A wrong code is reported as a wrong code, because reaching that branch AT ALL
             * requires already holding a live token: whoever sees it is the person the email was
             * sent to, and telling them their code is wrong discloses nothing they did not have.
             */
            if (array_key_exists('token', $exception->errors())) {
                return $this->refusal();
            }

            throw $exception;
        } catch (AuthorizationException) {
            // Portal consent was withdrawn between the invitation and this attempt. The invitation
            // genuinely is no longer valid, and the guest surface says exactly that — one page,
            // no reason, no 403 leaking out of a public route.
            return $this->refusal();
        }

        // acceptInvite() already logged the patient in on the `patient` guard and put the token's
        // tenant in the session; regenerate the id on the privilege change, as the login flows do.
        $request->session()->regenerate();

        return redirect()->route('portal.home');
    }

    /**
     * The ONE refusal. Every dead-token case renders this and only this — no token, no email, no
     * practice, no reason — so the four cases are byte-identical to a prober (`valid: false` and
     * nothing else).
     */
    private function refusal(): Response
    {
        return Inertia::render('Portal/AcceptInvite', ['valid' => false]);
    }
}
