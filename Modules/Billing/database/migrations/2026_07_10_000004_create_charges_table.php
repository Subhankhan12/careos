<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('encounter_id')->nullable();
            $table->ulid('visit_id')->nullable();
            $table->ulid('branch_id');
            $table->date('service_date');
            $table->ulid('tariff_catalog_id');
            $table->ulid('tariff_item_id');
            $table->string('code');
            $table->text('description');
            $table->integer('unit_price_minor');
            $table->integer('vat_rate_bp');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('line_total_minor');
            $table->string('status')->default('draft');
            $table->ulid('invoice_id')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->restrictOnDelete();
            $table->foreign('visit_id')->references('id')->on('visits')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('tariff_catalog_id')->references('id')->on('tariff_catalogs')->restrictOnDelete();
            $table->foreign('tariff_item_id')->references('id')->on('tariff_items')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'service_date']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'invoice_id']);
        });

        DB::statement('ALTER TABLE charges ADD CONSTRAINT charges_not_both_sources CHECK (encounter_id IS NULL OR visit_id IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
