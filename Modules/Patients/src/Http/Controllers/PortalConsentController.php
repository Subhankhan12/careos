<?php

namespace Modules\Patients\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Platform\Models\User;

/**
 * Portal consents: the patient's OWN consent captures. Withdrawal goes through
 * the Phase B ConsentService (audited, immutable snapshots); withdrawing
 * portal.access locks the portal on the very next request via the
 * portal-consent middleware.
 */
class PortalConsentController
{
    /**
     * The consents this product has plain-language copy for — and, not coincidentally, the only
     * two scopes anything in CareOS actually enforces:
     *
     *   - `portal`  → `portal.access`, checked by the portal middleware on every request, by the
     *     login service, and again inside the document, messaging and telehealth services.
     *   - `comms`   → `comms.email`, checked by NotificationService, the appointment-reminder job
     *     and the recall draft tool.
     *
     * A consent outside this list is still LISTED (it is the patient's record), but the page says
     * plainly that we cannot describe what withdrawing it would change — better than inventing a
     * consequence the code does not enforce (D-176).
     *
     * @var list<string>
     */
    private const DESCRIBED_CONSENTS = ['portal', 'comms'];

    public function index(Request $request): Response
    {
        $account = $this->account($request);

        // PT.P1 — the patient is reading their own record: one read row per render, through
        // the EXISTING auditRead() path, so this disclosure appears in their access log (PC.P5).
        Patient::query()->whereKey($account->patient_id)->firstOrFail()
            ->auditRead(['surface' => 'portal_consents']);

        $consents = PatientConsent::query()
            ->where('patient_id', $account->patient_id)
            ->orderByDesc('granted_at')
            ->get();

        // One query for every capturer, rather than one per row.
        $capturers = User::query()
            ->whereIn('id', $consents->pluck('captured_by')->unique()->all())
            ->pluck('name', 'id');

        return Inertia::render('Portal/Consents', [
            'consents' => $consents->map(fn (PatientConsent $consent): array => [
                'id' => $consent->id,
                'template_key' => $consent->template_key,
                'title' => $consent->template_title,
                'scope_keys' => $consent->template_scope_keys,
                'status' => $consent->status,
                'granted_at' => $consent->granted_at?->toDateTimeString(),
                'withdrawn_at' => $consent->withdrawn_at?->toDateTimeString(),
                /*
                 * Who captured it — a recorded fact (the staff member who took the signature).
                 * `captured_by` is NOT NULL and FK-restricted, so a capture always HAS one; the
                 * null fallback covers only a capturer outside this tenant scope, where the page
                 * omits the line rather than printing an id.
                 */
                'captured_by' => $capturers[$consent->captured_by] ?? null,
                /*
                 * PT.P5 — which copy block describes this consent. The page looks the purpose and
                 * the CONSEQUENCE up by this key, and the consequence text is written to match the
                 * code that actually enforces the scope. `null` means the product has no copy for
                 * this consent, and the page says so rather than inventing a reassurance.
                 */
                'copy_key' => in_array($consent->template_key, self::DESCRIBED_CONSENTS, true)
                    ? $consent->template_key
                    : null,
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
