<?php

namespace Modules\Radiology\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\EncounterService;
use Modules\Clinical\Services\OrderService;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Radiology\Exceptions\RadiologyReportException;
use Modules\Radiology\Models\ImagingStudy;
use Modules\Radiology\Models\ImagingStudyReport;
use Modules\Radiology\Models\RadiologyOrder;

/**
 * The radiologist's report (RAD.G4) — THE FENCE GATE. REUSE-heavy, not new clinical domain: a report is a
 * reused sign-and-lock Clinical {@see ClinicalNote} (write → sign → read-only → amend → version — the ED
 * op-note / lab-result precedent), authored by the radiologist on a reused {@see Encounter}, linked to the
 * RAD.G3 {@see ImagingStudy} by a radiology-side {@see ImagingStudyReport} so Clinical stays UNMODIFIED. Signing
 * the report advances the study → reported (the G3 legal transition) and the reused Clinical `Order` → resulted
 * (`OrderService::recordResult` — the report IS the result), whence the EXISTING order → review flow routes it
 * to the ordering clinician (reused, not reinvented — the LAB.G5 precedent).
 *
 * THE ELECTRIC FENCE (the sharpest in radiology): the radiologist AUTHORS the report — findings + impression as
 * PROSE the human writes. The system computes NO image finding, runs NO CAD (computer-aided detection), flags
 * NO abnormality, does NO auto-read/auto-interpret, computes NO confidence/probability, suggests NO diagnosis.
 * "AI radiology" is a HARD medical-device NON-GOAL (the AGENTS.md / dental-imaging line). Nothing auto-populates
 * the report; every word is the radiologist's, recorded through the append-only sign-and-lock discipline.
 */
