<?php

namespace App\AiCore\Tools;

use InvalidArgumentException;
use Modules\AiCore\Contracts\AiTool;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolDefinition;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;
use Modules\Platform\Models\User;

/**
 * Classification is a SUGGESTION (D-G5): category + patient match + filing
 * action wait in the approval queue. Nothing is filed until a human confirms;
 * then the deterministic DocumentService performs the filing. The patient
 * match is NEVER auto-applied — a misfiled document in a patient chart is a
 * serious harm, so execute() only ever applies the CATEGORY and refuses to
 * move a document between patients under any circumstances.
 */
class ClassifyDocumentTool implements AiTool
{
    public function __construct(private readonly DocumentService $documents) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            key: 'comms.classify_document',
            name: 'Classify an inbound document',
            category: ToolDefinition::CATEGORY_OPERATIONAL,
            permission: 'note.write',
            schema: [
                'type' => 'object',
                'required' => ['document_id'],
                'properties' => [
                    'document_id' => ['type' => 'string'],
                    'classification' => ['type' => 'object'],
                ],
            ],
            reversible: true,
            autonomyCeiling: AutonomyPolicy::SUGGEST,
        );
    }

    public function preview(array $input): array
    {
        $document = Document::query()->whereKey((string) ($input['document_id'] ?? ''))->firstOrFail();
        $document->auditRead(['surface' => 'inbox_agent_classification']);

        $classification = is_array($input['classification'] ?? null)
            ? $input['classification']
            : $this->suggest($document);

        $category = (string) ($classification['category'] ?? Document::CATEGORY_OTHER);

        if (! in_array($category, Document::categories(), true)) {
            throw new AiCoreException('Suggested document category is not allowed.');
        }

        return [
            'document_id' => $document->id,
            'current_category' => $document->category,
            'suggested_category' => $category,
            // The patient match is displayed for HUMAN verification only.
            'suggested_patient_id' => (string) ($classification['patient_id'] ?? $document->patient_id),
            'patient_match_auto_applied' => false,
            'filing_action' => 'set_category',
            'requires_human_confirmation' => true,
        ];
    }

    public function execute(array $input, ?User $actor = null): array
    {
        if ($actor === null) {
            throw new InvalidArgumentException('A human confirmer is required.');
        }

        $suggestion = $this->preview($input);
        $document = Document::query()->whereKey($suggestion['document_id'])->firstOrFail();

        // NEVER move a document between patients here — even if the suggested
        // match disagrees, only the category is filed and the mismatch stays a
        // suggestion for a human to act on through the normal chart flows.
        $patientUnchanged = $document->patient_id;

        $filed = $this->documents->reclassify($document, (string) $suggestion['suggested_category'], $actor);

        return [
            'document_id' => $filed->id,
            'category' => $filed->category,
            'patient_id' => $filed->patient_id,
            'patient_unchanged' => $filed->patient_id === $patientUnchanged,
            'executed_via' => DocumentService::class,
        ];
    }

    /**
     * Deterministic suggestion standing in for the LLM.
     *
     * @return array<string, mixed>
     */
    private function suggest(Document $document): array
    {
        $name = mb_strtolower($document->original_filename.' '.$document->title);

        $category = match (true) {
            str_contains($name, 'result') || str_contains($name, 'lab') => Document::CATEGORY_RESULT,
            str_contains($name, 'consent') => Document::CATEGORY_CONSENT,
            str_contains($document->mime_type, 'image') => Document::CATEGORY_IMAGE,
            str_contains($name, 'letter') || str_contains($name, 'referral') => Document::CATEGORY_LETTER,
            default => Document::CATEGORY_OTHER,
        };

        return ['category' => $category, 'patient_id' => $document->patient_id];
    }
}
