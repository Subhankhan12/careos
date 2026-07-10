<?php

namespace App\Http\Controllers\Portal;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
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
    public function __invoke(Request $request, ThreadService $threads): Response
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
        $unreadMessages = 0;
        foreach ($threads->threadsForPatient($patient) as $thread) {
            $unreadMessages += $threads->patientUnreadCount($thread, $patient);
        }

        $invoiceIds = Invoice::query()
            ->where('patient_id', $account->patient_id)
            ->whereNotNull('number')
            ->pluck('id');
        $outstandingMinor = (int) InvoiceBalance::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->sum('open_balance_minor');

        return Inertia::render('Portal/Home', [
            'nextAppointment' => $next !== null ? [
                'id' => $next->id,
                'service' => Service::query()->find($next->service_id)?->name,
                'starts_at' => $next->starts_at->toDateTimeString(),
                'status' => $next->status,
            ] : null,
            'unreadMessages' => (int) $unreadMessages,
            'outstandingBalanceMinor' => (int) $outstandingMinor,
        ]);
    }
}
