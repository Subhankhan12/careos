<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY.G1 — the tenant-authored FORMULARY: a tenant's own medication list. Exactly the catalog
 * discipline already shipped for tariffs / dental procedures / orderables — the tenant enters/imports
 * THEIR OWN meds; NO licensed drug database (First Databank / Medi-Span / RxNorm / ATC / NDC) is bundled
 * or stored here.
 *
 * `code` is the TENANT'S own code (not a licensed identifier). ELECTRIC FENCE: the columns are a plain
 * record — name / form / strength — with NO computed-safety field (no interaction/dose/contraindication
 * flag, no severity/score/risk). A licensed drug DB would later attach at a partner seam to ENRICH a row
 * (canonical name, ingredients, coded identifiers) — it is deliberately NOT attached now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulary_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('code');                 // the tenant's OWN code — not a licensed identifier
            $table->string('name');                 // generic/brand as the tenant enters it
            $table->string('form')->nullable();     // a plain dosage form: tablet/capsule/liquid/injection/topical/other
            $table->string('strength')->nullable(); // free text (e.g. "500 mg"), as the tenant needs
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // One code per tenant (the tenant authors its own coding scheme).
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulary_items');
    }
};
