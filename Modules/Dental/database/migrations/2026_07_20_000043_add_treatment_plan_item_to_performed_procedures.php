<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DENTAL.G5 — the OPTIONAL link from a performed procedure (G4) to a treatment-plan item, so
 * an accepted plan can track completion (a plan item is done when a performed procedure
 * references it). Additive + nullable; G4's atomic workflow is unchanged (the link is passed
 * only when performing a planned item). `nullOnDelete` so removing a plan item never destroys
 * the append-only performed record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performed_procedures', function (Blueprint $table): void {
            $table->ulid('treatment_plan_item_id')->nullable()->after('dental_procedure_id');
            $table->foreign('treatment_plan_item_id')->references('id')->on('treatment_plan_items')->nullOnDelete();
            $table->index(['tenant_id', 'treatment_plan_item_id'], 'performed_procedures_plan_item_idx');
        });
    }

    public function down(): void
    {
        Schema::table('performed_procedures', function (Blueprint $table): void {
            $table->dropForeign(['treatment_plan_item_id']);
            $table->dropIndex('performed_procedures_plan_item_idx');
            $table->dropColumn('treatment_plan_item_id');
        });
    }
};
