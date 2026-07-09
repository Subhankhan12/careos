<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;
use Modules\Patients\Models\PortalAccount;

class PortalDocumentController
{
    public function index(Request $request, DocumentService $documents): JsonResponse
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        return response()->json([
            'documents' => $documents->sharedForPortal($account)
                ->map(fn (Document $document): array => $this->summary($document))
                ->values()
                ->all(),
        ]);
    }

    public function show(string $document, Request $request, DocumentService $documents): Response
    {
        $account = $request->user('patient');
        abort_unless($account instanceof PortalAccount, 401);

        $record = $documents->portalDocument($document, $account);
        $record->auditRead([
            'surface' => 'portal_document_download',
            'original_filename' => $record->original_filename,
        ]);

        return response($documents->fileContents($record), 200, [
            'Content-Type' => $record->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$record->original_filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Document $document): array
    {
        return [
            'id' => $document->id,
            'category' => $document->category,
            'title' => $document->title,
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'uploaded_at' => $document->uploaded_at->toDateTimeString(),
            'shared_at' => $document->shared_at?->toDateTimeString(),
        ];
    }
}
