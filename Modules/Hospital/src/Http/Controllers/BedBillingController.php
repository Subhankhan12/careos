<?php

namespace Modules\Hospital\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\BedBillingService;
use Modules\Platform\Models\User;

/**
 * Bed-to-billing action (HOSPITAL.G6) — "invoice this stay": assemble the stay's accrued bed-day +
 * service charges into an invoice via the EXISTING billing engine and redirect to the existing invoice
 * page. PRESENTATIONAL over BedBillingService (P0D.GU) — no billing logic here. String-id {stay}
 * (FIX.1); gated `billing.manage`. Additive to the ADT flow — G2's discharge is untouched.
 */
class BedBillingController
{
    public function invoice(Request $request, string $stay, BedBillingService $billing): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Stay::query()->whereKey($stay)->firstOrFail();

        try {
            $invoice = $billing->invoiceStay($actor, $record);
        } catch (AdmissionException|InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        // Reuse the EXISTING billing invoice page for the result.
        return redirect()->route('billing.invoices.show', $invoice->id)->with('status', 'stay-invoiced');
    }
}
