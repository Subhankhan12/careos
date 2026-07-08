<?php

namespace Modules\Patients\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Models\PatientCoverage;
use Modules\Patients\Models\PatientIdentifier;
use Modules\Patients\Models\PortalAccount;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Services\TenantContext;

class PatientMergeService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function merge(string $sourceId, string $targetId, string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A merge reason is required.');
        }

        Gate::authorize('patient.merge');

        if ($sourceId === $targetId) {
            throw new InvalidArgumentException('A patient cannot be merged into itself.');
        }

        return DB::transaction(function () use ($sourceId, $targetId, $reason): string {
            $source = Patient::query()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
            $target = Patient::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($source, $target, $reason);

            $this->moveChildren($snapshot, $target->id);

            $source->forceFill([
                'status' => Patient::STATUS_MERGED,
                'merged_into_id' => $target->id,
            ])->save();
            $source->delete();

            $event = $this->audit->record([
                'action' => 'patient.merged',
                'resource_type' => 'patient',
                'resource_id' => $target->id,
                'patient_id' => $target->id,
                'reason' => $reason,
                'context' => $snapshot,
            ]);

            return (string) $event->id;
        });
    }

    public function unmerge(string $mergeEventId): string
    {
        return DB::transaction(function () use ($mergeEventId): string {
            $event = $this->mergeEvent($mergeEventId);
            $context = json_decode((string) $event->context, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($context)) {
                throw new InvalidArgumentException('Merge audit event has no reversible snapshot.');
            }

            $sourceId = (string) ($context['source_patient_id'] ?? '');
            $targetId = (string) ($context['target_patient_id'] ?? '');

            if ($sourceId === '' || $targetId === '') {
                throw new InvalidArgumentException('Merge audit event snapshot is incomplete.');
            }

            $source = Patient::withTrashed()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
            Patient::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();

            if ($source->trashed()) {
                $source->restore();
            }

            /** @var array<string, mixed> $sourceBefore */
            $sourceBefore = is_array($context['source_before'] ?? null) ? $context['source_before'] : [];

            $source->forceFill([
                'status' => (string) ($sourceBefore['status'] ?? Patient::STATUS_ACTIVE),
                'merged_into_id' => $sourceBefore['merged_into_id'] ?? null,
            ])->save();

            $this->restoreMovedChildren($context, $sourceId, $targetId);

            $event = $this->audit->record([
                'action' => 'patient.unmerged',
                'resource_type' => 'patient',
                'resource_id' => $sourceId,
                'patient_id' => $sourceId,
                'reason' => 'Unmerge of '.$mergeEventId,
                'context' => [
                    'merge_event_id' => $mergeEventId,
                    'source_patient_id' => $sourceId,
                    'target_patient_id' => $targetId,
                    'restored' => $context['moved'] ?? [],
                    'boundary' => 'Only records moved by the original merge are restored; target records created afterward remain on the target.',
                ],
            ]);

            return (string) $event->id;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Patient $source, Patient $target, string $reason): array
    {
        return [
            'source_patient_id' => $source->id,
            'target_patient_id' => $target->id,
            'reason' => $reason,
            'source_before' => [
                'status' => $source->status,
                'merged_into_id' => $source->merged_into_id,
                'deleted_at' => $source->deleted_at?->toDateTimeString(),
            ],
            'moved' => [
                'patient_contacts' => $this->ids(PatientContact::query()->where('patient_id', $source->id)->lockForUpdate()->pluck('id')->all()),
                'patient_identifiers' => $this->ids(PatientIdentifier::query()->where('patient_id', $source->id)->lockForUpdate()->pluck('id')->all()),
                'patient_coverages' => $this->ids(PatientCoverage::query()->where('patient_id', $source->id)->lockForUpdate()->pluck('id')->all()),
                'patient_consents' => $this->ids(PatientConsent::query()->where('patient_id', $source->id)->lockForUpdate()->pluck('id')->all()),
                'portal_accounts' => $this->ids(PortalAccount::query()->where('patient_id', $source->id)->lockForUpdate()->pluck('id')->all()),
            ],
            'created_at' => Carbon::now()->toISOString(),
            'boundary' => 'Reversal restores only records moved by this merge; records created on the target afterward are not moved back.',
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function moveChildren(array $snapshot, string $targetId): void
    {
        /** @var array<string, list<string>> $moved */
        $moved = $snapshot['moved'];

        $this->repoint('patient_contacts', $moved['patient_contacts'], $targetId);
        $this->repoint('patient_identifiers', $moved['patient_identifiers'], $targetId);
        $this->repoint('patient_coverages', $moved['patient_coverages'], $targetId);
        $this->repoint('patient_consents', $moved['patient_consents'], $targetId);
        $this->repoint('portal_accounts', $moved['portal_accounts'], $targetId);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function restoreMovedChildren(array $context, string $sourceId, string $targetId): void
    {
        /** @var array<string, list<string>> $moved */
        $moved = is_array($context['moved'] ?? null) ? $context['moved'] : [];

        $this->repoint('patient_contacts', $this->ids($moved['patient_contacts'] ?? []), $sourceId, $targetId);
        $this->repoint('patient_identifiers', $this->ids($moved['patient_identifiers'] ?? []), $sourceId, $targetId);
        $this->repoint('patient_coverages', $this->ids($moved['patient_coverages'] ?? []), $sourceId, $targetId);
        $this->repoint('patient_consents', $this->ids($moved['patient_consents'] ?? []), $sourceId, $targetId);
        $this->repoint('portal_accounts', $this->ids($moved['portal_accounts'] ?? []), $sourceId, $targetId);
    }

    /**
     * @param  list<string>  $ids
     */
    private function repoint(string $table, array $ids, string $patientId, ?string $onlyCurrentPatientId = null): void
    {
        if ($ids === []) {
            return;
        }

        $query = DB::table($table)->whereIn('id', $ids);

        if ($onlyCurrentPatientId !== null) {
            $query->where('patient_id', $onlyCurrentPatientId);
        }

        $query->update([
            'patient_id' => $patientId,
            'updated_at' => now(),
        ]);
    }

    private function mergeEvent(string $mergeEventId): object
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            throw TenantContextMissingException::forQuery(new Patient);
        }

        $event = DB::selectOne(
            'SELECT id, tenant_id, action, context FROM audit_events WHERE id = ? AND tenant_id <=> ? AND action = ?',
            [$mergeEventId, $tenantId, 'patient.merged'],
        );

        if ($event === null) {
            throw (new ModelNotFoundException)->setModel(Patient::class, [$mergeEventId]);
        }

        return $event;
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private function ids(array $ids): array
    {
        return array_values(array_map(fn (mixed $id): string => (string) $id, $ids));
    }
}
