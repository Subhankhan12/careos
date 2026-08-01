<?php

namespace Modules\Lab\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Lab\Exceptions\SpecimenException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\Specimen;
use Modules\Lab\Models\SpecimenEvent;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * Specimen tracking (LAB.G3) — the one genuine net-new lab entity. A specimen is collected against a LAB.G2
 * {@see LabOrder} (which links the reused Clinical `Order`), accessioned with a unique-per-tenant identifier
 * (the `MrnGenerator` precedent — generated under a tenant-row lock), and advanced through a legal-only state
 * machine (collected → in_lab → resulted; + rejected). Gated `lab.result` (the phlebotomist / lab tech).
 * Append-only state history; audited (app-layer).
 *
 * The Clinical `Order` is REUSED + UNTOUCHED: collection records the SPECIMEN; it does NOT advance the Order's
 * own lifecycle (that stays Clinical's — the result step, LAB.G4, moves the Order via `OrderService`, which is
 * `order.manage`-gated; the phlebotomist holds only `lab.result`). The specimen state is the lab-side tracking.
 *
 * ELECTRIC FENCE (operational): the state + accession are FACTS (where the specimen is / its identifier) —
 * this service computes NO priority/urgency, auto-routes NOTHING by a computed acuity. A STAT flag is the
 * LAB.G2 clinician-recorded flag; a worklist may show it as a fact, never a computed ranking.
 */
class SpecimenService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Collect a specimen for a lab order: generate the accession + create the specimen (status=collected) + the
     * append-only `collected` event — atomically. Gated `lab.result`; tenant fail-closed. The specimen type
     * defaults from the LAB.G2 order overlay. Does NOT advance the Clinical Order (see the class docblock).
     */
    public function collect(User $actor, LabOrder $labOrder, ?string $containerType = null, ?string $collectionNote = null): Specimen
    {
        Gate::forUser($actor)->authorize('lab.result');
        $this->assertSameTenant($labOrder->tenant_id, 'lab_order_id', $labOrder->id);

        return DB::transaction(function () use ($actor, $labOrder, $containerType, $collectionNote): Specimen {
            $accession = $this->generateAccession();

            $specimen = Specimen::query()->create([
                'patient_id' => $labOrder->patient_id,
                'lab_order_id' => $labOrder->id,
                'accession_number' => $accession,
                'specimen_type' => $labOrder->specimen_type,
                'container_type' => $containerType,
                'collection_note' => $collectionNote,
                'collected_by' => $actor->getKey(),
                'collected_at' => Carbon::now(),
            ]);

            SpecimenEvent::query()->create([
                'patient_id' => $specimen->patient_id,
                'specimen_id' => $specimen->id,
                'event_type' => SpecimenEvent::TYPE_COLLECTED,
                'reason' => null,
                'performed_by' => $actor->getKey(),
                'occurred_at' => Carbon::now(),
            ]);

            return $specimen->refresh();
        });
    }

    /**
     * Advance the specimen through its LEGAL-ONLY lifecycle (collected → in_lab → resulted; or → rejected).
     * Gated `lab.result`; an illegal move throws; a rejection requires a reason. Appends an append-only event.
     */
    public function transition(User $actor, Specimen $specimen, string $toStatus, ?string $reason = null): Specimen
    {
        Gate::forUser($actor)->authorize('lab.result');
        $this->assertSameTenant($specimen->tenant_id, 'specimen_id', $specimen->id);

        if (! Specimen::canTransition($specimen->status, $toStatus)) {
            throw SpecimenException::invalidTransition($specimen->status, $toStatus);
        }
        if ($toStatus === Specimen::STATUS_REJECTED && trim((string) $reason) === '') {
            throw SpecimenException::rejectionReasonRequired();
        }

        return DB::transaction(function () use ($actor, $specimen, $toStatus, $reason): Specimen {
            $specimen->forceFill(['status' => $toStatus])->save();

            SpecimenEvent::query()->create([
                'patient_id' => $specimen->patient_id,
                'specimen_id' => $specimen->id,
                'event_type' => $toStatus, // 1:1 with the target state
                'reason' => $reason,
                'performed_by' => $actor->getKey(),
                'occurred_at' => Carbon::now(),
            ]);

            return $specimen->refresh();
        });
    }

    /**
     * @return Collection<int, Specimen>
     */
    public function forOrder(LabOrder $labOrder): Collection
    {
        return Specimen::query()->where('lab_order_id', $labOrder->id)->orderByDesc('collected_at')->get();
    }

    /**
     * @return Collection<int, Specimen>
     */
    public function forPatient(Patient $patient): Collection
    {
        return Specimen::query()->where('patient_id', $patient->id)->with('labOrder')->orderByDesc('collected_at')->get();
    }

    /**
     * Generate a unique-per-tenant accession number (ACC-000001 style) — the `MrnGenerator` recipe: lock the
     * tenant row so concurrent collections serialise, take the max existing, and increment past any gap. The
     * `unique(tenant_id, accession_number)` constraint is the belt. A factual identifier, never a judgment.
     */
    private function generateAccession(): string
    {
        $tenantId = $this->tenantContext->id();

        DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

        $last = Specimen::query()
            ->where('accession_number', 'like', 'ACC-%')
            ->orderByDesc('accession_number')
            ->value('accession_number');

        $next = $last !== null ? ((int) substr((string) $last, 4)) + 1 : 1;

        do {
            $accession = sprintf('ACC-%06d', $next);
            $next++;
        } while (Specimen::query()->where('accession_number', $accession)->exists());

        return $accession;
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
