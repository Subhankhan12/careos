<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G4 — surgical STOCK: the current on-hand per surgical item. MIRRORS the pharmacy `medication_stocks`
 * recipe (the Bed-status analogue): `on_hand` is the MUTABLE current state, mutated ONLY under a FOR UPDATE
 * row lock so concurrent uses cannot oversell; the immutable ledger is `surgical_stock_movements`.
 * `reorder_threshold` is a plain number — "below stock" is a factual `on_hand <= threshold` comparison, never
 * a graded alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_item_stocks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('surgical_item_id');
            $table->string('location')->nullable();
            $table->integer('on_hand')->default(0);
            $table->string('unit')->default('unit');
            $table->integer('reorder_threshold')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('surgical_item_id')->references('id')->on('surgical_items')->cascadeOnDelete();

            $table->unique(['tenant_id', 'surgical_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_item_stocks');
    }
};
