<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY.G4 — pharmacy STOCK: the current on-hand quantity per formulary item for a tenant
 * (docs/HOSPITAL-PHASE2-PHARMACY-MAP.md §1). `on_hand` is the MUTABLE current state (the Bed-status
 * analogue) — mutated only under a FOR UPDATE row lock so concurrent dispenses cannot oversell; the
 * immutable ledger is `stock_movements`, and the current on_hand is kept consistent with it.
 *
 * ELECTRIC FENCE (operational, minimal): stock is a plain operational fact. `reorder_threshold` is a plain
 * configured NUMBER; "low stock" is a factual `on_hand <= reorder_threshold` comparison the UI renders,
 * NEVER a graded/severity/alert judgment. No safety judgment anywhere (medication safety is the
 * orders/administration seam, not dispensing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_stocks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('formulary_item_id');
            $table->string('location')->nullable();          // optional pharmacy/location (descriptive)
            $table->integer('on_hand')->default(0);          // the current on-hand quantity (mutated under a lock)
            $table->string('unit')->default('unit');         // the stock unit (tablet / vial / mL …)
            $table->integer('reorder_threshold')->nullable(); // a plain configured number — NOT a judgment
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('formulary_item_id')->references('id')->on('formulary_items')->cascadeOnDelete();

            // One stock row per formulary item per tenant.
            $table->unique(['tenant_id', 'formulary_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_stocks');
    }
};
