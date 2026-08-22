<?php

namespace App\Http\Controllers\Portal;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Services\PatientBalanceReader;
use Modules\Clinical\Models\Document;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Service;

/**
 * Portal home: an app-layer composition (D-017) across Scheduling, Comms, and
 * Billing for the AUTHENTICATED patient only. Presentational page; every
 * number here is derived server-side.
 */
class PortalHomeController
{
    public function __invoke(Request $request, ThreadService $threads, PatientBalanceReader $balances): Response
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        $next = Appointment::query()
            ->where('patient_id', $account->patient_id)
            ->whereIn('status', [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        $patient = Patient::query()->whereKey($account->patient_id)->firstOrFail();

        // PT.P1 — the patient is reading their own record: one read row per render, through
        // the EXISTING auditRead() path, so this disclosure appears in their access log (PC.P5).
        $patient->auditRead(['surface' => 'portal_home']);

        $unreadMessages = 0;
        foreach ($threads->threadsForPatient($patient) as $thread) {
            $unreadMessages += $threads->patientUnreadCount($thread, $patient);
        }

        /*
         * PT.P2 — ONE source. This figure and the one on Portal Invoices now come from the same
         * reader, which applies the ENGINE's rule (Σ the projection's open balances, the tie
         * target `MetricsService::accountLedger()` asserts at δ=0). Previously Home derived it
         * here and the invoices page derived it again in the browser; with a credit note on the
         * account the two disagreed.
         */
        $outstanding = $balances->present($account->patient_id);

        /*
         * PT.P3 — SERVER-COMPUTED counts of real rows, never a Vue length over a partial payload
         * (the PC.P2 defect). `documents` counts what the practice has explicitly shared; nothing
         * else from the record can reach this number, because the same `shared_with_patient`
         * condition gates the documents screen itself.
         */
        $documentCount = Document::query()
            ->where('patient_id', $account->patient_id)
            ->where('shared_with_patient', true)
            ->count();

        $upcomingCount = Appointment::query()
            ->where('patient_id', $account->patient_id)
            ->whereIn('status', [Appointment::STATUS_BOOKED, Appointment::STATUS_CONFIRMED])
            ->where('starts_at', '>=', now())
            ->count();

        return Inertia::render('Portal/Home', [
            'nextAppointment' => $next !== null ? [
                'id' => $next->id,
                'service' => Service::query()->find($next->service_id)?->name,
                'starts_at' => $next->starts_at->toDateTimeString(),
                'status' => $next->status,
            ] : null,
            'unreadMessages' => (int) $unreadMessages,
            // The integer stays available; the STRING is what the page renders, so no portal
            // template divides by 100 (the DENTAL-B.P4 contract, applied patient-side).
            'counts' => [
                'documents' => $documentCount,
                'upcomingAppointments' => $upcomingCount,
                'unreadMessages' => (int) $unreadMessages,
            ],
            'outstandingBalanceMinor' => $outstanding['minor'],
            'outstandingBalance' => $outstanding['formatted'],
            'currency' => $outstanding['currency'],
        ]);
    }
}
