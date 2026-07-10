<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('tariff_catalog_id');
            $table->string('code');
            $table->text('description');
            $table->integer('unit_price_minor');
            $table->integer('vat_rate_bp')->default(0);
            $table->string('unit')->nullable();
            $table->boolean('requires_service_documentation')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tariff_catalog_id')->references('id')->on('tariff_catalogs')->cascadeOnDelete();

            $table->unique(['tenant_id', 'tariff_catalog_id', 'code']);
            $table->index(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_items');
    }
};
