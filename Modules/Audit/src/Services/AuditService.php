<?php

namespace Modules\Audit\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Contracts\AuditContext;
use Modules\Audit\Models\AuditEvent;

/**
 * The append-only, per-tenant hash-chained audit write path.
 *
 * Each tenant (plus the null "platform" bucket) has an independent chain: every
 * event's hash covers a canonical ordered payload INCLUDING the previous event's
 * hash, so any tampering or gap is detectable by {@see verifyChain()}.
 */
class AuditService
{
    /**
     * The exact field order hashed into the chain (documented for auditors).
     *
     * @var list<string>
     */
    private const HASH_FIELDS = [
        'id', 'tenant_id', 'actor_type', 'actor_id', 'action',
        'resource_type', 'resource_id', 'patient_id', 'before_hash',
        'after_hash', 'reason', 'ip', 'ua', 'context', 'occurred_at', 'prev_hash',
    ];

    public function __construct(private readonly AuditContext $context) {}

    /**
     * Append one event and return the stored record.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data): AuditEvent
    {
        $tenantId = array_key_exists('tenant_id', $data)
            ? $data['tenant_id']
            : $this->context->tenantId();

        $actor = $this->context->actor();
        $actorType = $data['actor_type'] ?? $actor['type'];
        $actorId = array_key_exists('actor_id', $data) ? $data['actor_id'] : $actor['id'];

        return DB::transaction(function () use ($data, $tenantId, $actorType, $actorId) {
            // Serialize appends for this tenant by locking its latest row.
            $prev = DB::selectOne(
                'SELECT hash, occurred_at FROM audit_events '.
                'WHERE tenant_id <=> ? ORDER BY occurred_at DESC, id DESC LIMIT 1 FOR UPDATE',
                [$tenantId],
            );

            $prevHash = $prev !== null ? $prev->hash : null;

            // Strictly monotonic occurred_at per tenant so the stored order and
            // the verification order always agree.
            $occurredAt = Carbon::now();
            if ($prev !== null) {
                $prevTime = Carbon::parse($prev->occurred_at);
                if ($occurredAt <= $prevTime) {
                    $occurredAt = $prevTime->copy()->addMicrosecond();
                }
            }

            $fields = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'actor_type' => $actorType,
                'actor_id' => $actorId !== null ? (string) $actorId : null,
                'action' => $data['action'],
                'resource_type' => $data['resource_type'] ?? null,
                'resource_id' => $data['resource_id'] ?? null,
                'patient_id' => $data['patient_id'] ?? null,
                'before_hash' => $data['before_hash'] ?? null,
                'after_hash' => $data['after_hash'] ?? null,
                'reason' => $data['reason'] ?? null,
                'ip' => $data['ip'] ?? $this->context->ip(),
                'ua' => $data['ua'] ?? $this->context->userAgent(),
                'context' => $data['context'] ?? null,
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u'),
                'prev_hash' => $prevHash,
            ];

            $hash = $this->hash($fields);

            DB::table('audit_events')->insert([
                ...$fields,
                'context' => $fields['context'] !== null ? json_encode($fields['context']) : null,
                'hash' => $hash,
            ]);

            return AuditEvent::query()->where('id', $fields['id'])->firstOrFail();
        });
    }

    /**
     * Record a read of a sensitive resource (action 'read'). This is the
     * mechanism the patient "who accessed my record" report builds on.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordRead(string $resourceType, string $resourceId, ?string $patientId = null, array $context = []): AuditEvent
    {
        return $this->record([
            'action' => 'read',
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'patient_id' => $patientId,
            'context' => $context !== [] ? $context : null,
        ]);
    }

    /**
     * Walk a tenant's chain in order and report whether it is intact.
     *
     * @return array{ok: bool, broken_at: string|null, index?: int, reason?: string, count?: int}
     */
    public function verifyChain(?string $tenantId = null): array
    {
        $rows = DB::select(
            'SELECT * FROM audit_events WHERE tenant_id <=> ? ORDER BY occurred_at ASC, id ASC',
            [$tenantId],
        );

        $prevHash = null;

        foreach ($rows as $index => $row) {
            if (($row->prev_hash ?? null) !== $prevHash) {
                return [
                    'ok' => false,
                    'broken_at' => $row->id,
                    'index' => $index,
                    'reason' => 'prev_hash does not match the preceding row',
                ];
            }

            if ($this->hash($this->fieldsFromRow($row)) !== $row->hash) {
                return [
                    'ok' => false,
                    'broken_at' => $row->id,
                    'index' => $index,
                    'reason' => 'stored hash does not match the row content',
                ];
            }

            $prevHash = $row->hash;
        }

        return ['ok' => true, 'broken_at' => null, 'count' => count($rows)];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function hash(array $fields): string
    {
        $payload = [];
        foreach (self::HASH_FIELDS as $key) {
            $payload[$key] = $key === 'context'
                ? $this->normalizeContext($fields['context'] ?? null)
                : ($fields[$key] ?? null);
        }

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldsFromRow(object $row): array
    {
        return [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'actor_type' => $row->actor_type,
            'actor_id' => $row->actor_id,
            'action' => $row->action,
            'resource_type' => $row->resource_type,
            'resource_id' => $row->resource_id,
            'patient_id' => $row->patient_id,
            'before_hash' => $row->before_hash,
            'after_hash' => $row->after_hash,
            'reason' => $row->reason,
            'ip' => $row->ip,
            'ua' => $row->ua,
            'context' => $row->context !== null ? json_decode($row->context, true) : null,
            'occurred_at' => $row->occurred_at,
            'prev_hash' => $row->prev_hash,
        ];
    }

    /**
     * Deterministically normalize context so write-time and verify-time hashes
     * agree regardless of key order.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeContext(mixed $context): ?array
    {
        if ($context === null) {
            return null;
        }

        $array = (array) $context;
        $this->ksortRecursive($array);

        return $array;
    }

    /**
     * @param  array<mixed>  $array
     */
    private function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
