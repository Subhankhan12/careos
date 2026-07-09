<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;
use Modules\Platform\Models\User;

class DocumentDeleteController
{
    public function __invoke(string $document, Request $request, DocumentService $documents): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $record = Document::query()->whereKey($document)->firstOrFail();
        $documents->delete($record, $actor);

        return response()->json(['deleted' => true]);
    }
}
