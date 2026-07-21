<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * diagnosis_terms — the tenant's OWN list of common diagnosis terms (DENTAL.G7). A plain
 * convenience PICK-LIST the dentist can choose from when recording a diagnosis; free text is
 * equally valid. This is TENANT-AUTHORED, exactly like the dental procedure catalog — NO
 * licensed diagnostic code set (ICD/SNODENT/etc.) is bundled.
 *
 * ELECTRIC FENCE (the sharpest in the vertical): this is a flat list of the tenant's own terms.
 * There is DELIBERATELY no rank, no likelihood, no confidence, no score, no "suggested" or
 * "differential" ordering — the list is never filtered or sorted by any computed judgment. The
 * dentist reads their own list and picks; the system never proposes or ranks a diagnosis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosis_terms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('label');            // the tenant's own term — no code, no rank
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'label'], 'diagnosis_terms_tenant_label_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosis_terms');
    }
};
