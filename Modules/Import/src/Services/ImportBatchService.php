<?php

namespace Modules\Import\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Import\Exceptions\ImportException;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Models\ImportRow;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Uploads and parses a CSV into a tenant-owned batch of raw rows, and persists
 * the column mapping. Writes NOTHING to patients — that is ImportCommitter's job,
 * and only after a dry-run.
 */
class ImportBatchService
{
    private const MAX_SIZE_BYTES = 5_242_880; // 5 MB

    private const ALLOWED_MIME = [
        'text/csv', 'text/plain', 'application/csv',
        'application/vnd.ms-excel', 'application/octet-stream',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CsvReader $reader,
    ) {}

    public function upload(UploadedFile $file, User $actor, string $type = ImportBatch::TYPE_PATIENTS): ImportBatch
    {
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new ImportException('The CSV file exceeds the maximum size.');
        }

        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($mime, self::ALLOWED_MIME, true) && ! in_array($extension, ['csv', 'txt'], true)) {
            throw new ImportException('Only CSV files may be imported.');
        }

        $content = file_get_contents((string) $file->getRealPath());
        if ($content === false) {
            throw new ImportException('The uploaded file could not be read.');
        }

        // Parse first (throws ImportException on malformed input) so nothing is
        // stored for a file we cannot read.
        $parsed = $this->reader->parse($content);

        $path = sprintf('tenants/%s/imports/%s.csv', $this->tenants->id(), (string) Str::ulid());
        Storage::disk('local')->put($path, $content);

        return DB::transaction(function () use ($file, $actor, $type, $parsed, $path): ImportBatch {
            $batch = ImportBatch::query()->create([
                'type' => $type,
                'original_filename' => $this->sanitizeFilename($file->getClientOriginalName()),
                'storage_path' => $path,
                'status' => ImportBatch::STATUS_UPLOADED,
                'row_count' => count($parsed['rows']),
                'created_by' => (string) $actor->id,
            ]);

            foreach ($parsed['rows'] as $index => $raw) {
                ImportRow::query()->create([
                    'import_batch_id' => $batch->id,
                    'row_number' => $index + 1,
                    'raw' => $raw,
                    'status' => ImportRow::STATUS_PENDING,
                ]);
            }

            return $batch->refresh();
        });
    }

    /**
     * @param  array<string, string>  $mapping  CSV header => CareOS field key
     */
    public function setMapping(ImportBatch $batch, array $mapping, string $dateFormat): ImportBatch
    {
        $fieldKeys = PatientFieldMap::fieldKeys();
        $clean = [];
        foreach ($mapping as $column => $field) {
            $field = (string) $field;
            if ($field === '' || ! in_array($field, $fieldKeys, true)) {
                continue; // unmapped or unknown target — ignored (recorded by omission)
            }
            $clean[(string) $column] = $field;
        }

        if (trim($dateFormat) === '') {
            throw new ImportException('A date format must be selected.');
        }

        $batch->forceFill([
            'mapping' => $clean,
            'date_format' => trim($dateFormat),
            'status' => ImportBatch::STATUS_MAPPED,
        ])->save();

        return $batch->refresh();
    }

    private function sanitizeFilename(string $filename): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'import.csv';

        return Str::limit($base, 180, '');
    }
}
