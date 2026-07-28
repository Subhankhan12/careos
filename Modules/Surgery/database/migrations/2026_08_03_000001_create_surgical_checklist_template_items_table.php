<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G3 — the TENANT-AUTHORED WHO Surgical Safety Checklist TEMPLATE. Per
 * docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.4. The three WHO phases (sign_in / time_out / sign_out) each carry a
 * structured, tenant-EDITABLE set of items — seeded with the standard (freely-published) WHO items as an
 * editable starter, NOT a licensed/proprietary set (the formulary / bed-day tenant-authored discipline). A
 * MUTABLE config catalog (the tenant adds / deactivates items).
 *
 * ELECTRIC FENCE: an item is a plain checklist LABEL — no verdict / pass-fail / safety-weight column. The
 * checklist RECORDS what the team confirms; it computes no safety judgment and gates nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_checklist_template_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('phase');            // sign_in / time_out / sign_out
            $table->string('label');            // the item the team confirms (a plain label)
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // One item per (phase, label) per tenant; explicit short name (auto-name exceeds MySQL's 64 chars).
            $table->unique(['tenant_id', 'phase', 'label'], 'surg_checklist_tmpl_unique');
            $table->index(['tenant_id', 'phase', 'active'], 'surg_checklist_tmpl_phase_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_checklist_template_items');
    }
};
