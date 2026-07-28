<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G4 — the tenant-authored SURGICAL ITEM catalog (consumables + implants). Per
 * docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.5 — MIRRORS the pharmacy `formulary_items` tenant-authored catalog
 * (Surgery cannot import the peer Pharmacy vertical, so the recipe is COPIED, not shared). `is_implant` flags
 * an item that needs lot/serial/UDI traceability (`implant_placements`). `code` is the tenant's OWN code.
 *
 * ELECTRIC FENCE: a plain operational catalog — no device-safety / recall-status / grade column; the system
 * records the items, it does not verify or grade the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->string('code');   // the tenant's OWN code (NOT a licensed identifier)
            $table->string('name');
            $table->boolean('is_implant')->default(false); // implants need lot/serial/UDI traceability
            $table->string('unit')->default('unit');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_items');
    }
};
