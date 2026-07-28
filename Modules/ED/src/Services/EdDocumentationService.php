<?php

namespace Modules\ED\Services;

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
use Modules\ED\Exceptions\EdVisitException;
use Modules\ED\Models\EdVisit;
use Modules\ED\Models\EdVisitEncounter;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;

/**
 * ED clinical documentation (ED.G4) — REUSE-heavy, not new clinical domain. It composes the EXISTING tested
 * Clinical module against an `EdVisit`, WITHOUT modifying Clinical (the inpatient bedside-chart / surgery
 * op-note pattern, per docs/HOSPITAL-PHASE6-ED-MAP.md §2 / the map's "reuse Clinical" line):
 *
 *  - a treatment encounter is a reused Clinical `Encounter` (opened via EncounterService — its
 *    one-open-per-practitioner invariant enforced UNCHANGED), linked to the visit by an ED-side
 *    {@see EdVisitEncounter} so Clinical's schema/invariant stay untouched (Encounter is NOT modified);
 *  - the encounter's note is the existing sign-and-lock `ClinicalNote` (write → sign → read-only → amend →
 *    version), created here as a draft and edited/signed in the EXISTING editor;
 *  - vitals are the existing raw `Vital` (ClinicalListService::recordVital), tied to the visit's treatment
 *    Encounter; the visit-scoped READ ({@see self::vitalsForVisit()}) is the only new affordance and adds NO
 *    interpretation — it reuses VitalsSeries (raw values over time, no bands/flags/scores);
 *  - orders are the existing structured `Order` (OrderService::place / append-only OrderResult).
 *
 * The USER actor drives auth (encounter.manage / note.write / order.manage — the ED roles hold these); a
 * treating clinician (a StaffProfile) is the clinical context for the encounter + its records. ELECTRIC FENCE
 * carries through: raw vitals, sign-and-lock notes, NO computed acuity / severity / deterioration score
 * anywhere (the recorded triage acuity is G2's nurse-ASSIGNED value; nothing is computed here).
 */
class EdDocumentationService
{
    public function __construct(
        private readonly EncounterService $encounters,
        private readonly ClinicalNoteService $notes,
        private readonly ClinicalListService $clinicalLists,
        private readonly OrderService $orders,
    ) {}

    /**
     * Start a treatment encounter for the visit: open a Clinical Encounter (invariant enforced — a second
     * concurrent open encounter for the same patient + practitioner is REFUSED by EncounterService), link it
     * to the visit ED-side, and create the sign-and-lock note draft — all reused Clinical, atomically. Returns
     * the link + its draft note (the caller redirects into the EXISTING note editor).
     *
     * @return array{link: EdVisitEncounter, note: ClinicalNote}
     */
    public function startEncounter(User $actor, EdVisit $visit, StaffProfile $practitioner, ?string $reason = null): array
    {
        $patient = Patient::query()->findOrFail($visit->patient_id);
        $branch = Branch::query()->findOrFail($visit->branch_id);
        $template = NoteTemplate::query()->where('active', true)->orderBy('name')->first();

        return DB::transaction(function () use ($actor, $visit, $patient, $branch, $practitioner, $reason, $template): array {
            // An ED treatment encounter is a reused Encounter (TYPE_CONSULTATION — NO ED type is added to
            // Clinical); EncounterService enforces encounter.manage + the one-open-per-practitioner invariant.
            $encounter = $this->encounters->open($patient, $practitioner, $branch, null, Encounter::TYPE_CONSULTATION, $actor, $reason);

            $link = EdVisitEncounter::query()->create(['ed_visit_id' => $visit->id, 'encounter_id' => $encounter->id]);

            // The existing sign-and-lock note (draft) — edited/signed/amended in the existing editor.
            $note = $this->notes->saveDraft($encounter, $practitioner, [], $actor, null, $template);

            return ['link' => $link, 'note' => $note];
        });
    }

    /**
     * Record a vital during the visit — the EXISTING raw `Vital`, tied to the visit's latest treatment
     * Encounter (so it scopes to the visit's clinical record). Reuses ClinicalListService::recordVital
     * (note.write). Raw facts only — no interpretation. The recorder is the treatment encounter's clinician.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordVital(User $actor, EdVisit $visit, array $data): Vital
    {
        $encounter = $this->latestVisitEncounter($visit);
        $recorder = StaffProfile::query()->findOrFail($encounter->practitioner_id);

        return $this->clinicalLists->recordVital(
            Patient::query()->findOrFail($visit->patient_id),
            $recorder,
            $actor,
            $data,
            $encounter,
        );
    }

    /**
     * Place an order during the visit — the EXISTING structured `Order`, tied to the visit's latest treatment
     * Encounter. Reuses OrderService::place (order.manage).
     *
     * @param  array{priority?: string, clinical_note?: string|null}  $data
     */
    public function placeOrder(User $actor, EdVisit $visit, OrderableItem $item, array $data): Order
    {
        $encounter = $this->latestVisitEncounter($visit);

        return $this->orders->place(Patient::query()->findOrFail($visit->patient_id), $encounter, $item, $data, $actor);
    }

    /**
     * The visit's treatment encounters (newest first) with their Encounter + practitioner — the record's
     * encounter list.
     *
     * @return Collection<int, EdVisitEncounter>
     */
    public function encountersForVisit(EdVisit $visit): Collection
    {
        return EdVisitEncounter::query()
            ->where('ed_visit_id', $visit->id)
            ->with(['encounter.practitioner'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * The visit's raw vitals over time — the ONLY new affordance (a visit-scoped read). It filters the existing
     * `Vital` store to the visit's treatment Encounters and builds the RAW per-metric series via the existing
     * VitalsSeries (no bands/flags/scores — the electric fence, unchanged).
     *
     * @return array{metrics: array<string, list<array{recorded_at: string, value: mixed, source: string}>>}
     */
    public function vitalsForVisit(EdVisit $visit): array
    {
        $encounterIds = EdVisitEncounter::query()->where('ed_visit_id', $visit->id)->pluck('encounter_id')->all();

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
     * The visit's orders (newest first) — the existing structured orders on the visit's treatment Encounters.
     *
     * @return Collection<int, Order>
     */
    public function ordersForVisit(EdVisit $visit): Collection
    {
        $encounterIds = EdVisitEncounter::query()->where('ed_visit_id', $visit->id)->pluck('encounter_id')->all();

        return Order::query()
            ->whereIn('encounter_id', $encounterIds)
            ->with(['orderableItem', 'results'])
            ->orderByDesc('ordered_at')
            ->get();
    }

    /**
     * The visit's latest treatment Encounter — vitals/orders tie to it. A visit must have at least one
     * treatment encounter before charting (start one first).
     */
    private function latestVisitEncounter(EdVisit $visit): Encounter
    {
        $link = EdVisitEncounter::query()
            ->where('ed_visit_id', $visit->id)
            ->with('encounter')
            ->orderByDesc('created_at')
            ->first();

        if ($link === null || $link->encounter === null) {
            throw EdVisitException::noEncounter($visit->id);
        }

        return $link->encounter;
    }
}
