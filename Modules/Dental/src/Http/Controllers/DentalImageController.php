<?php

namespace Modules\Dental\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalImage;
use Modules\Dental\Models\DentalImageReading;
use Modules\Dental\Services\DentalImagingService;
use Modules\Dental\Support\ToothNotation;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

/**
 * The dental imaging UI (DENTAL.G8) — PRESENTATIONAL over the DentalImagingService (P0D.GU): upload
 * an image, view it (a basic 2D viewer, client-side zoom/pan on the raw pixels), and read/write the
 * DENTIST'S reading. The image bytes stream from the private disk through an authenticated route —
 * there is NO public URL.
 *
 * ELECTRIC FENCE carried into the UI: the payload carries the stored image + its metadata + the
 * dentist's written readings ONLY. There is NO ai/finding/detected/overlay/confidence field, NO
 * "AI findings" panel, NO auto-annotation — the system displays the image; it never analyses it.
 *
 * Live capture (X-ray sensor / intraoral scanner), DICOM/PACS, and 3D scan overlay/comparison are
 * PARTNER-GATED and out of scope; AI radiology / caries detection is a NON-GOAL (see DEFERRED).
 *
 * String-id params (FIX.1 / D-090). show/download = `patient.view`, store/reading = `dental.chart`.
 */
class DentalImageController
{
    public function show(Request $request, string $patient, DentalImagingService $imaging): InertiaResponse
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $images = $imaging->imagesFor($actor, $record);

        return Inertia::render('Dental/Imaging', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'name' => trim($record->first_name.' '.$record->last_name),
            ],
            'images' => $images->map(fn (DentalImage $image): array => $this->present($image))->values()->all(),
            'types' => DentalImage::TYPES,
            'teeth' => [
                'permanent' => ToothNotation::permanent(),
                'primary' => ToothNotation::primary(),
            ],
            'actions' => [
                'can_manage' => Gate::allows('dental.chart'),
                'store_url' => route('dental.imaging.store', $record->id),
            ],
        ]);
    }

    public function store(Request $request, string $patient, DentalImagingService $imaging): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:10240'],
            'image_type' => ['required', 'string', 'max:40'],
            'tooth' => ['nullable', 'string', 'max:2'],
            'region' => ['nullable', 'string', 'max:120'],
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $tooth = ($data['tooth'] ?? '') === '' ? null : $data['tooth'];
        $region = ($data['region'] ?? '') === '' ? null : $data['region'];

        try {
            $imaging->upload($actor, $record, $request->file('file'), $data['image_type'], $tooth, $region);
        } catch (DentalException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('dental.imaging', $record->id)->with('status', 'uploaded');
    }

    public function storeReading(Request $request, string $image, DentalImagingService $imaging): RedirectResponse
    {
        Gate::authorize('dental.chart');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'reading' => ['required', 'string', 'max:4000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = DentalImage::query()->whereKey($image)->firstOrFail();

        try {
            $imaging->recordReading($actor, $record, $data['reading'], $data['reason'] ?? null);
        } catch (DentalException $e) {
            return back()->withErrors(['reading' => $e->getMessage()]);
        }

        return redirect()->route('dental.imaging', $record->patient_id)->with('status', 'read');
    }

    /**
     * Stream the raw image bytes from the private disk (no public URL, nosniff). Gated on
     * patient.view and read-logged inside the service.
     */
    public function download(Request $request, string $image, DentalImagingService $imaging): Response
    {
        Gate::authorize('patient.view');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $record = DentalImage::query()->whereKey($image)->firstOrFail();
        $document = $record->document;
        abort_unless($document !== null, 404);

        return response($imaging->fileContents($actor, $record), 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="'.$document->original_filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A dental image + its metadata + the dentist's readings — no analysis/finding/overlay field.
     *
     * @return array<string, mixed>
     */
    private function present(DentalImage $image): array
    {
        return [
            'id' => $image->id,
            'image_type' => $image->image_type,
            'tooth' => $image->tooth,
            'region' => $image->region,
            'captured_at' => $image->captured_at->toIso8601String(),
            'uploaded_by' => $image->uploaded_by,
            'mime_type' => $image->document?->mime_type,
            'original_filename' => $image->document?->original_filename,
            'file_url' => route('dental.imaging.file', $image->id),
            'reading_url' => route('dental.imaging.reading', $image->id),
            'readings' => $image->readings
                ->sortByDesc('read_at')
                ->map(fn (DentalImageReading $r): array => [
                    'id' => $r->id,
                    'reading' => $r->reading,
                    'reason' => $r->reason,
                    'read_by' => $r->read_by,
                    'read_at' => $r->read_at->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
