<?php

namespace App\Http\Controllers;

use App\Services\GovernanceLedgerExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * GOV.P5 — download the governance ledger for a window.
 *
 * Gated on **`audit.export`**, deliberately narrower than the `audit.view` that opens the dashboard:
 * three roles may read the log on screen, one may take it out of the building as a file. Same
 * reasoning as `billing.escalate` (ARDETAIL.P6).
 *
 * The response is a ZIP of two files — the CSV and its manifest — because the manifest is NOT
 * optional (property (b)): shipping them together means a recipient always has the thing that lets
 * them detect truncation, and cannot accidentally be handed a payload alone.
 *
 * The free-text opt-in is a SECOND gate on top: it needs `audit.export` to be here at all, and it is
 * refused unless explicitly requested. It is never pre-selected in the UI — a tick that is already
 * ticked is not a decision (D-176).
 */
class GovernanceLedgerExportController
{
    public function __invoke(Request $request, GovernanceLedgerExporter $exporter, AuditService $audit): Response
    {
        Gate::authorize('audit.export');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'outcome' => ['nullable', 'string', 'max:40'],
            'include_free_text' => ['sometimes', 'boolean'],
        ]);

        $wantsFreeText = (bool) ($data['include_free_text'] ?? false);

        /*
         * THE OPT-IN IS ITS OWN DECISION, not a consequence of being allowed to export. A request
         * for free text from someone who may export but has no business taking patient-adjacent
         * prose out is refused outright rather than silently downgraded — a silent downgrade would
         * hand back a file that looks like what was asked for and is not.
         */
        if ($wantsFreeText && ! Gate::forUser($actor)->allows('admin.manage')) {
            abort(403, 'Exporting free-text fields requires tenant administration.');
        }

        $export = $exporter->export(
            $actor,
            Carbon::parse($data['from']),
            Carbon::parse($data['to']),
            $wantsFreeText ? [GovernanceLedgerExporter::OPT_IN_FREE_TEXT] : [],
            $data['outcome'] ?? null,
        );

        /*
         * SELF-AUDIT — one row, on the EXISTING audit path, recording who exported what. It is
         * written after the snapshot, which the manifest states rather than leaving the recipient to
         * wonder why the export is not in its own export.
         */
        $audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->getKey(),
            'action' => 'governance.ledger_exported',
            'resource_type' => 'ai_interaction_ledger',
            'context' => [
                'from' => $export['manifest']['window']['from'],
                'to' => $export['manifest']['window']['to'],
                'outcome' => $data['outcome'] ?? null,
                'row_count' => $export['rowCount'],
                'opt_ins' => $export['manifest']['opt_ins'],
                'payload_sha256' => $export['manifest']['payload_sha256'],
            ],
        ]);

        return $this->zip($export);
    }

    /**
     * @param  array{payload: string, manifest: array<string, mixed>, rowCount: int, filename: string}  $export
     */
    private function zip(array $export): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'gov-ledger-');
        $archive = new \ZipArchive;
        $archive->open($path, \ZipArchive::OVERWRITE);
        $archive->addFromString($export['filename'], $export['payload']);
        $archive->addFromString(
            'manifest.json',
            (string) json_encode($export['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $archive->close();

        return response()
            ->download($path, str_replace('.csv', '.zip', $export['filename']), [
                'Content-Type' => 'application/zip',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend(true);
    }
}
