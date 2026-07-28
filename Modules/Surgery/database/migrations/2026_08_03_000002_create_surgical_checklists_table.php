<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G3 — the per-case WHO checklist CONTAINER (one per case). Ties the checklist to the G1/G2
 * `surgical_case`; `patient_id` is denormalized for patient-scoped read-logging. The team's item
 * confirmations live in the append-only `surgical_checklist_items`. This is a RECORD, not a gate on the case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_checklists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('surgical_case_id');
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('surgical_case_id')->references('id')->on('surgical_cases')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            $table->unique(['tenant_id', 'surgical_case_id']); // one checklist per case
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_checklists');
    }
};
