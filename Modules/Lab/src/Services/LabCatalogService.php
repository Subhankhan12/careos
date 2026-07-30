<?php

namespace Modules\Lab\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Clinical\Models\OrderableItem;
use Modules\Lab\Exceptions\LabCatalogException;
use Modules\Lab\Models\LabTest;
use Modules\Platform\Models\User;

/**
 * The tenant's lab test catalog (LAB.G1) — a lab test IS a tenant-authored Clinical `OrderableItem`
 * (`category='lab'`; code/name/specimen live there, REUSED not duplicated) with a thin {@see LabTest} overlay
 * adding the lab-specific DISPLAY reference data (`unit` + `reference_range`). Authoring is gated
 * `lab.catalog`; audited via the app-layer `LabTest`/`OrderableItem` hooks. Tenant-scoped, fail-closed.
 *
 * **NO licensed LOINC/test/analyzer code set is bundled** — {@see seedStarter()} is a SMALL GENERIC editable
 * template a tenant edits/replaces (the `OrderableItemService` / tariff-catalog discipline). ELECTRIC FENCE
 * (docs/HOSPITAL-PHASE3-LAB-MAP.md §4): `reference_range` is RECORDED REFERENCE DATA (a free-text/recorded
 * range the clinician reads beside a result) — the system NEVER computes a high/low/abnormal/critical flag or
 * grades a value against it. This service records the catalog; it interprets nothing.
 */
class LabCatalogService
{
    /**
     * A SMALL GENERIC starter set — an EDITABLE TEMPLATE, NOT a licensed code set. Plain names + the tenant's
     * own short codes; the ranges are illustrative RECORDED reference data a tenant edits. (A blood-count
     * PANEL has no single range — its `reference_range` is null; the analytes carry ranges.)
     *
     * @var list<array{code: string, name: string, specimen: string, unit: string|null, range: string|null}>
     */
    private const STARTER = [
        ['code' => 'LAB-FBC', 'name' => 'Full blood count', 'specimen' => 'Blood', 'unit' => null, 'range' => null],
        ['code' => 'LAB-HB', 'name' => 'Haemoglobin', 'specimen' => 'Blood', 'unit' => 'g/dL', 'range' => '13.0–17.0'],
        ['code' => 'LAB-K', 'name' => 'Potassium', 'specimen' => 'Blood', 'unit' => 'mmol/L', 'range' => '3.5–5.1'],
        ['code' => 'LAB-CREAT', 'name' => 'Creatinine', 'specimen' => 'Blood', 'unit' => 'µmol/L', 'range' => '60–110'],
        ['code' => 'LAB-GLU-F', 'name' => 'Fasting glucose', 'specimen' => 'Blood', 'unit' => 'mmol/L', 'range' => '3.9–5.5'],
        ['code' => 'LAB-URINALYSIS', 'name' => 'Urinalysis', 'specimen' => 'Urine', 'unit' => null, 'range' => null],
    ];

    /**
     * Author (create or update) a lab test — a tenant-authored `OrderableItem` (`category='lab'`, REUSED) +
     * its {@see LabTest} overlay (unit + reference range). Gated `lab.catalog`; one transaction; idempotent on
     * the tenant's code. The reference range is RECORDED reference data, never a computed threshold.
     */
    public function authorTest(
        User $actor,
        string $code,
        string $name,
        ?string $specimenType = null,
        ?string $unit = null,
        ?string $referenceRange = null,
    ): LabTest {
        Gate::forUser($actor)->authorize('lab.catalog');

        $code = trim($code);
        $name = trim($name);
        if ($code === '' || $name === '') {
            throw LabCatalogException::codeAndNameRequired();
        }

        return DB::transaction(function () use ($code, $name, $specimenType, $unit, $referenceRange): LabTest {
            // REUSE the existing Clinical orderable catalog — a lab test is an OrderableItem (category=lab).
            $orderable = OrderableItem::query()->updateOrCreate(
                ['code' => $code],
                [
                    'category' => OrderableItem::CATEGORY_LAB,
                    'name' => $name,
                    'specimen_or_modality' => $specimenType,
                    'active' => true,
                ],
            );

            // The thin lab overlay: only the display reference data (unit + reference range).
            return LabTest::query()->updateOrCreate(
                ['orderable_item_id' => $orderable->id],
                ['unit' => $unit, 'reference_range' => $referenceRange],
            );
        });
    }

    /** Deactivate a lab test (soft) — the orderable's `active=false`, so it stops being orderable. Gated `lab.catalog`. */
    public function deactivate(User $actor, LabTest $test): LabTest
    {
        Gate::forUser($actor)->authorize('lab.catalog');

        $orderable = OrderableItem::query()->find($test->orderable_item_id);
        if ($orderable !== null) {
            $orderable->forceFill(['active' => false])->save();
        }

        return $test->refresh();
    }

    /** Seed the generic starter template for the current tenant. Idempotent by code. Gated `lab.catalog`. */
    public function seedStarter(User $actor): int
    {
        Gate::forUser($actor)->authorize('lab.catalog');

        $created = 0;
        foreach (self::STARTER as $t) {
            $before = LabTest::query()->count();
            $this->authorTest($actor, $t['code'], $t['name'], $t['specimen'], $t['unit'], $t['range']);
            if (LabTest::query()->count() > $before) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * The tenant's lab tests (with the orderable) — the catalog surface.
     *
     * @return Collection<int, LabTest>
     */
    public function catalog(): Collection
    {
        return LabTest::query()->with('orderableItem')->get()
            ->sortBy(fn (LabTest $t): string => (string) $t->orderableItem?->code)->values();
    }
}
