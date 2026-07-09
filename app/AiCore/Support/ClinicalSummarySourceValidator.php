<?php

namespace App\AiCore\Support;

use Modules\AiCore\Exceptions\AiCoreException;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Medication;
use Modules\Clinical\Models\Problem;
use Modules\Clinical\Models\Vital;

class ClinicalSummarySourceValidator
{
    private const NOTE_SECTIONS = [
        ClinicalNote::SECTION_SUBJECTIVE,
        ClinicalNote::SECTION_OBJECTIVE,
        ClinicalNote::SECTION_ASSESSMENT,
        ClinicalNote::SECTION_PLAN,
    ];

    /**
     * The Summary agent is extractive only: every displayed line must point to
     * an existing patient-owned source row/field. Unsourced generated prose is
     * rejected instead of being shown or inserted into a note.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function validate(string $patientId, array $lines): void
    {
        foreach ($lines as $line) {
            if (trim((string) ($line['text'] ?? '')) === '') {
                throw new AiCoreException('Clinical summary lines require text.');
            }

            $source = $line['source'] ?? null;
            if (! is_array($source)) {
                throw new AiCoreException('Clinical summary lines require a source reference.');
            }

            $type = (string) ($source['type'] ?? '');
            $id = (string) ($source['id'] ?? '');

            match ($type) {
                'clinical_note' => $this->assertNoteSource($patientId, $id, (string) ($source['section'] ?? '')),
                'problem' => $this->assertRowSource(Problem::class, $patientId, $id),
                'medication' => $this->assertRowSource(Medication::class, $patientId, $id),
                'vital' => $this->assertRowSource(Vital::class, $patientId, $id),
                default => throw new AiCoreException('Clinical summary source type is not allowed.'),
            };
        }
    }

    private function assertNoteSource(string $patientId, string $id, string $section): void
    {
        if (! in_array($section, self::NOTE_SECTIONS, true)) {
            throw new AiCoreException('Clinical summary note source requires a SOAP section.');
        }

        $note = ClinicalNote::query()
            ->whereKey($id)
            ->where('patient_id', $patientId)
            ->where('status', ClinicalNote::STATUS_SIGNED)
            ->first();

        if (! $note instanceof ClinicalNote || trim((string) $note->getAttribute($section)) === '') {
            throw new AiCoreException('Clinical summary note source does not resolve.');
        }
    }

    /**
     * @param  class-string<Problem|Medication|Vital>  $model
     */
    private function assertRowSource(string $model, string $patientId, string $id): void
    {
        if (! $model::query()->whereKey($id)->where('patient_id', $patientId)->exists()) {
            throw new AiCoreException('Clinical summary row source does not resolve.');
        }
    }
}
