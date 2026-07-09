<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

class DocumentUploadController
{
    public function __invoke(string $patient, Request $request, DocumentService $documents): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        /** @var array{category: string, title: string, encounter_id?: string|null, file: UploadedFile} $data */
        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', Document::categories())],
            'title' => ['required', 'string', 'max:255'],
            'encounter_id' => ['nullable', 'string'],
            'file' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,text/plain', 'max:10240'],
        ]);

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $document = $documents->upload($record, $actor, $data['file'], $data);

        return response()->json(['document' => $this->summary($document)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Document $document): array
    {
        return [
            'id' => $document->id,
            'patient_id' => $document->patient_id,
            'encounter_id' => $document->encounter_id,
            'category' => $document->category,
            'title' => $document->title,
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'shared_with_patient' => $document->shared_with_patient,
            'shared_at' => $document->shared_at?->toDateTimeString(),
        ];
    }
}
