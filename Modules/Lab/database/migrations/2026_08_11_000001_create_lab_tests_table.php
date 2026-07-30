<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB.G1 — the lab-test overlay on the EXISTING Clinical `OrderableItem` (the `dental_procedures` /
 * `surgical_items` precedent, per docs/HOSPITAL-PHASE3-LAB-MAP.md §2.3). A lab test IS a tenant-authored
 * `OrderableItem` (`category='lab'`; code/name/specimen live there); this overlay adds ONLY the lab-specific
 * DISPLAY reference data — `unit` and `reference_range` — keyed 1:1 to the orderable. **NO licensed
 * LOINC/test/analyzer code set is bundled** (the tenant authors its own, like every catalog).
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE3-LAB-MAP.md §4): `reference_range` is **RECORDED REFERENCE DATA** the
 * clinician reads beside a result — it is a free-text/recorded range, NOT a computed threshold the system
 * grades a value against. There is deliberately NO abnormal/high/low/critical/flag/score/grade column here or
 * anywhere: a COMPUTED abnormal/critical verdict is the vitals-bands line — a certified-partner / non-goal,
 * enforced in LAB.G4. Order/result themselves REUSE Clinical's `Order`/`OrderResult` (append-only, raw) — this
 * gate does NOT duplicate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_tests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('orderable_item_id'); // the twin (code/name/specimen/active live on orderable_items)
            $table->string('unit')->nullable();            // e.g. g/dL, mmol/L — recorded reference data
            $table->string('reference_range')->nullable(); // e.g. "13.0–17.0" — RECORDED reference data (displayed), NOT a computed threshold
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('orderable_item_id')->references('id')->on('orderable_items')->cascadeOnDelete();

            $table->unique('orderable_item_id'); // one lab-test overlay per orderable
            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_tests');
    }
};
