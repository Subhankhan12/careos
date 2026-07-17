<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-AUTHORED nurse competencies (finer-grained than the RN/LPN/care-assistant
 * qualification). Like the tariff catalog and orderable items, a tenant defines its
 * OWN list — NO bundled licensed competency set. Each competency's ENFORCEMENT
 * (hard = blocks assignment, soft = warns the dispatcher) is the AGENCY's choice:
 * the system never decides which competencies are safety-critical (P0P.G12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competencies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('code'); // the tenant's own code
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('enforcement')->default('hard'); // hard = blocks, soft = warns
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competencies');
    }
};
