<?php

namespace App\AiCore\Support;

use Illuminate\Support\Carbon;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\TariffResolver;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitNote;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;
use Throwable;

/**
 * Suggestion engine for the Billing agent's code-mapping tool.
 *
 * The LLM only ever DRAFTS suggestions (simulated here by explicit
 * `suggestions` input or the deterministic generator). Every suggestion —
 * generated or supplied — passes the same IN-CODE gates before it may reach
 * the approval queue:
 *   - the source must be a signed-note encounter or a completed visit of the
 *     current tenant;
 *   - the code must resolve through the deterministic TariffResolver for the
 *     catalog version valid on the SERVICE DATE;
 *   - the rationale must be source-linked: its quoted text must resolve to
 *     real documented text of that patient (unsourced suggestions are
 *     REJECTED, mirroring D.8);
 *   - agent-supplied prices are IGNORED — preview prices come from the
 *     resolved tariff item and capture re-resolves the tariff itself.
 */
class BillingCodeSuggestionEngine
{
    public function __construct(
        private readonly TariffResolver $tariffs,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function suggest(array $input): array
    {
        $sourceType = (string) ($input['source_type'] ?? '');
        $sourceId = (string) ($input['source_id'] ?? '');

        [$patient, $serviceDate, $documents] = match ($sourceType) {
            'encounter' => $this->encounterSource($sourceId),
            'visit' => $this->visitSource($sourceId),
            default => throw new AiCoreException('Billing suggestions require an encounter or visit source.'),
        };

        if ($documents === []) {
            throw new AiCoreException('Billing suggestions require documented text on the source.');
        }

        $suggestions = array_key_exists('suggestions', $input)
            ? $this->validateExplicit(array_values((array) $input['suggestions']), $documents, $serviceDate)
            : $this->generate($documents, $serviceDate);

        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'patient_id' => $patient->id,
            'service_date' => $serviceDate,
            'suggestions' => $suggestions,
            'suggestion_count' => count($suggestions),
            'prices_from' => TariffResolver::class,
            'captures_on_approval' => count($suggestions) > 0,
            'explanation' => 'Every suggestion is source-linked to documented text and priced by the deterministic TariffResolver at the service date; agent-supplied prices are ignored.',
        ];
    }

    /**
     * @return array{0: Patient, 1: string, 2: list<array{type: string, id: string, section: ?string, text: string}>}
     */
    private function encounterSource(string $encounterId): array
    {
        $encounter = Encounter::query()->whereKey($encounterId)->firstOrFail();
        $patient = Patient::query()->whereKey($encounter->patient_id)->firstOrFail();
        $patient->auditRead(['surface' => 'billing_agent']);

        $notes = ClinicalNote::query()
            ->where('encounter_id', $encounter->id)
            ->where('status', ClinicalNote::STATUS_SIGNED)
            ->orderBy('signed_at')
            ->get();

        if ($notes->isEmpty()) {
            throw new AiCoreException('Billing suggestions require a SIGNED encounter note.');
        }

        $documents = [];
        $notes->each(function (ClinicalNote $note) use (&$documents): void {
            $note->auditRead(['surface' => 'billing_agent']);

            foreach ([
                ClinicalNote::SECTION_SUBJECTIVE,
                ClinicalNote::SECTION_OBJECTIVE,
                ClinicalNote::SECTION_ASSESSMENT,
                ClinicalNote::SECTION_PLAN,
            ] as $section) {
                $text = trim((string) $note->getAttribute($section));
                if ($text !== '') {
                    $documents[] = [
                        'type' => 'clinical_note',
                        'id' => $note->id,
                        'section' => $section,
                        'text' => $text,
                    ];
                }
            }
        });

        return [$patient, $encounter->started_at->toDateString(), $documents];
    }

    /**
     * @return array{0: Patient, 1: string, 2: list<array{type: string, id: string, section: ?string, text: string}>}
     */
    private function visitSource(string $visitId): array
    {
        $visit = Visit::query()->whereKey($visitId)->firstOrFail();

        if ($visit->status !== Visit::STATUS_COMPLETED) {
            throw new AiCoreException('Billing suggestions require a COMPLETED visit.');
        }

        $patient = Patient::query()->whereKey($visit->patient_id)->firstOrFail();
        $patient->auditRead(['surface' => 'billing_agent']);

        $documents = VisitNote::query()
            ->where('visit_id', $visit->id)
            ->orderBy('recorded_at')
            ->get()
            ->map(fn (VisitNote $note): array => [
                'type' => 'visit_note',
                'id' => $note->id,
                'section' => null,
                'text' => trim($note->body),
            ])
            ->filter(fn (array $document): bool => $document['text'] !== '')
            ->values()
            ->all();

        $serviceDate = Carbon::parse((string) ($visit->checked_in_at ?? $visit->scheduled_start_at))->toDateString();

        return [$patient, $serviceDate, $documents];
    }

