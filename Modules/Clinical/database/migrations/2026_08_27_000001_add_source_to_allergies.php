<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ALLERGY.P1 — a RECORDED `source` for a patient allergy (e.g. "A&E discharge, confirmed by patient").
 *
 * Additive, nullable. This is a CLINICIAN-RECORDED FACT — where/how the allergy was documented — NOT a
 * computed field. It joins the existing recorded fields (substance, reaction, severity, verified_at) so
 * the allergy record card can surface the provenance the clinician documented. Nothing here computes a
 * drug-allergy conflict, cross-reactivity, or severity grade — those are the certified-partner
 * MedicationSafetyProvider seam's job (a permanent non-goal for homemade code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allergies', function (Blueprint $table): void {
            $table->string('source')->nullable()->after('reaction');
        });
    }

    public function down(): void
    {
        Schema::table('allergies', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
