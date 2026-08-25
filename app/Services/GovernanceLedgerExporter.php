<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\AiCore\Models\AiInteraction;
use Modules\AiCore\Services\AgentRegistry;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\User;

/**
 * GOV.P5 — the governance-ledger export.
 *
 * An auditor or regulator asks for the record of what the agents did. This produces that file, and
 * the whole design is about what must NOT go in it and how the recipient can tell the file is whole.
 *
 * ── THE COLUMN-BY-COLUMN CLASSIFICATION (the gate's core decision) ──────────────────────────────
 *
 * Every column of `ai_interactions`, classified. **The rule when a column's PHI status is unclear is
 * OUT**, because an export leaves the building and cannot be recalled.
 *
 *  IN by default — governance facts, no patient content:
 *    id             the ledger row's own identifier
 *    occurred_at    when it happened
 *    agent          which canonical agent (resolved through AgentRegistry to a stable name)
 *    feature        which governed capability
 *    outcome        proposed / approved / executed / rejected / fence_refused
 *    tool           the tool key, read from tool_calls[0] — a registry key, never free text
 *    provider·model·model_version   which engine produced it
 *    prompt_hash    a hash, not a prompt
 *    input_tokens·output_tokens·cost_minor·latency_ms   counters
 *    approver_id    WHICH STAFF MEMBER approved — a staff id, not a patient's. This is the point of
 *                   the export: an accountability record with no actor is not an audit trail.
 *    output_ref     a pointer to the agent_action, an id — no content travels with it
 *
 *  OUT by default — free text a caller controls, so its contents cannot be guaranteed:
 *    metadata       caller-supplied JSON. Today it holds a `why` sentence and a `reason`; the demo's
 *                   own rows carry staff prose, and nothing stops a future caller putting a patient's
 *                   words there. **Only reachable through the explicit opt-in.**
 *    error_message  provider errors, which have every chance of echoing the payload that failed.
 *                   **Opt-in only.**
 *    tool_calls     the raw JSON; only the tool KEY is lifted out of it. The rest is argument data.
 *
 *  NEVER, under any opt-in:
 *    tenant_id      an internal identifier of no value to a recipient who already knows the tenant
 *    label          a constant
 *    created_at·updated_at   row bookkeeping; `occurred_at` is the fact
 *
 * `audit_events` is NOT exported row-by-row. It carries `patient_id` on most rows and a free-text
 * `reason` that in this very demo reads *"Patient ist erkrankt"* and *"Weight was measured during the
 * consultation"* — clinical content, in a column no filter could sanitise reliably. What the export
 * takes from it is its INTEGRITY STATE, in the manifest: the chain's verdict and head at the moment
 * of export. That gives the recipient the guarantee without the content.
 *
 * ── INTEGRITY ───────────────────────────────────────────────────────────────────────────────────
 *
 * Every export emits a MANIFEST — there is no option to omit it. It records the window, the filters,
 * the row count, the actor, the moment, which opt-ins were used, a SHA-256 of the exact payload
 * bytes, and the audit chain's state. Re-hash the file and compare: a truncated or altered payload
 * no longer matches, so removing rows is detectable rather than invisible.
 *
 * ── NO MUTATION ─────────────────────────────────────────────────────────────────────────────────
 *
 * Reading for export is a read. Nothing here writes to `ai_interactions` (which refuses UPDATE and
 * DELETE at the database anyway) and nothing touches the chain. The single write is the export's own
 * audit row, on the existing path — see {@see GovernanceLedgerExportController}.
 */
class GovernanceLedgerExporter
{
    /** The governance columns, in the order they appear in the file. */
    public const DEFAULT_COLUMNS = [
        'id',
        'occurred_at',
        'agent',
        'agent_name',
        'feature',
        'tool',
        'outcome',
        'provider',
        'model',
        'model_version',
        'prompt_hash',
        'input_tokens',
        'output_tokens',
        'cost_minor',
        'latency_ms',
        'approver_id',
        'output_ref',
    ];

    /** Free-text columns, reachable ONLY through the explicit, separately-gated opt-in. */
    public const OPT_IN_COLUMNS = [
        'metadata',
        'error_message',
    ];

