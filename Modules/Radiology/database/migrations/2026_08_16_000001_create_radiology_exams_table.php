<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAD.G1 — the imaging-exam overlay on the EXISTING Clinical `OrderableItem` (the `lab_tests` /
 * `dental_procedures` / `surgical_items` precedent, per docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §2.3). An imaging
 * exam IS a tenant-authored `OrderableItem` (`category='imaging'`; code/name live there, and the **modality**
 * lives in the existing `specimen_or_modality` field — both ALREADY exist); this overlay adds ONLY the
 * imaging-specific facts `body_part` + `contrast`, keyed 1:1 to the orderable. **NO licensed CPT/RadLex/imaging
 * code set is bundled** (the tenant authors its own, like every catalog).
 *
 * ELECTRIC FENCE (§4): there is deliberately NO computed image finding/CAD/abnormality/flag/confidence/score
 * column here or anywhere — a computed image read is a hard medical-device non-goal. The order/report/image
 * REUSE Clinical's `Order`/`ClinicalNote`/`Document` — this gate does NOT duplicate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_exams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('orderable_item_id'); // the twin (code/name/modality[specimen_or_modality]/active live on orderable_items)
            $table->string('body_part')->nullable();       // e.g. Chest, Head — recorded reference data
            $table->boolean('contrast')->default(false);   // with-contrast flag — a recorded fact, never computed
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('orderable_item_id')->references('id')->on('orderable_items')->cascadeOnDelete();

            $table->unique('orderable_item_id'); // one exam overlay per orderable
            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_exams');
    }
};
