<?php

namespace Modules\Clinical\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Clinical\Events\DocumentChanged;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Models\Encounter;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use RuntimeException;

class DocumentService
{
    private const MAX_SIZE_BYTES = 10_485_760;

    /**
     * @var array<string, string>
     */
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'text/plain' => 'txt',
    ];

    public function __construct(
        private readonly ConsentService $consents,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array{category: string, title: string, encounter_id?: string|null}  $data
     */
    public function upload(Patient $patient, User $actor, UploadedFile $file, array $data): Document
    {
        $this->authorizeWrite($actor);
        $this->assertPatientInTenant($patient);

        $category = (string) $data['category'];
        if (! in_array($category, Document::categories(), true)) {
            throw new InvalidArgumentException('Unsupported document category.');
        }

        $encounter = null;
        if (! empty($data['encounter_id'])) {
            $encounter = Encounter::query()->whereKey((string) $data['encounter_id'])->first();
            if (! $encounter instanceof Encounter || $encounter->patient_id !== $patient->id) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $data['encounter_id']);
            }
        }

        $mimeType = (string) $file->getMimeType();
        $this->assertAllowedUpload($file, $mimeType);

        $path = $this->storagePath($patient, self::MIME_EXTENSIONS[$mimeType]);
        $contents = file_get_contents((string) $file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('Unable to read uploaded document.');
        }

        Storage::disk('local')->put($path, $contents);

        $document = Document::query()->create([
            'patient_id' => $patient->id,
            'encounter_id' => $encounter?->id,
            'category' => $category,
            'title' => trim((string) $data['title']),
            'original_filename' => $this->sanitizeFilename($file->getClientOriginalName()),
            'storage_path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $actor->id,
            'uploaded_at' => now(),
        ]);

        Event::dispatch(new DocumentChanged($document, $actor, 'document.uploaded', [
            'category' => $document->category,
            'title' => $document->title,
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
        ]));

        return $document->refresh();
    }

    public function shareWithPatient(Document $document, User $actor): Document
    {
        $this->authorizeWrite($actor);
        $patient = $this->patientFor($document);

        if (! $this->consents->has($patient, 'portal.access')) {
            throw new AuthorizationException('Portal access consent is required to share documents.');
        }

        $document->forceFill([
            'shared_with_patient' => true,
            'shared_at' => now(),
        ])->save();

        Event::dispatch(new DocumentChanged($document->refresh(), $actor, 'document.shared', [
            'shared_with_patient' => true,
        ]));

        return $document;
    }

    public function unshareFromPatient(Document $document, User $actor): Document
    {
        $this->authorizeWrite($actor);
        $this->assertDocumentInTenant($document);

        $document->forceFill([
            'shared_with_patient' => false,
            'shared_at' => null,
        ])->save();

        Event::dispatch(new DocumentChanged($document->refresh(), $actor, 'document.unshared', [
            'shared_with_patient' => false,
        ]));

        return $document;
    }

    public function delete(Document $document, User $actor): void
    {
        $this->authorizeWrite($actor);
        $this->assertDocumentInTenant($document);

        Event::dispatch(new DocumentChanged($document, $actor, 'document.deleted', [
            'storage_path' => $document->storage_path,
            'soft_delete' => true,
        ]));

        $document->delete();
    }

    /**
     * @return Collection<int, Document>
     */
    public function sharedForPortal(PortalAccount $account): Collection
    {
        $this->assertPortalAccountInTenant($account);

        return Document::query()
            ->where('patient_id', $account->patient_id)
            ->where('shared_with_patient', true)
            ->orderByDesc('uploaded_at')
            ->get();
    }

    public function portalDocument(string $documentId, PortalAccount $account): Document
    {
        $this->assertPortalAccountInTenant($account);

        return Document::query()
            ->whereKey($documentId)
            ->where('patient_id', $account->patient_id)
            ->where('shared_with_patient', true)
            ->firstOrFail();
    }

    public function fileContents(Document $document): string
    {
        $this->assertDocumentInTenant($document);

        $contents = Storage::disk('local')->get($document->storage_path);

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read stored document.');
        }

        return $contents;
    }

    private function authorizeWrite(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('note.write')) {
            throw new AuthorizationException('This user cannot manage clinical documents.');
        }
    }

    private function assertAllowedUpload(UploadedFile $file, string $mimeType): void
    {
        if (! array_key_exists($mimeType, self::MIME_EXTENSIONS)) {
            throw new InvalidArgumentException('Unsupported document MIME type.');
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new InvalidArgumentException('Document exceeds the maximum allowed size.');
        }
    }

    private function storagePath(Patient $patient, string $extension): string
    {
        return sprintf(
            'tenants/%s/clinical-documents/%s/%s.%s',
            $this->tenants->id(),
            $patient->id,
            (string) Str::ulid(),
            $extension,
        );
    }

    private function sanitizeFilename(string $filename): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($filename)) ?? '';
        $sanitized = trim($sanitized, " .\t\n\r\0\x0B");

        if ($sanitized === '') {
            return 'document';
        }

        return Str::limit($sanitized, 180, '');
    }

    private function assertPatientInTenant(Patient $patient): void
    {
        if ($patient->tenant_id !== $this->tenants->id()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', $patient->id);
        }
    }

    private function assertDocumentInTenant(Document $document): void
    {
        if ($document->tenant_id !== $this->tenants->id()) {
            throw CrossTenantReferenceException::forAttribute('document_id', $document->id);
        }
    }

    private function assertPortalAccountInTenant(PortalAccount $account): void
    {
        if ($account->tenant_id !== $this->tenants->id()) {
            throw CrossTenantReferenceException::forAttribute('portal_account_id', $account->id);
        }
    }

    private function patientFor(Document $document): Patient
    {
        $this->assertDocumentInTenant($document);

        return Patient::query()->whereKey($document->patient_id)->firstOrFail();
    }
}
