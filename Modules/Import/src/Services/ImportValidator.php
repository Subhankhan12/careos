<?php

namespace Modules\Import\Services;

use DateTime;
use Illuminate\Support\Facades\DB;
use Modules\Import\Exceptions\ImportException;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Models\ImportRow;
use Modules\Patients\Services\DuplicateCandidate;
use Modules\Patients\Services\DuplicateDetector;

/**
 * The mandatory dry-run engine. For every row it parses/validates the mapped
 * fields (dates via the batch's explicit format), runs the EXISTING
 * DuplicateDetector against the mapped demographics, and records a per-row status
 * plus a batch summary. It WRITES NOTHING to patients — it only updates the
 * import_rows/import_batches bookkeeping tables.
 */
class ImportValidator
{
    /**
     * A top duplicate candidate at or above this score is treated as a likely
     * match (medium/high confidence — 'low' is score < 50).
     */
    public const DUPLICATE_SCORE_THRESHOLD = 50;

    public function __construct(
        private readonly DuplicateDetector $duplicates,
        private readonly PatientFieldMap $fieldMap,
    ) {}

    public function validate(ImportBatch $batch): ImportBatch
    {
        if ($batch->status === ImportBatch::STATUS_COMMITTED) {
            throw new ImportException('A committed batch cannot be re-validated.');
        }

        $mapping = $batch->mapping ?? [];
        $this->assertRequiredFieldsMapped($mapping);

        $dateFormat = (string) $batch->date_format;
        if (trim($dateFormat) === '') {
            throw new ImportException('A date format must be selected before validation.');
        }

        // field key => CSV header (first column wins if two map to one field).
        $fieldToColumn = [];
        foreach ($mapping as $column => $field) {
            $fieldToColumn[$field] ??= $column;
        }

        $counts = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicate' => 0];
        $headers = [];

        DB::transaction(function () use ($batch, $fieldToColumn, $dateFormat, &$counts, &$headers): void {
            $rows = ImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->orderBy('row_number')
                ->get();

            foreach ($rows as $row) {
                $raw = $row->raw;
                $headers = $headers === [] ? array_keys($raw) : $headers;

                $values = [];
                foreach ($fieldToColumn as $field => $column) {
                    $values[$field] = (string) ($raw[$column] ?? '');
                }

                [$errors, $normalized] = $this->validateValues($values, $dateFormat);

                if ($errors !== []) {
                    $row->forceFill([
                        'status' => ImportRow::STATUS_INVALID,
                        'errors' => $errors,
                        'matched_patient_id' => null,
                        'match' => null,
                    ])->save();
                    $counts['total']++;
                    $counts['invalid']++;

                    continue;
                }

                $shaped = $this->fieldMap->build($normalized);
                $candidate = $this->topDuplicate($shaped['demographics']);

                if ($candidate !== null && $candidate->score >= self::DUPLICATE_SCORE_THRESHOLD) {
                    $row->forceFill([
                        'status' => ImportRow::STATUS_DUPLICATE,
                        'errors' => null,
                        'matched_patient_id' => $candidate->patient->id,
                        'match' => [
                            'score' => $candidate->score,
                            'confidence' => $candidate->confidence,
                            'reasons' => $candidate->reasons,
                        ],
                    ])->save();
                    $counts['total']++;
                    $counts['duplicate']++;

                    continue;
                }

                $row->forceFill([
                    'status' => ImportRow::STATUS_VALID,
                    'errors' => null,
                    'matched_patient_id' => null,
                    'match' => null,
                ])->save();
                $counts['total']++;
                $counts['valid']++;
            }
        });

        $ignored = array_values(array_diff($headers, array_keys($mapping)));

        $batch->forceFill([
            'status' => ImportBatch::STATUS_VALIDATED,
            'summary' => [
                'counts' => $counts,
                'ignored_columns' => $ignored,
                'date_format' => $dateFormat,
                'generated_at' => now()->toIso8601String(),
            ],
        ])->save();

        return $batch->refresh();
    }

    /**
     * @param  array<string, string>  $mapping
     */
    private function assertRequiredFieldsMapped(array $mapping): void
    {
        $mappedFields = array_values($mapping);
        $missing = array_diff(PatientFieldMap::requiredFields(), $mappedFields);

        if ($missing !== []) {
            throw new ImportException('Required fields are not mapped: '.implode(', ', $missing));
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function validateValues(array $values, string $dateFormat): array
    {
        $errors = [];
        $normalized = $values;

        foreach (['first_name', 'last_name'] as $field) {
            if (trim((string) ($values[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }

        $dob = trim((string) ($values['date_of_birth'] ?? ''));
        if ($dob === '') {
            $errors['date_of_birth'] = 'This field is required.';
        } else {
            $parsed = $this->parseDate($dob, $dateFormat);
            if ($parsed === null) {
                $errors['date_of_birth'] = 'Could not parse date "'.$dob.'" with format "'.$dateFormat.'".';
            } else {
                $normalized['date_of_birth'] = $parsed;
            }
        }

        return [$errors, $normalized];
    }

    private function parseDate(string $value, string $format): ?string
    {
        $dt = DateTime::createFromFormat('!'.$format, $value);
        $errors = DateTime::getLastErrors();

        if ($dt === false) {
            return null;
        }

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $dt->format('Y-m-d');
    }

    /**
     * @param  array<string, mixed>  $demographics
     */
    private function topDuplicate(array $demographics): ?DuplicateCandidate
    {
        return $this->duplicates->findForDemographics($demographics)
            ->filter(fn (DuplicateCandidate $candidate): bool => $candidate->score > 0)
            ->first();
    }
}
