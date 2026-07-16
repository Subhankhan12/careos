<?php

namespace Modules\Scheduling\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\WaitlistEntry;
use Modules\Scheduling\Models\WaitlistOffer;
use Modules\Scheduling\Services\WaitlistOfferService;

class WaitlistOfferController
{
    /**
     * JSON: matching waitlist candidates for a freed slot, ranked.
     */
    public function candidates(Request $request, WaitlistOfferService $offers): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ]);

        Gate::authorize('appointment.manage', ['branch_id' => $data['branch_id']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $candidates = $offers->candidates(
            $data['service_id'],
            $data['branch_id'],
            $data['starts_at'],
            $data['ends_at'],
            $actor,
        )->map(function (WaitlistEntry $entry): array {
            $patient = Patient::query()->find($entry->patient_id);

            return [
                'waitlist_entry_id' => $entry->id,
                'patient_id' => $entry->patient_id,
                'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
                'priority' => $entry->priority,
                'flexible' => $entry->flexible,
                'desired_starts_at' => $entry->desired_starts_at?->toDateTimeString(),
                'desired_ends_at' => $entry->desired_ends_at?->toDateTimeString(),
            ];
        })->all();

        return response()->json(['candidates' => $candidates]);
    }

    public function offer(Request $request, WaitlistOfferService $offers): RedirectResponse
    {
        $data = $request->validate([
            'waitlist_entry_id' => ['required', 'string'],
            'branch_id' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'resource_ids' => ['required', 'array', 'min:1'],
            'resource_ids.*' => ['required', 'string'],
            'source_appointment_id' => ['nullable', 'string'],
        ]);

        Gate::authorize('appointment.manage', ['branch_id' => $data['branch_id']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $entry = WaitlistEntry::query()->findOrFail($data['waitlist_entry_id']);

        $offers->offer(
            $entry,
            $data['branch_id'],
            $data['starts_at'],
            $data['ends_at'],
            array_values($data['resource_ids']),
            $actor,
            $data['source_appointment_id'] ?? null,
        );

        return back();
    }

    public function accept(Request $request, WaitlistOfferService $offers): RedirectResponse
    {
        $data = $request->validate(['offer_id' => ['required', 'string']]);

        $offer = WaitlistOffer::query()->findOrFail($data['offer_id']);
        Gate::authorize('appointment.manage', ['branch_id' => $offer->branch_id]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $offers->accept($offer, $actor);

        return back();
    }

    public function decline(Request $request, WaitlistOfferService $offers): RedirectResponse
    {
        $data = $request->validate(['offer_id' => ['required', 'string']]);

        $offer = WaitlistOffer::query()->findOrFail($data['offer_id']);
        Gate::authorize('appointment.manage', ['branch_id' => $offer->branch_id]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $offers->decline($offer, $actor);

        return back();
    }
}
