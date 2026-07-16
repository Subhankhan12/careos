<?php

namespace Modules\Import\Services;

use DateTime;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Import\Exceptions\ImportException;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Models\ImportRow;
use Modules\Patients\Services\PatientMergeService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\User;
use Throwable;

/**
 * Commits a VALIDATED batch. Every patient is created through the REAL
 * PatientService (so MRN generation, tenancy, validation, and audit all apply
 * exactly as normal) — never a raw insert. Idempotent: committing twice does not
 * re-import (guarded on the batch status AND each row's status). Writes one audit
 * event summarizing the import.
 */
class ImportCommitter
{
    public function __construct(
        private readonly PatientService $patients,
        private readonly PatientMergeService $merges,
        private readonly PatientFieldMap $fieldMap,
        private readonly AuditService $audit,
    ) {}

    public function commit(ImportBatch $batch, User $actor, string $policy = ImportBatch::POLICY_SKIP): ImportBatch
    {
        // Idempotency guard #1: an already-committed batch is a no-op.
        if ($batch->status === ImportBatch::STATUS_COMMITTED) {
            return $batch;
        }

        if ($batch->status !== ImportBatch::STATUS_VALIDATED) {
            throw new ImportException('Only a validated batch can be committed.');
        }

        if (! in_array($policy, ImportBatch::POLICIES, true)) {
            throw new ImportException('Unknown duplicate policy.');
        }

        $dateFormat = (string) $batch->date_format;
        $fieldToColumn = [];
        foreach ($batch->mapping ?? [] as $column => $field) {
            $fieldToColumn[$field] ??= $column;
        }

        $counts = ['imported' => 0, 'skipped' => 0, 'invalid' => 0];

        DB::transaction(function () use ($batch, $actor, $policy, $fieldToColumn, $dateFormat, &$counts): void {
            $rows = ImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->orderBy('row_number')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                // Idempotency guard #2: a row already processed is never redone.
                if (in_array($row->status, [ImportRow::STATUS_IMPORTED, ImportRow::STATUS_SKIPPED], true)) {
                    $row->status === ImportRow::STATUS_IMPORTED ? $counts['imported']++ : $counts['skipped']++;

                    continue;
                }

                if ($row->status === ImportRow::STATUS_INVALID) {
                    $counts['invalid']++;

                    continue; // invalid rows are NEVER imported
                }

                if ($row->status === ImportRow::STATUS_DUPLICATE && $policy === ImportBatch::POLICY_SKIP) {
                    $row->forceFill(['status' => ImportRow::STATUS_SKIPPED])->save();
                    $counts['skipped']++;

                    continue;
                }

                try {
                    $entityId = $this->importRow($row, $actor, $policy, $fieldToColumn, $dateFormat);
                    $row->forceFill([
                        'status' => ImportRow::STATUS_IMPORTED,
                        'created_entity_id' => $entityId,
                    ])->save();
                    $counts['imported']++;
                } catch (Throwable $e) {
                    // A row that fails at commit is recorded, not fatal to the batch.
                    $row->forceFill([
                        'status' => ImportRow::STATUS_INVALID,
                        'errors' => ['commit' => $e->getMessage()],
                    ])->save();
                    $counts['invalid']++;
                }
            }
        });

        $summary = $batch->summary ?? [];
        $summary['commit'] = [
            'policy' => $policy,
            'counts' => $counts,
            'committed_at' => now()->toIso8601String(),
        ];

        $batch->forceFill([
            'status' => ImportBatch::STATUS_COMMITTED,
            'duplicate_policy' => $policy,
            'committed_at' => now(),
            'summary' => $summary,
        ])->save();

        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => 'patient.import.committed',
            'resource_type' => 'import_batch',
            'resource_id' => $batch->id,
            'context' => [
                'type' => $batch->type,
                'original_filename' => $batch->original_filename,
                'row_count' => $batch->row_count,
                'duplicate_policy' => $policy,
                'imported' => $counts['imported'],
                'skipped' => $counts['skipped'],
                'invalid' => $counts['invalid'],
            ],
        ]);

        return $batch->refresh();
    }

    /**
     * @param  array<string, string>  $fieldToColumn
     * @return string the surviving patient id (created, or the merge target)
     */
    private function importRow(ImportRow $row, User $actor, string $policy, array $fieldToColumn, string $dateFormat): string
    {
        $values = [];
        foreach ($fieldToColumn as $field => $column) {
            $values[$field] = (string) ($row->raw[$column] ?? '');
        }
        $values['date_of_birth'] = $this->normalizeDate((string) ($values['date_of_birth'] ?? ''), $dateFormat);

        $shaped = $this->fieldMap->build($values);

        $patient = $this->patients->create(
            $shaped['patient'],
            $shaped['contacts'],
            $shaped['identifiers'],
            $shaped['coverages'],
        );

        // Merge policy on a flagged duplicate: import the new record then merge it
        // into the matched existing patient through the EXISTING audited merge path,
        // so the survivor is the existing patient and child rows move onto it.
        if ($policy === ImportBatch::POLICY_MERGE
            && $row->status === ImportRow::STATUS_DUPLICATE
            && $row->matched_patient_id !== null) {
            $this->merges->merge($patient->id, $row->matched_patient_id, 'CSV import merge (batch '.$row->import_batch_id.')');

            return $row->matched_patient_id;
        }

        return $patient->id;
    }

    private function normalizeDate(string $value, string $format): string
    {
        $dt = DateTime::createFromFormat('!'.$format, $value);

        return $dt !== false ? $dt->format('Y-m-d') : $value;
    }
}