class RadiologyReportService
{
    public function __construct(
        private readonly EncounterService $encounters,
        private readonly ClinicalNoteService $notes,
        private readonly OrderService $orders,
        private readonly ImagingStudyService $studies,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Author (create or update) the study's DRAFT report — a reused sign-and-lock `ClinicalNote` on a reused
     * `Encounter`, linked radiology-side. The findings + impression are AUTHORED prose (mapped to the note's
     * objective + assessment) — nothing is computed or auto-populated. Gated `note.write` (in the reused
     * `ClinicalNoteService::saveDraft`). Idempotent on the study's report encounter.
     */
    public function saveDraft(User $actor, ImagingStudy $study, StaffProfile $radiologist, ?string $findings, ?string $impression): ClinicalNote
    {
        $this->assertSameTenant($study->tenant_id, 'imaging_study_id', $study->id);

        return DB::transaction(function () use ($actor, $study, $radiologist, $findings, $impression): ClinicalNote {
            $encounter = $this->reportEncounter($actor, $study, $radiologist);
            $head = $this->headNote($encounter);

            if ($head !== null && $head->status === ClinicalNote::STATUS_SIGNED) {
                throw RadiologyReportException::alreadySigned();
            }

            // Author the prose — the radiologist's findings + impression. NOTHING is computed/auto-populated.
            return $this->notes->saveDraft(
                $encounter,
                $radiologist,
                ['objective' => $findings, 'assessment' => $impression],
                $actor,
                $head, // update the existing draft if present
            );
        });
    }

    /**
     * Sign (file) the study's report: sign the draft note (read-only), advance the study → reported (the G3
     * legal transition), and advance the reused Clinical `Order` → resulted (the report IS the result), all
     * atomically. The signed report then routes to the ordering clinician via the EXISTING order → review flow.
     * Gated `note.sign` (sign) + `radiology.study` (transition) + `order.manage` (recordResult) — the
     * radiologist holds all three. The study must be `acquired` (the radiographer's G3 step) to reach reported.
     */
    public function sign(User $actor, ImagingStudy $study): ClinicalNote
    {
        $this->assertSameTenant($study->tenant_id, 'imaging_study_id', $study->id);

        $encounter = $this->requireReportEncounter($study);
        $note = $this->headNote($encounter);
        if ($note === null || $note->status !== ClinicalNote::STATUS_DRAFT) {
            throw RadiologyReportException::noDraftToSign();
        }

        return DB::transaction(function () use ($actor, $study, $note): ClinicalNote {
            // Sign the report (the reused sign-and-lock discipline — note.sign; the note becomes immutable).
            $signed = $this->notes->sign($note, $actor);

            // Advance the study → reported (the G3 legal-only machine; requires acquired — the radiographer's step).
            $this->studies->transition($actor, $study, ImagingStudy::STATUS_REPORTED);

            // Advance the reused Clinical Order → resulted — the report IS the result (recordResult, order.manage).
            $radiologyOrder = RadiologyOrder::query()->findOrFail($study->radiology_order_id);
            $order = Order::query()->findOrFail($radiologyOrder->order_id);
            $value = trim((string) $signed->assessment) !== '' ? (string) $signed->assessment
                : (trim((string) $signed->objective) !== '' ? (string) $signed->objective : 'Radiology report filed');
            $this->orders->recordResult($order, ['value' => $value], $actor);

            return $signed;
        });
    }

    /**
     * Amend a SIGNED report — the reused sign-and-lock amendment (a NEW version via `supersedes_id` + a reason;
     * the original stays immutable). Gated `note.write` (in the reused `ClinicalNoteService::amend`).
     */
    public function amend(User $actor, ImagingStudy $study, StaffProfile $radiologist, ?string $findings, ?string $impression, string $reason): ClinicalNote
    {
        $this->assertSameTenant($study->tenant_id, 'imaging_study_id', $study->id);

        $encounter = $this->requireReportEncounter($study);
        $signed = $this->headNote($encounter);
        if ($signed === null || $signed->status !== ClinicalNote::STATUS_SIGNED) {
            throw RadiologyReportException::noSignedReportToAmend();
        }

        return $this->notes->amend($signed, ['objective' => $findings, 'assessment' => $impression], $reason, $radiologist, $actor);
    }

    /** The study's current (head) report note, or null. */
    public function reportFor(ImagingStudy $study): ?ClinicalNote
    {
        $link = ImagingStudyReport::query()->where('imaging_study_id', $study->id)->first();

        return $link !== null && $link->encounter !== null ? $this->headNote($link->encounter) : null;
    }

    /**
     * The report's version chain (reused `ClinicalNoteService::versionsFor`), oldest → newest, or empty.
     *
     * @return Collection<int, ClinicalNote>
     */
    public function versionsFor(ImagingStudy $study): Collection
    {
        $head = $this->reportFor($study);

        /** @var Collection<int, ClinicalNote> $empty */
        $empty = new Collection;

        return $head !== null ? $this->notes->versionsFor($head) : $empty;
    }

    /** Get-or-create the study's report Encounter (reused Clinical Encounter) + its radiology-side link. */
    private function reportEncounter(User $actor, ImagingStudy $study, StaffProfile $radiologist): Encounter
    {
        $link = ImagingStudyReport::query()->where('imaging_study_id', $study->id)->first();
        if ($link !== null && $link->encounter !== null) {
            return $link->encounter;
        }

        $patient = Patient::query()->findOrFail($study->patient_id);
        $branch = Branch::query()->firstOrFail(); // the report encounter is branch-attributed (the surgery/lab precedent)

        // A radiology report encounter is a reused Encounter (TYPE_CONSULTATION — NO radiology type is added to
        // Clinical); EncounterService enforces encounter.manage + the one-open-per-practitioner invariant.
        $encounter = $this->encounters->open($patient, $radiologist, $branch, null, Encounter::TYPE_CONSULTATION, $actor, 'Radiology report');

        ImagingStudyReport::query()->create([
            'patient_id' => $patient->id,
            'imaging_study_id' => $study->id,
            'encounter_id' => $encounter->id,
        ]);

        return $encounter;
    }

    private function requireReportEncounter(ImagingStudy $study): Encounter
    {
        $link = ImagingStudyReport::query()->where('imaging_study_id', $study->id)->with('encounter')->first();
        if ($link === null || $link->encounter === null) {
            throw RadiologyReportException::noDraftToSign();
        }

        return $link->encounter;
    }

    /** The head (latest version) `ClinicalNote` on the report encounter, or null. */
    private function headNote(Encounter $encounter): ?ClinicalNote
    {
        return ClinicalNote::query()
            ->where('encounter_id', $encounter->id)
            ->orderByDesc('version')
            ->orderByDesc('created_at')
            ->first();
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
