<?php

namespace Modules\Hospital\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\NoteTemplate;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Models\Vital;
use Modules\Clinical\Services\ClinicalListService;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\EncounterService;
use Modules\Clinical\Services\OrderService;
use Modules\Clinical\Support\VitalsSeries;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Models\WardRound;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;

/**
 * Bedside charting for an inpatient stay (HOSPITAL.G4) — REUSE-heavy, not new clinical domain.
 * It composes the EXISTING tested Clinical module against the stay, WITHOUT modifying Clinical:
 *
 *  - a ward round is a reused Clinical `Encounter` (opened via EncounterService — its
 *    one-open-per-practitioner invariant enforced UNCHANGED), linked to the stay by a Hospital-side
 *    {@see WardRound} so Clinical's schema/invariant stay untouched;
 *  - the round's note is the existing sign-and-lock `ClinicalNote` (ClinicalNoteService — write ->
 *    sign -> read-only -> amend -> version), created here as a draft and edited in the existing editor;
 *  - vitals are the existing raw `Vital` (ClinicalListService::recordVital), tied to the round's
 *    Encounter; the stay-scoped READ is the only new affordance ({@see self::vitalsForStay()}) and
 *    adds NO interpretation — it reuses VitalsSeries (raw values over time, no bands/flags/scores);
 *  - orders are the existing structured `Order` (OrderService::place / append-only OrderResult).
 *
 * The USER actor drives auth (encounter.manage / note.write / order.manage — the inpatient roles
 * hold these); the stay's admitting clinician is the StaffProfile clinical context for the round and
 * its records. ELECTRIC FENCE carries through: raw vitals, sign-and-lock notes, NO computed acuity /
 * deterioration / early-warning score anywhere (a NEWS2-style score is certified-partner/non-goal).
 */
class BedsideChartService
{
    public function __construct(
        private readonly EncounterService $encounters,
        private readonly ClinicalNoteService $notes,
        private readonly ClinicalListService $clinicalLists,
        private readonly OrderService $orders,
    ) {}

    /**
     * Start a ward round: open a Clinical Encounter for the stay (invariant enforced), link it to
     * the stay, and create the sign-and-lock note draft — all reused Clinical, atomically. Returns
     * the round + its draft note (the caller redirects into the EXISTING note editor).
     *
     * @return array{round: WardRound, note: ClinicalNote}
     */
    public function startRound(User $actor, Stay $stay, ?string $reason = null): array
    {
        // Required FKs — resolve as typed models (tenant-scoped) for the reused Clinical services.
        $patient = Patient::query()->findOrFail($stay->patient_id);
        $branch = Branch::query()->findOrFail($stay->branch_id);
        $clinician = StaffProfile::query()->findOrFail($stay->admitting_clinician_id);
        $template = NoteTemplate::query()->where('active', true)->orderBy('name')->first();

        return DB::transaction(function () use ($actor, $stay, $patient, $branch, $clinician, $reason, $template): array {
            // A ward round is a reused Encounter (type 'other' — no inpatient type is added to Clinical);
            // EncounterService enforces encounter.manage + the one-open-per-practitioner invariant.
            $encounter = $this->encounters->open($patient, $clinician, $branch, null, Encounter::TYPE_OTHER, $actor, $reason);

            $round = WardRound::query()->create(['stay_id' => $stay->id, 'encounter_id' => $encounter->id]);

            // The existing sign-and-lock note (draft) — edited/signed/amended in the existing editor.
            $note = $this->notes->saveDraft($encounter, $clinician, [], $actor, null, $template);

            return ['round' => $round, 'note' => $note];
        });
    }

    /**
     * Record a vital during the stay — the EXISTING raw Vital, tied to the stay's latest ward round's
     * Encounter (so it scopes to the stay). Reuses ClinicalListService::recordVital (note.write). Raw
     * facts only — no interpretation.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordVital(User $actor, Stay $stay, array $data): Vital
    {
        $encounter = $this->latestRoundEncounter($stay);

        return $this->clinicalLists->recordVital(
            Patient::query()->findOrFail($stay->patient_id),
            StaffProfile::query()->findOrFail($stay->admitting_clinician_id),
            $actor,
            $data,
            $encounter,
        );
    }

    /**
     * Place an order during the stay — the EXISTING structured Order, tied to the stay's latest ward
     * round's Encounter. Reuses OrderService::place (order.manage).
     *
     * @param  array{priority?: string, clinical_note?: string|null}  $data
     */
    public function placeOrder(User $actor, Stay $stay, OrderableItem $item, array $data): Order
    {
        $encounter = $this->latestRoundEncounter($stay);

        return $this->orders->place(Patient::query()->findOrFail($stay->patient_id), $encounter, $item, $data, $actor);
    }

    /**
     * The stay's ward rounds (newest first) with their Encounter + note — the chart's round list.
     *
     * @return Collection<int, WardRound>
     */
    public function roundsForStay(Stay $stay): Collection
    {
        return WardRound::query()
            ->where('stay_id', $stay->id)
            ->with(['encounter.practitioner'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * The stay's raw vitals over time — the ONLY new affordance (a stay-scoped read). It filters the
     * existing Vital store to the stay's round Encounters and builds the RAW per-metric series via the
     * existing VitalsSeries (no bands/flags/scores — the electric fence, unchanged).
     *
     * @return array{metrics: array<string, list<array{recorded_at: string, value: mixed, source: string}>>}
     */
    public function vitalsForStay(Stay $stay): array
    {
        $encounterIds = WardRound::query()->where('stay_id', $stay->id)->pluck('encounter_id')->all();

        $readings = Vital::query()
            ->whereIn('encounter_id', $encounterIds)
            ->orderByDesc('recorded_at')
            ->get()
            ->map(fn (Vital $vital): array => [
                'recorded_at' => $vital->recorded_at->toDateTimeString(),
                'source' => VitalsSeries::SOURCE_CLINIC,
                'systolic' => $vital->systolic,
                'diastolic' => $vital->diastolic,
                'heart_rate' => $vital->heart_rate,
                'temperature_c' => $vital->temperature_c,
                'spo2' => $vital->spo2,
                'weight_g' => $vital->weight_g,
                'height_mm' => $vital->height_mm,
            ])
            ->all();

        return ['metrics' => VitalsSeries::build($readings, 'desc')];
    }

    /**
     * The stay's orders (newest first) — the existing structured orders on the stay's round Encounters.
     *
     * @return Collection<int, Order>
     */
    public function ordersForStay(Stay $stay): Collection
    {
        $encounterIds = WardRound::query()->where('stay_id', $stay->id)->pluck('encounter_id')->all();

        return Order::query()
            ->whereIn('encounter_id', $encounterIds)
            ->with(['orderableItem', 'results'])
            ->orderByDesc('ordered_at')
            ->get();
    }

    /**
     * The stay's latest ward round's Encounter — vitals/orders tie to it. A stay must have at least one
     * ward round before charting (start one first).
     */
    private function latestRoundEncounter(Stay $stay): Encounter
    {
        $round = WardRound::query()
            ->where('stay_id', $stay->id)
            ->with('encounter')
            ->orderByDesc('created_at')
            ->first();

        if ($round === null || $round->encounter === null) {
            throw AdmissionException::noWardRound($stay->id);
        }

        return $round->encounter;
    }
}
