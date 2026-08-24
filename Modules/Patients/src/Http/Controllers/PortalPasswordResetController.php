<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Services\PortalAccessService;

/**
 * Portal password recovery (PT.P7) — the last thing a patient could not do for themselves.
 *
 * Fortify's reset broker runs over `users`; portal accounts live in `portal_accounts` and were never
 * covered by it, so a patient who forgot their password had to ask the practice to re-invite them.
 * This is the missing broker, built on the token machinery PT.P6 validated rather than beside it.
 *
 * The guest posture, unchanged from the invite page:
 *
 *  - **the request form answers identically to everyone.** Live account, unknown address, patient
 *    who was never invited, disabled account — one response, so a public form cannot be used to
 *    discover who holds an account here (D-185);
 *  - **every dead token renders the same generic page** — unknown, expired, already used, wrong
 *    purpose, or bound to an account outside its own tenant;
 *  - **nothing is disclosed**: no patient name, no address, no clinical data, on any response.
 *
 * And a reset changes exactly one thing. It does not sign the patient in, so the `portal.access`
 * consent check in {@see PortalAccessService::login()} still decides whether they get in (PT.P5).
 */
class PortalPasswordResetController
{
    public function request(): Response
    {
        return Inertia::render('Portal/Password/Forgot');
    }

    public function send(Request $request, PortalAccessService $portal): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        /*
         * The service returns void and reveals nothing, and this response is built the same way for
         * every outcome — including the ones where no email was sent. Hence the wording the page
         * uses: "IF an account exists for that address". Saying "we've emailed you" would be a claim
         * about an action that, for three of the four subjects, was never taken (D-179).
         */
        $portal->requestPasswordReset($data['email']);

        return redirect()->route('portal.password.request')->with('status', 'portal.password.sent');
    }

    public function edit(string $token, PortalAccessService $portal): Response
    {
        $reset = $portal->previewPasswordReset($token);

        if ($reset === null) {
            return $this->refusal();
        }

        return Inertia::render('Portal/Password/Reset', [
            'valid' => true,
            'token' => $token,
            'practiceName' => $reset['practiceName'],
            'expiresAt' => $reset['expiresAt'],
        ]);
    }

    public function update(Request $request, string $token, PortalAccessService $portal): RedirectResponse|Response
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'max:16'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $portal->resetPassword($token, $data['otp'], $data['password']);
        } catch (ValidationException $exception) {
            // A dead token gets the generic page; a wrong code is reported as a wrong code, because
            // reaching that branch requires already holding a live token (the PT.P6 distinction).
            if (array_key_exists('token', $exception->errors())) {
                return $this->refusal();
            }

            throw $exception;
        }

        /*
         * NO AUTOMATIC SIGN-IN. The patient goes to the sign-in page and through the real login
         * path, where the portal.access consent re-check still applies. Logging them in here would
         * make password recovery a way around a gate PT.P5 built.
         */
        return redirect()->route('portal.login')->with('status', 'portal.password.reset');
    }

    /**
     * The ONE refusal — `valid: false` and nothing else, so unknown, expired, consumed,
     * wrong-purpose and cross-tenant tokens are indistinguishable.
     */
    private function refusal(): Response
    {
        return Inertia::render('Portal/Password/Reset', ['valid' => false]);
    }
}