    /** The opt-in's own key, so the UI, the audit row and the manifest all name it the same way. */
    public const OPT_IN_FREE_TEXT = 'free_text';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Build the export for a window.
     *
     * @param  list<string>  $optIns
     * @return array{payload: string, manifest: array<string, mixed>, rowCount: int, filename: string}
     */
    public function export(
        User $actor,
        CarbonInterface $from,
        CarbonInterface $to,
        array $optIns = [],
        ?string $outcome = null,
    ): array {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();

        $includeFreeText = in_array(self::OPT_IN_FREE_TEXT, $optIns, true);
        $columns = $includeFreeText
            ? [...self::DEFAULT_COLUMNS, ...self::OPT_IN_COLUMNS]
            : self::DEFAULT_COLUMNS;

        $rows = $this->rows($from, $to, $outcome);
        $payload = $this->toCsv($columns, $rows, $includeFreeText);

        /*
         * The chain's state AT EXPORT TIME. This is a pure replay that writes nothing — the same
         * verification the governance dashboard runs — and it is what lets a recipient tie the file
         * to a ledger whose integrity was intact when it left.
         */
        $chain = $this->audit->verifyChain($actor->tenant_id);

        $manifest = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'generated_by' => (string) $actor->getKey(),
            'tenant_id' => (string) $actor->tenant_id,
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'filters' => ['outcome' => $outcome],
            'row_count' => count($rows),
            'columns' => $columns,
            // Named explicitly, so a reader of the manifest alone can tell whether free text is in
            // the file without opening it.
            'opt_ins' => $includeFreeText ? [self::OPT_IN_FREE_TEXT] : [],
            'contains_free_text' => $includeFreeText,
            'payload_sha256' => hash('sha256', $payload),
            'payload_bytes' => strlen($payload),
            'audit_chain' => [
                'ok' => $chain['ok'],
                'events' => $chain['count'] ?? null,
            ],
            /*
             * HONESTY ABOUT THE EXPORT'S OWN ROW. The export is audited, but that row is written
             * AFTER this snapshot is taken — so it cannot be inside the file. Saying so is the
             * difference between a gap and a silent omission (D-179).
             */
            'self_audit' => 'This export is recorded in the audit log as governance.ledger_exported. That row is written after this snapshot and is therefore not among the rows above.',
        ];

        return [
            'payload' => $payload,
            'manifest' => $manifest,
            'rowCount' => count($rows),
            'filename' => sprintf('governance-ledger-%s_%s.csv', $from->toDateString(), $to->toDateString()),
        ];
    }

    /**
     * The rows for the window — the SAME set the governance dashboard shows, because both ask the
     * ledger the same question over the same window. A divergence here would mean the file and the
     * screen disagreed about what happened, which is the one thing an auditor cannot tolerate.
     *
     * @return list<AiInteraction>
     */
    public function rows(CarbonInterface $from, CarbonInterface $to, ?string $outcome = null): array
    {
        $query = AiInteraction::query()
            ->whereBetween('occurred_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->orderBy('occurred_at')
            ->orderBy('id');

        if ($outcome !== null && $outcome !== '') {
            $query->where('outcome', $outcome);
        }

        // Tenant scoping is the model's own global scope — fail-closed, and not something this
        // service can forget to apply.
        return $query->get()->all();
    }

    /**
     * @param  list<string>  $columns
     * @param  list<AiInteraction>  $rows
     */
    private function toCsv(array $columns, array $rows, bool $includeFreeText): string
    {
        // Built in memory: a governance window is thousands of rows at most, and the manifest needs
        // a hash of the whole payload anyway — which a streamed response cannot produce before it
        // has streamed. If a tenant ever outgrows this, the manifest hash is what forces the design
        // (hash-as-you-stream), not the row count.
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $columns);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $column): string => $this->value($row, $column, $includeFreeText),
                $columns,
            ));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function value(AiInteraction $row, string $column, bool $includeFreeText): string
    {
        return match ($column) {
            'occurred_at' => optional($row->getAttribute('occurred_at'))->toIso8601String() ?? '',
            'agent_name' => AgentRegistry::displayName((string) $row->agent),
            'tool' => $this->firstTool($row),
            // Free text only when the opt-in was granted; otherwise it never reaches the writer.
            'metadata' => $includeFreeText ? (string) json_encode($row->getAttribute('metadata')) : '',
            'error_message' => $includeFreeText ? (string) $row->error_message : '',
            default => (string) ($row->getAttribute($column) ?? ''),
        };
    }

    private function firstTool(AiInteraction $row): string
    {
        $calls = $row->getAttribute('tool_calls');

        if (is_array($calls) && is_array($calls[0] ?? null) && array_key_exists('tool', $calls[0])) {
            return (string) $calls[0]['tool'];
        }

        return '';
    }
}
