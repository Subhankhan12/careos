<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;

/**
 * Portal consents: the patient's OWN consent captures. Withdrawal goes through
 * the Phase B ConsentService (audited, immutable snapshots); withdrawing
 * portal.access locks the portal on the very next request via the
 * portal-consent middleware.
 */
class PortalConsentController
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);

        $consents = PatientConsent::query()
            ->where('patient_id', $account->patient_id)
            ->orderByDesc('granted_at')
            ->get();

        return Inertia::render('Portal/Consents', [
            'consents' => $consents->map(fn (PatientConsent $consent): array => [
                'id' => $consent->id,
                'template_key' => $consent->template_key,
                'title' => $consent->template_title,
                'scope_keys' => $consent->template_scope_keys,
                'status' => $consent->status,
                'granted_at' => $consent->granted_at?->toDateTimeString(),
                'withdrawn_at' => $consent->withdrawn_at?->toDateTimeString(),
            ])->all(),
            'actions' => [
                'withdrawUrl' => route('portal.consents.withdraw'),
            ],
        ]);
    }

    public function withdraw(Request $request, ConsentService $consents): RedirectResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'consent_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // Own consent rows only — fail-closed.
        $consent = PatientConsent::query()
            ->whereKey($data['consent_id'])
            ->where('patient_id', $account->patient_id)
            ->where('status', PatientConsent::STATUS_GRANTED)
            ->firstOrFail();

        $consents->withdraw($consent, $data['reason']);

        return redirect()->route('portal.consents');
    }

    private function account(Request $request): PortalAccount
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        return $account;
    }
}
