<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;
use Modules\Platform\Models\User;

class DocumentShareController
{
    public function share(string $document, Request $request, DocumentService $documents): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Document::query()->whereKey($document)->firstOrFail();
        $updated = $documents->shareWithPatient($record, $actor);

        return response()->json(['document' => $this->summary($updated)]);
    }

    public function unshare(string $document, Request $request, DocumentService $documents): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Document::query()->whereKey($document)->firstOrFail();
        $updated = $documents->unshareFromPatient($record, $actor);

        return response()->json(['document' => $this->summary($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Document $document): array
    {
        return [
            'id' => $document->id,
            'shared_with_patient' => $document->shared_with_patient,
            'shared_at' => $document->shared_at?->toDateTimeString(),
        ];
    }
}
