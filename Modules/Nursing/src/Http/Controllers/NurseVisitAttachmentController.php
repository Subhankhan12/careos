<?php

namespace Modules\Nursing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Nursing\Models\VisitAttachment;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Resource;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NurseVisitAttachmentController
{
    public function __invoke(Request $request, string $attachment): StreamedResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->tokenCan('nurse:day-pack')) {
            abort(403);
        }

        /*
         * RESOLVED FROM A STRING ID, NEVER ROUTE-MODEL BINDING (QA-FIX.12a).
         *
         * `VisitAttachment` is `BelongsToTenant`, and `IdentifyTenantFromUser` is APPENDED to the api
         * group — so it runs AFTER `SubstituteBindings`. Implicit binding therefore queried a
         * tenant-scoped model with no tenant context and the route answered **HTTP 500 for every
         * caller**: driven live with a valid `nurse:day-pack` token, it returned
         * `TenantContextMissingException` and streamed nothing at all. This is the repo's documented
         * hazard (the C-1 / FIX.1 lesson), and it means `QF10a-H1`'s premise was half wrong — the
         * disclosure it describes could not occur, so auditing alone would have been an unbacked
         * presence (D-176). The route is made to WORK first, then recorded.
         *
         * Resolving here runs after the tenant middleware, so the scope applies and an attachment
         * belonging to another tenant is simply NOT FOUND — fail-closed, 404 rather than a leak.
         */
        $record = VisitAttachment::query()->whereKey($attachment)->firstOrFail();

        $staffIds = StaffProfile::query()
            ->where('user_id', $user->id)
            ->where('status', StaffProfile::STATUS_ACTIVE)
            ->pluck('id');

        $canAccess = Resource::query()
            ->where('type', Resource::TYPE_PRACTITIONER)
            ->where('active', true)
            ->whereIn('staff_profile_id', $staffIds)
            ->whereKey($record->visit()->value('resource_id'))
            ->exists();

        if (! $canAccess) {
            abort(403);
        }

        /*
         * `QF10a-H1` (QA-FIX.12a) — RECORDED BEFORE THE BYTES ARE HANDED OVER.
         *
         * The guard above is real and is not the point: this route was correctly narrowed to the
         * caller's own visits and still wrote NOTHING, so the patient could not learn their
         * home-visit photo or signature had been downloaded. One row, the EXISTING `auditRead()`
         * path, action `read` — already in `PatientAccessReport::DISCLOSURE_ACTIONS`, so it reaches
         * the patient's log without inventing an action (D-221).
         *
         * Recorded BEFORE the stream, matching EVERY other audited download in the codebase (verified:
         * `InvoiceController::download`, `PortalInvoiceController::download`, `DocumentDownloadController`,
         * `PortalDocumentController::download` all record first): a client that
         * disconnects mid-transfer must not be able to skip the record.
         */
        $record->auditRead(['surface' => 'nurse_visit_attachment_download', 'type' => $record->type]);

        return Storage::disk('local')->download(
            $record->storage_path,
            $record->type.'.'.pathinfo($record->storage_path, PATHINFO_EXTENSION),
            ['Content-Type' => $record->mime_type],
        );
    }
}
