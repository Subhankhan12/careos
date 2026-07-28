<?php

namespace Modules\Surgery\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\SurgicalChecklistException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalChecklist;
use Modules\Surgery\Models\SurgicalChecklistItem;
use Modules\Surgery\Models\SurgicalChecklistTemplateItem;

/**
 * The WHO Surgical Safety Checklist (SURGERY.G3) — RECORDED, NOT ENFORCED (docs/HOSPITAL-PHASE5-SURGERY-MAP.md
 * §2.4). The team confirms items across the three WHO phases; CareOS records each confirmation (append-only).
 *
 * THE FENCE LINE (the crux): this service RECORDS checklist completion and computes NO safety judgment. It
 * NEVER touches the case status or its transitions — the G2 lifecycle machine is entirely independent of the
 * checklist, so a surgery can proceed regardless of checklist completeness (a blocking checklist would be a
 * safety-enforcement medical device). The read model exposes only a FACTUAL "checked / total" count, never a
 * "passed / safe-to-proceed" verdict. Template items are tenant-authored (the standard WHO set, editable).
 */
class SurgicalChecklistService
{
    /**
     * The standard, freely-published WHO Surgical Safety Checklist — seeded as an EDITABLE tenant starter (NOT
     * a licensed/proprietary set), the formulary/bed-day discipline. The tenant may add / deactivate items.
     *
     * @var array<string, list<string>>
     */
    public const STANDARD = [
        SurgicalChecklistTemplateItem::PHASE_SIGN_IN => [
            'Patient has confirmed identity, site, procedure, and consent',
            'Surgical site is marked (or not applicable)',
            'Anaesthesia safety check is complete',
            'Pulse oximeter is on the patient and functioning',
            'Known allergy reviewed',
            'Difficult airway or aspiration risk reviewed',
            'Risk of >500 ml blood loss reviewed',
        ],
        SurgicalChecklistTemplateItem::PHASE_TIME_OUT => [
            'All team members have introduced themselves by name and role',
            'Surgeon, anaesthetist, and nurse confirm patient, site, and procedure',
            'Anticipated critical events have been reviewed',
            'Antibiotic prophylaxis given within the last 60 minutes (or not applicable)',
            'Essential imaging is displayed (or not applicable)',
        ],
        SurgicalChecklistTemplateItem::PHASE_SIGN_OUT => [
            'Nurse confirms the procedure name recorded',
            'Instrument, sponge, and needle counts are correct',
            'Specimen labelling is confirmed',
            'Any equipment problems have been addressed',
            'Key concerns for recovery and management reviewed',
        ],
    ];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Seed / top-up the tenant's editable WHO template (idempotent). Gated `surgery.manage` (an OR-config
     * act). The template is the tenant's own editable set — no licensed content.
     */
    public function seedTemplate(User $actor): int
    {
        Gate::forUser($actor)->authorize('surgery.manage');

        return $this->seedStandardItems();
    }

    /**
     * Get-or-create the per-case checklist container (auto-seeding the tenant template on first use). Gated
     * `note.write` (the surgical team). Does NOT touch the case status.
     */
    public function openChecklist(User $actor, SurgicalCase $case): SurgicalChecklist
    {
        Gate::forUser($actor)->authorize('note.write');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);

        return $this->resolveChecklist($case);
    }

    /**
     * RECORD a team member's confirmation of one checklist item (append-only). Gated `note.write`. `checked`
     * is the member's fact; a correction is a NEW confirmation (with a note), never an edit. This RECORDS —
     * it changes no case state and computes no safety judgment.
     */
    public function confirmItem(
        User $actor,
        SurgicalCase $case,
        string $templateItemId,
        bool $checked,
        ?string $note = null,
    ): SurgicalChecklistItem {
        Gate::forUser($actor)->authorize('note.write');
        $this->assertSameTenant($case->tenant_id, 'surgical_case_id', $case->id);

        $checklist = $this->resolveChecklist($case);
        $template = SurgicalChecklistTemplateItem::query()->find($templateItemId);
        if ($template === null) {
            throw SurgicalChecklistException::unknownTemplateItem($templateItemId);
        }

        return SurgicalChecklistItem::query()->create([
            'surgical_checklist_id' => $checklist->id,
            'patient_id' => $case->patient_id,
            'template_item_id' => $template->id,
            'phase' => $template->phase,   // snapshot
            'label' => $template->label,   // snapshot
            'checked' => $checked,
            'confirmed_by' => $actor->id,
            'confirmed_at' => Carbon::now(),
            'note' => $note,
        ]);
    }

    /**
     * The FACTUAL read model: per WHO phase, the active template items with their latest confirmation state +
     * a plain "checked / total" count. NO verdict, NO "safe-to-proceed" — a count is a fact, not a judgment.
     *
     * @return array{checklist_id: string|null, phases: list<array<string, mixed>>}
     */
    public function forCase(SurgicalCase $case): array
    {
        // Ensure the tenant's editable WHO template exists so the full checklist is always visible (idempotent).
        if (SurgicalChecklistTemplateItem::query()->where('active', true)->doesntExist()) {
            $this->seedStandardItems();
        }

        $checklist = SurgicalChecklist::query()->where('surgical_case_id', $case->id)->first();

        $latest = [];
        if ($checklist !== null) {
            foreach (SurgicalChecklistItem::query()->where('surgical_checklist_id', $checklist->id)->orderBy('confirmed_at')->orderBy('id')->get() as $confirmation) {
                $latest[(string) $confirmation->template_item_id] = $confirmation; // asc → last wins
            }
        }

        $phases = [];
        foreach (SurgicalChecklistTemplateItem::PHASES as $phase) {
            $items = SurgicalChecklistTemplateItem::query()->where('phase', $phase)->where('active', true)
                ->orderBy('display_order')->orderBy('label')->get()
                ->map(function (SurgicalChecklistTemplateItem $t) use ($latest): array {
                    // array_key_exists (not `?? null`) so the "no confirmation yet" case is a genuine null.
                    $confirmation = array_key_exists($t->id, $latest) ? $latest[$t->id] : null;

                    return [
                        'template_item_id' => $t->id,
                        'label' => $t->label,
                        'checked' => $confirmation !== null && $confirmation->checked,
                        'confirmed_at' => $confirmation?->confirmed_at?->toIso8601String(),
                    ];
                });

            $phases[] = [
                'phase' => $phase,
                'items' => $items->values()->all(),
                'checked_count' => $items->where('checked', true)->count(), // a FACT, never a verdict
                'total' => $items->count(),
            ];
        }

        return ['checklist_id' => $checklist?->id, 'phases' => $phases];
    }

    /** Get-or-create the case's checklist container, auto-seeding the tenant template if empty. */
    private function resolveChecklist(SurgicalCase $case): SurgicalChecklist
    {
        if (SurgicalChecklistTemplateItem::query()->where('active', true)->doesntExist()) {
            $this->seedStandardItems();
        }

        return SurgicalChecklist::query()->firstOrCreate(
            ['surgical_case_id' => $case->id],
            ['patient_id' => $case->patient_id],
        );
    }

    private function seedStandardItems(): int
    {
        $created = 0;
        foreach (self::STANDARD as $phase => $labels) {
            foreach ($labels as $order => $label) {
                $item = SurgicalChecklistTemplateItem::query()->firstOrCreate(
                    ['phase' => $phase, 'label' => $label],
                    ['display_order' => $order, 'active' => true],
                );
                if ($item->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return $created;
    }

    private function assertSameTenant(?string $tenantId, string $attribute, string $id): void
    {
        if ($tenantId !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, $id);
        }
    }
}
