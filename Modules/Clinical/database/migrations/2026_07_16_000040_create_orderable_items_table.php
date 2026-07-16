<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-AUTHORED orderable items (labs/imaging/other). Like the tariff catalog,
 * a tenant defines its OWN list — NO licensed test catalog or proprietary code
 * set is bundled (P0P.G11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orderable_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('category'); // lab / imaging / other
            $table->string('code'); // the tenant's own code
            $table->string('name');
            $table->string('specimen_or_modality')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'category', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orderable_items');
    }
};
