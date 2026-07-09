<?php

namespace App\AiCore\Tools;

use App\AiCore\Support\ClinicalSummarySourceValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Services\AiInteractionRecorder;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Medication;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Vital;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\User;

class ClinicalSummaryTool implements AiTool
{
    public function __construct(private readonly ClinicalSummarySourceValidator $validator) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'clinical.summarize_since_last_visit',
            name: 'Summarize since last visit',
            category: ToolDefinition::CATEGORY_CLINICAL,
            permission: 'note.write',
            schema: [
                'type' => 'object',
                'required' => ['patient_id', 'from', 'to'],
                'properties' => [
                    'patient_id' => ['type' => 'string'],
                    'from' => ['type' => 'string'],
                    'to' => ['type' => 'string'],
                ],
            ],
            reversible: true,
            autonomyCeiling: AutonomyPolicy::SUGGEST,
        );
    }

    public function preview(array $input): array
    {
        return $this->payload($input);
    }

    public function execute(array $input, ?User $actor = null): array
    {
        return $this->payload($input);
    }

    /**
     * ABSOLUTE CONSTRAINT: this tool is EXTRACTIVE. It surfaces existing
     * patient-record text/fields with source references only. It does not
     * interpret, diagnose, infer, prioritize clinically, or add unsourced text.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payload(array $input): array
    {
        $patient = Patient::query()->whereKey((string) $input['patient_id'])->firstOrFail();

        if (! Gate::allows('patient.view')) {
            throw new AiCoreException('Clinical summary requires patient.view.');
        }

        $patient->auditRead(['surface' => 'clinical_summary_agent']);

        $from = CarbonImmutable::parse((string) $input['from'])->startOfDay();
        $to = CarbonImmutable::parse((string) $input['to'])->endOfDay();
        $lines = [
            ...$this->noteLines($patient, $from, $to),
            ...$this->problemLines($patient, $from, $to),
            ...$this->medicationLines($patient, $from, $to),
            ...$this->vitalLines($patient, $from, $to),
        ];

        $this->validator->validate($patient->id, $lines);

        return [
            'patient_id' => $patient->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'label' => AiInteractionRecorder::LABEL,
            'human_handoff' => true,
            'writes_to_record' => false,
            'lines' => $lines,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function noteLines(Patient $patient, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lines = [];
        $sections = [
            ClinicalNote::SECTION_SUBJECTIVE,
            ClinicalNote::SECTION_OBJECTIVE,
            ClinicalNote::SECTION_ASSESSMENT,
            ClinicalNote::SECTION_PLAN,
        ];

        ClinicalNote::query()
            ->where('patient_id', $patient->id)
            ->where('status', ClinicalNote::STATUS_SIGNED)
            ->whereBetween('signed_at', [$from, $to])
            ->orderBy('signed_at')
            ->get()
            ->each(function (ClinicalNote $note) use (&$lines, $sections): void {
                $note->auditRead(['surface' => 'clinical_summary_agent']);

                foreach ($sections as $section) {
                    $text = trim((string) $note->getAttribute($section));
                    if ($text === '') {
                        continue;
                    }

                    $lines[] = [
                        'text' => $text,
                        'source' => [
                            'type' => 'clinical_note',
                            'id' => $note->id,
                            'section' => $section,
                            'label' => 'clinical_note:'.$note->id.':'.$section,
                            'url' => route('clinical.notes.edit', $note->id),
                        ],
                    ];
                }
            });

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function problemLines(Patient $patient, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Problem::query()
            ->where('patient_id', $patient->id)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get()
            ->map(function (Problem $problem): array {
                $problem->auditRead(['surface' => 'clinical_summary_agent']);

                return [
                    'text' => trim($problem->description),
                    'source' => [
                        'type' => 'problem',
                        'id' => $problem->id,
                        'label' => 'problem:'.$problem->id,
                        'url' => null,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medicationLines(Patient $patient, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Medication::query()
            ->where('patient_id', $patient->id)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get()
            ->map(function (Medication $medication): array {
                $medication->auditRead(['surface' => 'clinical_summary_agent']);

                return [
                    'text' => trim(implode(' ', array_filter([
                        $medication->name,
                        $medication->dose_text,
                        $medication->route,
                        $medication->frequency_text,
                        $medication->status,
                    ]))),
                    'source' => [
                        'type' => 'medication',
                        'id' => $medication->id,
                        'label' => 'medication:'.$medication->id,
                        'url' => null,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vitalLines(Patient $patient, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Vital::query()
            ->where('patient_id', $patient->id)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get()
            ->map(function (Vital $vital): array {
                $vital->auditRead(['surface' => 'clinical_summary_agent']);

                return [
                    'text' => trim(implode(' ', array_filter([
                        'recorded_at '.$vital->recorded_at->toDateTimeString(),
                        $vital->systolic !== null || $vital->diastolic !== null ? 'bp '.($vital->systolic ?? '-').'/'.($vital->diastolic ?? '-') : null,
                        $vital->heart_rate !== null ? 'heart_rate '.$vital->heart_rate : null,
                        $vital->temperature_c !== null ? 'temperature_c '.$vital->temperature_c : null,
                        $vital->spo2 !== null ? 'spo2 '.$vital->spo2 : null,
                        $vital->weight_g !== null ? 'weight_g '.$vital->weight_g : null,
                        $vital->height_mm !== null ? 'height_mm '.$vital->height_mm : null,
                    ]))),
                    'source' => [
                        'type' => 'vital',
                        'id' => $vital->id,
                        'label' => 'vital:'.$vital->id,
                        'url' => null,
                    ],
                ];
            })
            ->values()
            ->all();
    }
}
