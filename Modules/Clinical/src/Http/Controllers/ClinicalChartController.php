<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\CarePlan;
use Modules\Clinical\Models\CarePlanGoal;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Models\Encounter;
use Modules\Clinical\Models\Medication;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Models\OrderResult;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\RecallRule;
use Modules\Clinical\Models\Referral;
use Modules\Clinical\Models\Vital;
use Modules\Clinical\Services\ClinicalNoteService;
use Modules\Clinical\Services\OrderService;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\User;

class ClinicalChartController
{
    public function __invoke(Request $request, string $patient, ClinicalNoteService $notes, OrderService $orders): Response
    {
        Gate::authorize('patient.view');

        $record = Patient::query()->whereKey($patient)->firstOrFail();
        $actor = $request->user();
        $record->auditRead(['surface' => 'clinical_chart']);

        $noteRecords = ClinicalNote::query()
            ->where('patient_id', $record->id)
            ->with(['author'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Clinical/Chart', [
            'patient' => [
                'id' => $record->id,
                'mrn' => $record->mrn,
                'name' => trim($record->first_name.' '.$record->last_name),
                'date_of_birth' => $record->date_of_birth->toDateString(),
                'sex' => $record->sex,
                'status' => $record->status,
            ],
            'encounters' => Encounter::query()
                ->where('patient_id', $record->id)
                ->orderByDesc('started_at')
                ->get()
                ->map(fn (Encounter $encounter): array => [
                    'id' => $encounter->id,
                    'type' => $encounter->type,
                    'status' => $encounter->status,
                    'started_at' => $encounter->started_at->toDateTimeString(),
                    'ended_at' => $encounter->ended_at?->toDateTimeString(),
                ])
                ->all(),
            'notes' => $noteRecords
                ->whereNull('supersedes_id')
                ->map(fn (ClinicalNote $note): array => $this->noteSummary($note, $notes))
                ->values()
                ->all(),
            'problems' => Problem::query()
                ->where('patient_id', $record->id)
                ->orderByDesc('recorded_at')
                ->get()
                ->map(fn (Problem $problem): array => [
                    'id' => $problem->id,
                    'description' => $problem->description,
                    'code' => $problem->code,
                    'status' => $problem->status,
                    'recorded_at' => $problem->recorded_at->toDateTimeString(),
                    'resolved_at' => $problem->resolved_at?->toDateTimeString(),
                ])
                ->all(),
            'allergies' => Allergy::query()
                ->where('patient_id', $record->id)
                ->orderBy('substance')
                ->get()
                ->map(fn (Allergy $allergy): array => [
                    'id' => $allergy->id,
                    'substance' => $allergy->substance,
                    'reaction' => $allergy->reaction,
                    'severity' => $allergy->severity,
                    'status' => $allergy->status,
                    'verified_at' => $allergy->verified_at?->toDateTimeString(),
                ])
                ->all(),
            'vitals' => Vital::query()
                ->where('patient_id', $record->id)
                ->orderByDesc('recorded_at')
                ->get()
                ->map(fn (Vital $vital): array => [
                    'id' => $vital->id,
                    'recorded_at' => $vital->recorded_at->toDateTimeString(),
                    'systolic' => $vital->systolic,
                    'diastolic' => $vital->diastolic,
                    'heart_rate' => $vital->heart_rate,
                    'temperature_c' => $vital->temperature_c,
                    'spo2' => $vital->spo2,
                    'weight_g' => $vital->weight_g,
                    'height_mm' => $vital->height_mm,
                    'extra' => $vital->extra,
                ])
                ->all(),
            'medications' => Medication::query()
                ->where('patient_id', $record->id)
                ->orderByDesc('recorded_at')
                ->get()
                ->map(fn (Medication $medication): array => [
                    'id' => $medication->id,
                    'name' => $medication->name,
                    'dose_text' => $medication->dose_text,
                    'route' => $medication->route,
                    'frequency_text' => $medication->frequency_text,
                    'status' => $medication->status,
                    'started_on' => $medication->started_on->toDateString(),
                    'ended_on' => $medication->ended_on?->toDateString(),
                ])
                ->all(),
            'documents' => Document::query()
                ->where('patient_id', $record->id)
                ->orderByDesc('uploaded_at')
                ->get()
                ->map(fn (Document $document): array => [
                    'id' => $document->id,
                    'category' => $document->category,
                    'title' => $document->title,
                    'original_filename' => $document->original_filename,
                    'uploaded_at' => $document->uploaded_at->toDateTimeString(),
                    'shared_with_patient' => $document->shared_with_patient,
                    'download_url' => route('clinical.documents.download', $document->id),
                ])
                ->all(),
            'carePlans' => CarePlan::query()
                ->where('patient_id', $record->id)
                ->orderByDesc('started_on')
                ->get()
                ->map(fn (CarePlan $carePlan): array => $this->carePlanSummary($carePlan))
                ->all(),
            'referrals' => Referral::query()
                ->where('patient_id', $record->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Referral $referral): array => $this->referralSummary($referral))
                ->all(),
            'recalls' => Recall::query()
                ->where('patient_id', $record->id)
                ->orderBy('due_on')
                ->get()
                ->map(fn (Recall $recall): array => $this->recallSummary($recall))
                ->all(),
            'orders' => $actor instanceof User
                ? $orders->chartOrders($record, $actor)->map(fn (Order $order): array => $this->orderSummary($order))->all()
                : [],
            'orderableItems' => Gate::allows('order.manage')
                ? OrderableItem::query()
                    ->where('active', true)
                    ->orderBy('category')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (OrderableItem $i): array => [
                        'id' => $i->id,
                        'category' => $i->category,
                        'code' => $i->code,
                        'name' => $i->name,
                    ])
                    ->all()
                : [],
            'aiSummary' => $this->aiSummaryDraft($record),
            'actions' => [
                'can_view' => Gate::allows('patient.view'),
                'can_write_notes' => Gate::allows('note.write'),
                'can_sign_notes' => Gate::allows('note.sign'),
                'can_order' => Gate::allows('order.manage'),
                'summary_draft_url' => route('clinical.summary.draft', $record->id),
                'order_place_url' => route('clinical.orders.place'),
                'order_transition_url' => route('clinical.orders.transition'),
                'order_result_url' => route('clinical.orders.result'),
                'order_review_url' => route('clinical.orders.review'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function noteSummary(ClinicalNote $note, ClinicalNoteService $notes): array
    {
        return [
            'id' => $note->id,
            'status' => $note->status,
            'version' => $note->version,
            'author_name' => $this->staffName($this->authorFor($note)),
            'created_at' => $note->created_at?->toDateTimeString(),
            'signed_at' => $note->signed_at?->toDateTimeString(),
            'edit_url' => route('clinical.notes.edit', $note->id),
            'versions' => $notes->versionsFor($note)
                ->map(fn (ClinicalNote $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'status' => $version->status,
                    'author_name' => $this->staffName($this->authorFor($version)),
                    'created_at' => $version->created_at?->toDateTimeString(),
                    'signed_at' => $version->signed_at?->toDateTimeString(),
                    'amendment_reason' => $version->amendment_reason,
                    'edit_url' => route('clinical.notes.edit', $version->id),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function carePlanSummary(CarePlan $carePlan): array
    {
        $carePlan->auditRead(['surface' => 'clinical_chart']);

        return [
            'id' => $carePlan->id,
            'title' => $carePlan->title,
            'status' => $carePlan->status,
            'started_on' => $carePlan->started_on->toDateString(),
            'ended_on' => $carePlan->ended_on?->toDateString(),
            'goals' => CarePlanGoal::query()
                ->where('care_plan_id', $carePlan->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (CarePlanGoal $goal): array => [
                    'id' => $goal->id,
                    'description' => $goal->description,
                    'target_date' => $goal->target_date?->toDateString(),
                    'status' => $goal->status,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function referralSummary(Referral $referral): array
    {
        $referral->auditRead(['surface' => 'clinical_chart']);

        return [
            'id' => $referral->id,
            'direction' => $referral->direction,
            'status' => $referral->status,
            'specialty' => $referral->specialty,
            'reason' => $referral->reason,
            'to_provider_name' => $referral->to_provider_name,
            'from_provider_name' => $referral->from_provider_name,
            'to_branch_id' => $referral->to_branch_id,
            'sent_at' => $referral->sent_at?->toDateTimeString(),
            'responded_at' => $referral->responded_at?->toDateTimeString(),
            'notes' => $referral->notes,
        ];
    }

    /**
     * Orders are shown RAW and neutral — the result_value is displayed as
     * entered, with NO range/flag/abnormal/colour/score. "Reviewed" is a human
     * attestation, not a system judgment.
     *
     * @return array<string, mixed>
     */
    private function orderSummary(Order $order): array
    {
        return [
            'id' => $order->id,
            'item' => $order->orderableItem?->name,
            'category' => $order->orderableItem?->category,
            'priority' => $order->priority,
            'status' => $order->status,
            'clinical_note' => $order->clinical_note,
            'ordered_at' => $order->ordered_at->toDateTimeString(),
            'cancelled_reason' => $order->cancelled_reason,
            'reviewed_at' => $order->reviewed_at?->toDateTimeString(),
            'reviewed_by' => $order->reviewed_by,
            'results' => $order->results->map(fn (OrderResult $r): array => [
                'id' => $r->id,
                'value' => $r->result_value, // raw, as entered
                'has_document' => $r->result_document_id !== null,
                'source' => $r->source,
                'entered_at' => $r->entered_at->toDateTimeString(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recallSummary(Recall $recall): array
    {
        $recall->auditRead(['surface' => 'clinical_chart']);
        $rule = RecallRule::query()->whereKey($recall->rule_id)->firstOrFail();

        return [
            'id' => $recall->id,
            'rule_id' => $recall->rule_id,
            'rule_name' => $rule->name,
            'due_on' => $recall->due_on->toDateString(),
            'status' => $recall->status,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function aiSummaryDraft(Patient $patient): ?array
    {
        $draft = session('clinical_summary_draft');

        if (! is_array($draft)) {
            return null;
        }

        if (($draft['patient_id'] ?? null) !== $patient->id) {
            return null;
        }

        $lines = $draft['lines'] ?? [];
        if (! is_array($lines)) {
            $lines = [];
        }

        return [
            'status' => (string) ($draft['status'] ?? ''),
            'label' => (string) ($draft['label'] ?? ''),
            'human_handoff' => (bool) ($draft['human_handoff'] ?? true),
            'action_id' => $draft['action_id'] ?? null,
            'lines' => $lines,
            'insert_url' => route('clinical.summary.insert', $patient->id),
        ];
    }

    private function staffName(StaffProfile $profile): string
    {
        return $profile->display_name !== '' ? $profile->display_name : trim($profile->first_name.' '.$profile->last_name);
    }

    private function authorFor(ClinicalNote $note): StaffProfile
    {
        return StaffProfile::query()->whereKey($note->author_id)->firstOrFail();
    }
}
