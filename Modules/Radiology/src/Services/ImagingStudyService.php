<?php

namespace Modules\Radiology\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Exceptions\ImagingStudyException;
use Modules\Radiology\Models\ImagingStudy;
use Modules\Radiology\Models\ImagingStudyEvent;
use Modules\Radiology\Models\RadiologyOrder;

/**
 * Imaging-study tracking (RAD.G3) — the one genuine net-new radiology domain (the lab-`Specimen` analog). A
 * study is registered against a RAD.G2 {@see RadiologyOrder} (which links the reused Clinical `Order`),
 * accessioned with a unique-per-tenant identifier (the `Specimen` accession recipe — generated under a
 * tenant-row lock), and advanced through a legal-only state machine (ordered → acquired → reported; + cancelled).
 * Gated `radiology.study` (the radiographer). Append-only state history; audited (app-layer).
 *
 * The Clinical `Order` is REUSED + UNTOUCHED: acquiring records the STUDY state; it does NOT advance the Order's
 * own lifecycle (that stays Clinical's — the report step, RAD.G4, moves the Order via `OrderService`). The study
 * state is the radiology-side tracking.
 *
 * **THE STUDY IS METADATA, NOT THE IMAGE.** This service records that an exam happened + its state; the DICOM
 * image (storage / a diagnostic viewer / PACS retrieval / modality integration) is the PARTNER's — the
 * SEAM-STUBBED RAD.G6, behind `ImagingConnectivity`, NOT built here.
 *
 * ELECTRIC FENCE: the state + accession are FACTS — this service computes NO image finding/CAD/abnormality and
 * NO priority; the worklist may show the RAD.G2 recorded priority as a fact, never a computed ranking.
 */
class ImagingStudyService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Register a study for an imaging order: generate the accession + create the study (status=ordered) + the
     * append-only `ordered` event — atomically. Gated `radiology.study`; tenant fail-closed. IDEMPOTENT: one
     * study per order (returns the existing study). The modality defaults from the RAD.G2 order overlay. Does
     * NOT advance the Clinical Order (see the class docblock).
     */
    public function register(User $actor, RadiologyOrder $order): ImagingStudy
    {
        Gate::forUser($actor)->authorize('radiology.study');
        $this->assertSameTenant($order->tenant_id, 'radiology_order_id', $order->id);

        $existing = ImagingStudy::query()->where('radiology_order_id', $order->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($actor, $order): ImagingStudy {
            $accession = $this->generateAccession();

            $study = ImagingStudy::query()->create([
                'patient_id' => $order->patient_id,
                'radiology_order_id' => $order->id,
                'accession_number' => $accession,
                'modality' => $order->modality,
            ]);

            ImagingStudyEvent::query()->create([
                'patient_id' => $study->patient_id,
                'imaging_study_id' => $study->id,
                'event_type' => ImagingStudyEvent::TYPE_ORDERED,
                'reason' => null,
                'performed_by' => $actor->getKey(),
                'occurred_at' => Carbon::now(),
            ]);

            return $study->refresh();
        });
    }

    /**
     * Acquire a study for an imaging order — register-if-missing, then advance ordered → acquired (recording
     * acquired_by/at + the append-only `acquired` event). The radiographer's one-step "acquire from the
     * worklist". Gated `radiology.study`.
     */
    public function acquire(User $actor, RadiologyOrder $order): ImagingStudy
    {
        $study = $this->register($actor, $order);

        return $this->transition($actor, $study, ImagingStudy::STATUS_ACQUIRED);
    }

    /**
     * Advance the study through its LEGAL-ONLY lifecycle (ordered → acquired → reported; or → cancelled). Gated
     * `radiology.study`; an illegal move throws; a cancellation requires a reason. Records acquired_by/at on the
     * acquire step; appends an append-only event. (`reported` is reached by the RAD.G4 report step.)
     */
    public function transition(User $actor, ImagingStudy $study, string $toStatus, ?string $reason = null): ImagingStudy
    {
        Gate::forUser($actor)->authorize('radiology.study');
        $this->assertSameTenant($study->tenant_id, 'imaging_study_id', $study->id);

        if (! ImagingStudy::canTransition($study->status, $toStatus)) {
            throw ImagingStudyException::invalidTransition($study->status, $toStatus);
        }
        if ($toStatus === ImagingStudy::STATUS_CANCELLED && trim((string) $reason) === '') {
            throw ImagingStudyException::cancellationReasonRequired();
        }

        return DB::transaction(function () use ($actor, $study, $toStatus, $reason): ImagingStudy {
            $fill = ['status' => $toStatus];
            if ($toStatus === ImagingStudy::STATUS_ACQUIRED) {
                $fill['acquired_by'] = $actor->getKey();
                $fill['acquired_at'] = Carbon::now();
            }
            $study->forceFill($fill)->save();

            ImagingStudyEvent::query()->create([
                'patient_id' => $study->patient_id,
                'imaging_study_id' => $study->id,
                'event_type' => $toStatus, // 1:1 with the target state
                'reason' => $reason,
                'performed_by' => $actor->getKey(),
                'occurred_at' => Carbon::now(),
            ]);

            return $study->refresh();
        });
    }

    /**
     * The modality worklist — imaging orders AWAITING acquisition (no study yet, or the study is still
     * `ordered`), for the radiographer. The board / LAB.G5-review idiom: operational FACTS ordered by
     * ordered-time (a fact), NOT a computed priority ranking. Gated `radiology.study`.
     *
     * @return Collection<int, RadiologyOrder>
     */
    public function worklist(User $actor): Collection
    {
        Gate::forUser($actor)->authorize('radiology.study');

        return RadiologyOrder::query()
            ->whereDoesntHave('study', fn ($query) => $query->whereIn('status', [
                ImagingStudy::STATUS_ACQUIRED,
                ImagingStudy::STATUS_REPORTED,
                ImagingStudy::STATUS_CANCELLED,
            ]))
            ->with(['order.orderableItem', 'study'])
            ->get()
            ->sortBy(fn (RadiologyOrder $r): string => (string) $r->order?->ordered_at)
            ->values();
    }

    /** The study for an imaging order (with its append-only event history), or null. */
    public function forOrder(RadiologyOrder $order): ?ImagingStudy
    {
        return ImagingStudy::query()->where('radiology_order_id', $order->id)->with('events')->first();
    }

    /**
     * @return Collection<int, ImagingStudy>
     */
    public function forPatient(Patient $patient): Collection
    {
        return ImagingStudy::query()->where('patient_id', $patient->id)->with('radiologyOrder')->orderByDesc('created_at')->get();
    }

    /**
     * Generate a unique-per-tenant accession number (IMG-000001 style) — the `Specimen` accession recipe: lock
     * the tenant row so concurrent registrations serialise, take the max existing, and increment past any gap.
     * The `unique(tenant_id, accession_number)` constraint is the belt. A factual identifier, never a judgment.
     */
    private function generateAccession(): string
    {
        $tenantId = $this->tenantContext->id();

        DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

        $last = ImagingStudy::query()
            ->where('accession_number', 'like', 'IMG-%')
            ->orderByDesc('accession_number')
            ->value('accession_number');

        $next = $last !== null ? ((int) substr((string) $last, 4)) + 1 : 1;

        do {
            $accession = sprintf('IMG-%06d', $next);
            $next++;
        } while (ImagingStudy::query()->where('accession_number', $accession)->exists());

        return $accession;
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
