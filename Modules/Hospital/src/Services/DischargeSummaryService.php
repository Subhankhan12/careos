<?php

namespace Modules\Hospital\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\DischargeSummary;
use Modules\Hospital\Models\Stay;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Authors + finalizes the discharge summary (HOSPITAL.G7) — the SIGN-AND-LOCK discipline reused from
 * `ClinicalNoteService` (draft is editable; finalize locks it, `note.sign` under a row lock; a finalized
 * summary is immutable). One summary per stay. Tenant/patient fail-closed off the `Stay`. NOTHING is
 * computed or auto-populated — the narrative is the discharging clinician's own words (record-not-judge);
 * the write is audited via an app-layer model hook so Hospital stays free of Audit.
 */
class DischargeSummaryService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Create or update the stay's DRAFT summary (gate `note.write`). Refuses once finalized.
     */
    public function saveDraft(User $actor, Stay $stay, string $summary, ?string $instructions): DischargeSummary
    {
        Gate::forUser($actor)->authorize('note.write');
        $this->assertStayTenant($stay);

        $existing = DischargeSummary::query()->where('stay_id', $stay->id)->first();
        if ($existing !== null && $existing->isFinalized()) {
            throw AdmissionException::dischargeSummaryFinalized();
        }

        return DischargeSummary::query()->updateOrCreate(
            ['stay_id' => $stay->id],
            [
                'patient_id' => $stay->patient_id,
                'authored_by' => $actor->id,
                'summary' => $summary,
                'instructions' => $instructions,
                'status' => DischargeSummary::STATUS_DRAFT,
            ],
        );
    }

    /**
     * Sign-and-lock the stay's draft (gate `note.sign`) — idempotent, row-locked, requires a narrative.
     * After this the summary is immutable (model guard + DB trigger).
     */
    public function finalize(User $actor, Stay $stay): DischargeSummary
    {
        Gate::forUser($actor)->authorize('note.sign');
        $this->assertStayTenant($stay);

        return DB::transaction(function () use ($actor, $stay): DischargeSummary {
            $summary = DischargeSummary::query()->where('stay_id', $stay->id)->lockForUpdate()->first();

            if ($summary === null) {
                throw AdmissionException::dischargeSummaryMissing($stay->id);
            }

            if ($summary->isFinalized()) {
                return $summary;
            }

            if (trim($summary->summary) === '') {
                throw AdmissionException::dischargeSummaryRequiresNarrative();
            }

            $summary->forceFill([
                'status' => DischargeSummary::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
            ])->save();

            return $summary->refresh();
        });
    }

    public function forStay(Stay $stay): ?DischargeSummary
    {
        return DischargeSummary::query()->where('stay_id', $stay->id)->first();
    }

    private function assertStayTenant(Stay $stay): void
    {
        if ($stay->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('stay_id', (string) $stay->id);
        }
    }
}
