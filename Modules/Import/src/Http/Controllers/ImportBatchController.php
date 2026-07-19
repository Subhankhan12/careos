<?php

namespace Modules\Import\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Import\Exceptions\ImportException;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Models\ImportRow;
use Modules\Import\Services\ImportBatchService;
use Modules\Import\Services\ImportCommitter;
use Modules\Import\Services\ImportValidator;
use Modules\Import\Services\PatientFieldMap;

class ImportBatchController
{
    private const DATE_FORMATS = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'd-m-Y'];

    public function __construct(private readonly PatientFieldMap $fieldMap) {}

    public function index(): Response
    {
        Gate::authorize('data.import');

        $batches = ImportBatch::query()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ImportBatch $batch): array => $this->batchSummary($batch))
            ->all();

        return Inertia::render('Import/Index', [
            'batches' => $batches,
            'createUrl' => route('import.create'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('data.import');

        return Inertia::render('Import/Upload', [
            'batch' => null,
            'fieldCatalog' => $this->fieldMap->catalog(),
            'dateFormats' => self::DATE_FORMATS,
            'storeUrl' => route('import.store'),
        ]);
    }

    public function store(Request $request, ImportBatchService $service): RedirectResponse
    {
        Gate::authorize('data.import');

        $request->validate(['file' => ['required', 'file', 'max:5120']]);

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return back()->withErrors(['file' => 'A CSV file is required.']);
        }

        try {
            $batch = $service->upload($file, $request->user());
        } catch (ImportException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('import.show', $batch->id);
    }

    public function show(string $batch): Response
    {
        Gate::authorize('data.import');
        // Resolve the tenant-scoped batch INSIDE the action (not via implicit route-model
        // binding, which resolves before IdentifyTenantFromUser establishes the tenant
        // context). The BelongsToTenant scope makes a missing/cross-tenant id 404, never 500.
        $batch = ImportBatch::query()->whereKey($batch)->firstOrFail();

        return Inertia::render('Import/Upload', [
            'batch' => $this->batchDetail($batch),
            'fieldCatalog' => $this->fieldMap->catalog(),
            'dateFormats' => self::DATE_FORMATS,
            'storeUrl' => route('import.store'),
        ]);
    }

    public function mapping(Request $request, ImportBatchService $service, string $batch): RedirectResponse
    {
        Gate::authorize('data.import');
        $batch = ImportBatch::query()->whereKey($batch)->firstOrFail();

        $data = $request->validate([
            'mapping' => ['array'],
            'date_format' => ['required', 'string', 'max:32'],
        ]);

        try {
            $service->setMapping($batch, $data['mapping'] ?? [], $data['date_format']);
        } catch (ImportException $e) {
            return back()->withErrors(['mapping' => $e->getMessage()]);
        }

        return redirect()->route('import.show', $batch->id);
    }

    public function validateBatch(ImportValidator $validator, string $batch): RedirectResponse
    {
        Gate::authorize('data.import');
        $batch = ImportBatch::query()->whereKey($batch)->firstOrFail();

        try {
            $validator->validate($batch);
        } catch (ImportException $e) {
            return back()->withErrors(['validation' => $e->getMessage()]);
        }

        return redirect()->route('import.show', $batch->id);
    }

    public function commit(Request $request, ImportCommitter $committer, string $batch): RedirectResponse
    {
        Gate::authorize('data.import');
        $batch = ImportBatch::query()->whereKey($batch)->firstOrFail();

        $data = $request->validate([
            'duplicate_policy' => ['required', 'string', 'in:'.implode(',', ImportBatch::POLICIES)],
        ]);

        try {
            $committer->commit($batch, $request->user(), $data['duplicate_policy']);
        } catch (ImportException $e) {
            return back()->withErrors(['commit' => $e->getMessage()]);
        }

        return redirect()->route('import.show', $batch->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function batchSummary(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'type' => $batch->type,
            'original_filename' => $batch->original_filename,
            'status' => $batch->status,
            'row_count' => $batch->row_count,
            'summary' => $batch->summary,
            'committed_at' => $batch->committed_at?->toIso8601String(),
            'created_at' => $batch->created_at?->toIso8601String(),
            'show_url' => route('import.show', $batch->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batchDetail(ImportBatch $batch): array
    {
        $rows = ImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->orderBy('row_number')
            ->limit(500)
            ->get();

        $headers = $rows->isNotEmpty() ? array_keys($rows->first()->raw) : [];

        return [
            'id' => $batch->id,
            'type' => $batch->type,
            'original_filename' => $batch->original_filename,
            'status' => $batch->status,
            'row_count' => $batch->row_count,
            'headers' => $headers,
            'mapping' => $batch->mapping ?? new \stdClass,
            'date_format' => $batch->date_format,
            'duplicate_policy' => $batch->duplicate_policy,
            'summary' => $batch->summary,
            'committed_at' => $batch->committed_at?->toIso8601String(),
            'policies' => ImportBatch::POLICIES,
            'rows' => $rows->map(fn (ImportRow $row): array => [
                'row_number' => $row->row_number,
                'status' => $row->status,
                'errors' => $row->errors,
                'matched_patient_id' => $row->matched_patient_id,
                'match' => $row->match,
                'raw' => $row->raw,
            ])->all(),
            'urls' => [
                'mapping' => route('import.mapping', $batch->id),
                'validate' => route('import.validate', $batch->id),
                'commit' => route('import.commit', $batch->id),
            ],
        ];
    }
}
