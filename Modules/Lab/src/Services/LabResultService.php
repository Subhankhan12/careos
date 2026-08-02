<?php

namespace Modules\Lab\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderResult;
use Modules\Clinical\Services\OrderService;
use Modules\Lab\Exceptions\LabResultException;
use Modules\Lab\Models\LabResult;
use Modules\Lab\Models\Specimen;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Manual lab result entry (LAB.G4) — REUSE-first, THE FENCE GATE. A lab result IS a Clinical `OrderResult`:
 * this service records it through the EXISTING {@see OrderService::recordResult} (append-only, raw,
 * `source=manual`; advances the reused `Order` → resulted), then appends the thin lab {@see LabResult} overlay
 * linking that `OrderResult` to the LAB.G3 {@see Specimen} that produced it, and advances the specimen →
 * resulted through the G3 legal-only state machine (collected → in_lab → resulted). **Clinical's `OrderResult`/
 * `OrderService`/seam are REUSED, not duplicated or modified.**
 *
 * Gated `lab.result` (the lab bench — the lab-domain permission); the reused `recordResult` additionally checks
 * `order.manage` (the lab_tech / pathologist / org_admin holds both — see RbacProvisioner). The
 * `LabConnectivity` seam stays the manual no-op (`source=manual`; no homemade HL7 — that is the partner-gated
 * LAB.G7).
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE3-LAB-MAP.md §4 — the sharpest in lab): this service records the RAW value
 * only. The reference range is DISPLAYED reference data (from the LAB.G1 catalog, at presentation) — it is NEVER
 * a threshold this service grades the value against. NO abnormal/high/low/critical FLAG, NO delta-check, NO
 * interpretation is computed anywhere (the vitals-bands line; `OrderResult` already forbids the interpretation
 * column). The clinician reads value-vs-range; the system computes no verdict.
 */
class LabResultService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OrderService $orders,
        private readonly SpecimenService $specimens,
    ) {}

    /**
     * Record a MANUAL result against a specimen. REUSES `OrderService::recordResult` (the raw append-only
     * `OrderResult`, `source=manual`, advancing the reused `Order` → resulted), appends the thin `LabResult`
     * overlay linking the result to the specimen, and advances the specimen → resulted through the G3 legal
     * machine — atomically (one transaction: all, or none). Requires a raw value and/or a linked document (the
     * reused service enforces this). The specimen must not already be terminal (resulted/rejected).
     *
     * @param  array{value?: string|null, document_id?: string|null}  $data
     * @return array{result: OrderResult, labResult: LabResult}
     */
    public function record(User $actor, Specimen $specimen, array $data): array
    {
        Gate::forUser($actor)->authorize('lab.result');
        $this->assertSameTenant($specimen->tenant_id, 'specimen_id', $specimen->id);

        if (in_array($specimen->status, [Specimen::STATUS_RESULTED, Specimen::STATUS_REJECTED], true)) {
            throw LabResultException::specimenNotResultable($specimen->status);
        }

        $labOrder = $specimen->labOrder; // tenant-scoped by BelongsToTenant
        $order = Order::query()->findOrFail($labOrder->order_id);

        return DB::transaction(function () use ($actor, $specimen, $order, $data): array {
            // REUSE the EXISTING Clinical result path — append-only, RAW, source=manual; advances Order → resulted.
            // recordResult stores the raw value ONLY (no interpretation column) and re-checks order.manage.
            $result = $this->orders->recordResult($order, [
                'value' => $data['value'] ?? null,
                'document_id' => $data['document_id'] ?? null,
            ], $actor);

            // The thin overlay: link the reused OrderResult to the specimen that produced it (append-only, no value).
            $labResult = LabResult::query()->create([
                'patient_id' => $specimen->patient_id,
                'order_result_id' => $result->id,
                'specimen_id' => $specimen->id,
            ]);

            // Advance the specimen → resulted through the G3 legal-only machine (each hop legal + append-only).
            $this->advanceSpecimenToResulted($actor, $specimen);

            return ['result' => $result, 'labResult' => $labResult];
        });
    }

    /**
     * Walk the specimen to `resulted` using ONLY the G3 legal transitions: a `collected` specimen goes
     * collected → in_lab → resulted; an `in_lab` specimen goes in_lab → resulted. Never a direct/illegal hop.
     */
    private function advanceSpecimenToResulted(User $actor, Specimen $specimen): void
    {
        if ($specimen->status === Specimen::STATUS_COLLECTED) {
            $specimen = $this->specimens->transition($actor, $specimen, Specimen::STATUS_IN_LAB);
        }

        $this->specimens->transition($actor, $specimen, Specimen::STATUS_RESULTED);
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
