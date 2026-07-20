<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * treatment_plans (DENTAL.G5) — a patient's DENTIST-AUTHORED dental treatment plan: proposed
 * procedures organised into phases, with a cost ESTIMATE built from the G3 fee schedule, that
 * the patient accepts and works through. Distinct from the clinical Care Plans.
 *
 * It is an ESTIMATE + an agreement, NOT billing: accepting a plan posts NO charge — the actual
 * charge happens when a procedure is performed (G4). The status is a legal-only lifecycle
 * (draft → proposed → accepted/declined → in_progress → completed). ELECTRIC FENCE: the dentist
 * authors it; there is no auto-suggestion, no severity-driven prioritisation, no AI-recommended
 * treatment — no such column exists here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title')->nullable();
            $table->string('status')->default('draft'); // draft/proposed/accepted/in_progress/completed/declined
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id', 'status'], 'treatment_plans_patient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_plans');
    }
};
