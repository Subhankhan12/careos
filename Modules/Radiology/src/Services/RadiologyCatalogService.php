<?php

namespace Modules\Radiology\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\OrderableItem;
use Modules\Platform\Models\User;
use Modules\Radiology\Exceptions\RadiologyCatalogException;
use Modules\Radiology\Models\RadiologyExam;

/**
 * The tenant's imaging exam catalog (RAD.G1) — an imaging exam IS a tenant-authored Clinical `OrderableItem`
 * (`category='imaging'`; code/name live there, and the **modality** in the existing `specimen_or_modality`
 * field — both REUSED, not duplicated) with a thin {@see RadiologyExam} overlay adding `body_part` + `contrast`.
 * Authoring is gated `radiology.catalog`; audited via the app-layer `RadiologyExam` hook. Tenant-scoped,
 * fail-closed.
 *
 * **NO licensed CPT/RadLex/imaging code set is bundled** — {@see seedStarter()} is a SMALL GENERIC editable
 * template a tenant edits/replaces (the `LabCatalogService` / tariff-catalog discipline). ELECTRIC FENCE
 * (docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §4): the catalog records modality/body-part reference data — the
 * system NEVER computes an image finding/CAD/abnormality flag. This service records the catalog; it interprets
 * no image.
 */
class RadiologyCatalogService
{
    /**
     * A SMALL GENERIC starter set — an EDITABLE TEMPLATE, NOT a licensed code set. Plain names + the tenant's
     * own short codes; a handful of common exams a tenant edits/replaces.
     *
     * @var list<array{code: string, name: string, modality: string, body_part: string, contrast: bool}>
     */
    private const STARTER = [
        ['code' => 'RAD-CXR', 'name' => 'Chest X-ray', 'modality' => 'X-ray', 'body_part' => 'Chest', 'contrast' => false],
        ['code' => 'RAD-AXR', 'name' => 'Abdominal X-ray', 'modality' => 'X-ray', 'body_part' => 'Abdomen', 'contrast' => false],
        ['code' => 'RAD-CT-HEAD', 'name' => 'CT head', 'modality' => 'CT', 'body_part' => 'Head', 'contrast' => false],
        ['code' => 'RAD-CT-ABDO', 'name' => 'CT abdomen/pelvis', 'modality' => 'CT', 'body_part' => 'Abdomen/Pelvis', 'contrast' => true],
        ['code' => 'RAD-MRI-BRAIN', 'name' => 'MRI brain', 'modality' => 'MRI', 'body_part' => 'Brain', 'contrast' => false],
        ['code' => 'RAD-US-ABDO', 'name' => 'Abdominal ultrasound', 'modality' => 'US', 'body_part' => 'Abdomen', 'contrast' => false],
    ];

    /**
     * Author (create or update) an imaging exam — a tenant-authored `OrderableItem` (`category='imaging'`,
     * REUSED; modality on `specimen_or_modality`) + its {@see RadiologyExam} overlay (body_part + contrast).
     * Gated `radiology.catalog`; one transaction; idempotent on the tenant's code.
     */
    public function authorExam(
        User $actor,
        string $code,
        string $name,
        ?string $modality = null,
        ?string $bodyPart = null,
        bool $contrast = false,
    ): RadiologyExam {
        Gate::forUser($actor)->authorize('radiology.catalog');

        $code = trim($code);
        $name = trim($name);
        if ($code === '' || $name === '') {
            throw RadiologyCatalogException::codeAndNameRequired();
        }

        return DB::transaction(function () use ($code, $name, $modality, $bodyPart, $contrast): RadiologyExam {
            // REUSE the existing Clinical orderable catalog — an imaging exam is an OrderableItem (category=imaging).
            $orderable = OrderableItem::query()->updateOrCreate(
                ['code' => $code],
                [
                    'category' => OrderableItem::CATEGORY_IMAGING,
                    'name' => $name,
                    'specimen_or_modality' => $modality, // the modality lives on the orderable (field already exists)
                    'active' => true,
                ],
            );

            // The thin imaging overlay: body_part + contrast (recorded facts, never a computed image read).
            return RadiologyExam::query()->updateOrCreate(
                ['orderable_item_id' => $orderable->id],
                ['body_part' => $bodyPart, 'contrast' => $contrast],
            );
        });
    }

    /** Deactivate an exam (soft) — the orderable's `active=false`, so it stops being orderable. Gated `radiology.catalog`. */
    public function deactivate(User $actor, RadiologyExam $exam): RadiologyExam
    {
        Gate::forUser($actor)->authorize('radiology.catalog');

        $orderable = OrderableItem::query()->find($exam->orderable_item_id);
        if ($orderable !== null) {
            $orderable->forceFill(['active' => false])->save();
        }

        return $exam->refresh();
    }

    /** Seed the generic starter template for the current tenant. Idempotent by code. Gated `radiology.catalog`. */
    public function seedStarter(User $actor): int
    {
        Gate::forUser($actor)->authorize('radiology.catalog');

        $created = 0;
        foreach (self::STARTER as $e) {
            $before = RadiologyExam::query()->count();
            $this->authorExam($actor, $e['code'], $e['name'], $e['modality'], $e['body_part'], $e['contrast']);
            if (RadiologyExam::query()->count() > $before) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * The tenant's imaging exams (with the orderable) — the catalog surface.
     *
     * @return Collection<int, RadiologyExam>
     */
    public function catalog(): Collection
    {
        return RadiologyExam::query()->with('orderableItem')->get()
            ->sortBy(fn (RadiologyExam $e): string => (string) $e->orderableItem?->code)->values();
    }
}
