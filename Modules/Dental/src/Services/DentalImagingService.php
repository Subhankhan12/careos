<?php

namespace Modules\Dental\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Models\DentalImage;
use Modules\Dental\Models\DentalImageReading;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * The dental imaging service (DENTAL.G8) — upload, read-back, and dentist-authored readings. It
 * REUSES the existing clinical {@see DocumentService} for storage (private disk, tenant-prefixed
 * path, MIME/size validation, no public URL) and adds only dental metadata + the dentist's reading.
 *
 * ELECTRIC FENCE (imaging's risk): this service NEVER analyses an image. There is no CV/AI, no
 * caries/pathology detection, no auto-annotation, no overlay, no computed "finding" — not a single
 * method looks at the pixels. `recordReading()` stores the DENTIST'S written interpretation; the
 * system generates nothing.
 *
 * Everything is tenant-scoped and fail-closed. Upload/reading are `dental.chart`-gated (the file
 * store additionally enforces the document-write permission); reads are `patient.view`-gated and
 * patient-scoped read-logged.
 */
class DentalImagingService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
        private readonly DocumentService $documents,
    ) {}

    /**
     * Upload a dental image. The file is stored through the EXISTING DocumentService (private,
     * tenant-prefixed, validated, no public URL); this attaches the dental metadata over it.
     */
    public function upload(
        User $actor,
        Patient $patient,
        UploadedFile $file,
        string $imageType,
        ?string $tooth = null,
        ?string $region = null,
    ): DentalImage {
        Gate::forUser($actor)->authorize('dental.chart');
        $this->assertActorTenant($actor);
        $this->assertPatientTenant($patient);

        if (! in_array($imageType, DentalImage::TYPES, true)) {
            throw new DentalException("Invalid dental image type [{$imageType}].");
        }

        // Reuse the tested clinical storage path — no new file storage. Category = image.
        $document = $this->documents->upload($patient, $actor, $file, [
            'category' => Document::CATEGORY_IMAGE,
            'title' => 'Dental image ('.$imageType.')',
        ]);

        $image = DentalImage::query()->create([
            'patient_id' => $patient->id,
            'document_id' => $document->id,
            'image_type' => $imageType,
            'tooth' => $tooth,
            'region' => $region,
            'captured_at' => Carbon::now(),
            'uploaded_by' => $actor->id,
        ]);

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'dental.image_uploaded',
            'resource_type' => 'dental_images',
            'resource_id' => $image->id,
            'patient_id' => (string) $patient->id,
            'context' => ['image_type' => $imageType, 'tooth' => $tooth],
        ]);

        return $image->load('readings');
    }

    /**
     * Record the DENTIST'S reading of an image (append-only). `$reading` is their own written
     * interpretation — the system stores it, it never generates it.
     */
    public function recordReading(User $actor, DentalImage $image, string $reading, ?string $reason = null): DentalImageReading
    {
        Gate::forUser($actor)->authorize('dental.chart');
        $this->assertActorTenant($actor);
        $this->assertImageTenant($image);

        $record = DentalImageReading::query()->create([
            'dental_image_id' => $image->id,
            'patient_id' => $image->patient_id,
            'reading' => $reading,
            'reason' => $reason,
            'read_by' => $actor->id,
            'read_at' => Carbon::now(),
        ]);

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'dental.image_read',
            'resource_type' => 'dental_image_readings',
            'resource_id' => $record->id,
            'patient_id' => (string) $image->patient_id,
            'context' => ['dental_image_id' => $image->id, 'is_correction' => $reason !== null],
        ]);

        return $record;
    }

    /**
     * A patient's dental images (each with its stored asset + the dentist's readings), newest first.
     *
     * @return Collection<int, DentalImage>
     */
    public function imagesFor(User $actor, Patient $patient): Collection
    {
        Gate::forUser($actor)->authorize('patient.view');
        $this->assertActorTenant($actor);
        $this->assertPatientTenant($patient);

        // Viewing dental images discloses clinical data → patient-scoped read log.
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'read',
            'resource_type' => 'dental_images',
            'resource_id' => (string) $patient->id,
            'patient_id' => (string) $patient->id,
            'context' => ['scope' => 'dental_imaging'],
        ]);

        return DentalImage::query()
            ->where('patient_id', $patient->id)
            ->with(['document', 'readings'])
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The raw image bytes for the viewer — read back from the private disk through the existing
     * DocumentService (there is no public URL). Gated on `patient.view` and read-logged.
     */
    public function fileContents(User $actor, DentalImage $image): string
    {
        Gate::forUser($actor)->authorize('patient.view');
        $this->assertActorTenant($actor);
        $this->assertImageTenant($image);

        $document = Document::query()->whereKey($image->document_id)->firstOrFail();
        $document->auditRead(['surface' => 'dental_image_download', 'dental_image_id' => $image->id]);

        return $this->documents->fileContents($document);
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('actor_id', (string) $actor->id);
        }
    }

    private function assertPatientTenant(Patient $patient): void
    {
        if ($patient->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $patient->id);
        }
    }

    private function assertImageTenant(DentalImage $image): void
    {
        if ($image->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('dental_image_id', (string) $image->id);
        }
    }
}
