<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;

class DocumentDownloadController
{
    public function __invoke(string $document, DocumentService $documents): Response
    {
        Gate::authorize('patient.view');

        $record = Document::query()->whereKey($document)->firstOrFail();
        $record->auditRead([
            'surface' => 'document_download',
            'original_filename' => $record->original_filename,
        ]);

        return response($documents->fileContents($record), 200, [
            'Content-Type' => $record->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$record->original_filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