    /**
     * Deterministic draft generator standing in for the LLM: a tariff item is
     * suggested only when its documented description literally appears in the
     * source text, so every rationale is source-linked by construction.
     *
     * @param  list<array{type: string, id: string, section: ?string, text: string}>  $documents
     * @return list<array<string, mixed>>
     */
    private function generate(array $documents, string $serviceDate): array
    {
        $tenant = $this->currentTenant();
        $suggestions = [];

        foreach ($this->activeItemsFor($tenant, $serviceDate) as $item) {
            foreach ($documents as $document) {
                if (mb_stripos($document['text'], $item['description']) === false) {
                    continue;
                }

                $suggestions[] = $this->buildSuggestion(
                    $tenant,
                    $item['code'],
                    1,
                    $document,
                    $item['description'],
                    $serviceDate,
                );

                break;
            }
        }

        return $suggestions;
    }

    /**
     * @param  list<mixed>  $rawSuggestions
     * @param  list<array{type: string, id: string, section: ?string, text: string}>  $documents
     * @return list<array<string, mixed>>
     */
    private function validateExplicit(array $rawSuggestions, array $documents, string $serviceDate): array
    {
        $tenant = $this->currentTenant();
        $suggestions = [];

        foreach ($rawSuggestions as $rawSuggestion) {
            if (! is_array($rawSuggestion)) {
                throw new AiCoreException('Billing suggestion payload is invalid.');
            }

            $code = (string) ($rawSuggestion['code'] ?? '');
            $quantity = max(1, (int) ($rawSuggestion['quantity'] ?? 1));
            $rationale = is_array($rawSuggestion['rationale'] ?? null) ? $rawSuggestion['rationale'] : [];
            $sourceText = trim((string) ($rationale['source_text'] ?? ''));

            if ($sourceText === '') {
                throw new AiCoreException('Billing suggestions require a source-linked rationale.');
            }

            $document = $this->resolveSourceDocument($documents, $rationale, $sourceText);

            if ($document === null) {
                throw new AiCoreException(
                    "Billing suggestion for code {$code} is unsourced: its rationale does not resolve to documented text of this patient.",
                );
            }

            $suggestions[] = $this->buildSuggestion($tenant, $code, $quantity, $document, $sourceText, $serviceDate);
        }

        return $suggestions;
    }

    /**
     * A rationale resolves only when its quoted text is literally present in a
     * real documented text of this source (optionally pinned to a specific
     * document id/section by the rationale).
     *
     * @param  list<array{type: string, id: string, section: ?string, text: string}>  $documents
     * @param  array<string, mixed>  $rationale
     * @return array{type: string, id: string, section: ?string, text: string}|null
     */
    private function resolveSourceDocument(array $documents, array $rationale, string $sourceText): ?array
    {
        $pinnedId = isset($rationale['source_id']) ? (string) $rationale['source_id'] : null;
        $pinnedSection = isset($rationale['source_section']) ? (string) $rationale['source_section'] : null;

        foreach ($documents as $document) {
            if ($pinnedId !== null && $document['id'] !== $pinnedId) {
                continue;
            }

            if ($pinnedSection !== null && $document['section'] !== $pinnedSection) {
                continue;
            }

            if (mb_stripos($document['text'], $sourceText) !== false) {
                return $document;
            }
        }

        return null;
    }

    /**
     * @param  array{type: string, id: string, section: ?string, text: string}  $document
     * @return array<string, mixed>
     */
    private function buildSuggestion(
        Tenant $tenant,
        string $code,
        int $quantity,
        array $document,
        string $sourceText,
        string $serviceDate,
    ): array {
        try {
            $item = $this->tariffs->resolve($tenant, $code, $serviceDate);
        } catch (Throwable $exception) {
            throw new AiCoreException(
                "Billing suggestion code {$code} does not resolve in the tariff catalog valid on {$serviceDate}: {$exception->getMessage()}",
            );
        }

        return [
            'code' => $item->code,
            'description' => $item->description,
            'quantity' => $quantity,
            'unit_price_minor' => $item->unit_price_minor,
            'vat_rate_bp' => $item->vat_rate_bp,
            'tariff_item_id' => $item->id,
            'rationale' => [
                'source_type' => $document['type'],
                'source_id' => $document['id'],
                'source_section' => $document['section'],
                'source_text' => $sourceText,
            ],
        ];
    }

    /**
     * @return list<array{code: string, description: string}>
     */
    private function activeItemsFor(Tenant $tenant, string $serviceDate): array
    {
        $items = [];

        foreach (TariffItem::query()->where('active', true)->orderBy('code')->get() as $item) {
            try {
                $resolved = $this->tariffs->resolve($tenant, $item->code, $serviceDate);
            } catch (Throwable) {
                continue;
            }

            if ($resolved->id === $item->id) {
                $items[] = ['code' => $item->code, 'description' => $item->description];
            }
        }

        return $items;
    }

    private function currentTenant(): Tenant
    {
        $tenant = $this->tenantContext->current();

        if (! $tenant instanceof Tenant) {
            throw new AiCoreException('Billing suggestions require a tenant context.');
        }

        return $tenant;
    }
}
