<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAD.G3 — the NET-NEW `imaging_studies` entity (the one genuine net-new radiology build — the lab-`Specimen`
 * analog, per docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §2.2). A study is a RECORD that an exam was (or will be)
 * performed for a RAD.G2 imaging order: the `radiology_order_id` (→ the reused Clinical `Order` underneath),
 * the patient, the `modality` (from the order overlay), an `accession_number` (unique-per-tenant — the
 * `Specimen` accession recipe: tenant-locked safe generation + a unique constraint), `acquired_by`/`acquired_at`,
 * and a `status` (the legal-only lifecycle ordered → acquired → reported [+ cancelled]). The mutable CURRENT
 * state; the immutable state history is `imaging_study_events` (append-only). Tenant + patient scoped;
 * patient read-logged.
 *
 * **THE STUDY IS METADATA, NOT THE IMAGE.** The DICOM image (storage / a diagnostic viewer / PACS retrieval /
 * modality integration) is the PARTNER's — the SEAM-STUBBED RAD.G6, behind `ImagingConnectivity`, NOT built
 * here. This table records that an exam happened + its state; it holds no image and no pixel data.
 *
 * ELECTRIC FENCE (§4): `status` + `accession_number` are operational FACTS — there is deliberately NO computed
 * image finding/CAD/abnormality/confidence column, and NO computed priority/urgency/rank column (the recorded
 * STAT flag lives on the RAD.G2 order, shown as a fact, never computed here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imaging_studies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');         // denormalized for patient-scoped read logging + scoping
            $table->ulid('radiology_order_id'); // the RAD.G2 imaging order (→ the reused Clinical Order)
            $table->string('accession_number'); // a unique-per-tenant study identifier (safe generation)
            $table->string('modality')->nullable(); // from the order overlay (a plain recorded type)
            $table->string('status')->default('ordered'); // ordered → acquired → reported (+ cancelled); moves only via the machine
            $table->unsignedBigInteger('acquired_by')->nullable();
            $table->dateTime('acquired_at')->nullable(); // DATETIME (not TIMESTAMP) — engine-parity: no implicit ON UPDATE (P0P.G15)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('radiology_order_id')->references('id')->on('radiology_orders')->cascadeOnDelete();

            $table->unique(['tenant_id', 'accession_number']); // the accession is unique per tenant
            $table->unique('radiology_order_id');              // one study per imaging order
            $table->index(['tenant_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imaging_studies');
    }
};
