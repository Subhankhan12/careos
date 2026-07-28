<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G1 — an operating THEATRE (an OR room). Per docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.1 a theatre is a
 * Surgery-OWNED entity (NOT forced into Scheduling's `Resource`), tenant + branch scoped. `type` is a plain,
 * tenant-meaningful label (general / cardiac / …) — an operational classification, NEVER a computed grade.
 *
 * ELECTRIC FENCE: a theatre is an operational record. There is deliberately no acuity / priority / risk /
 * score / severity / utilization-grade column — utilization is a FACT (a count/time) the UI derives, not a
 * judgment stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theatres', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('branch_id');
            $table->string('name');
            $table->string('type')->nullable(); // a plain tenant-meaningful label (general/cardiac/…)
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->unique(['tenant_id', 'branch_id', 'name']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theatres');
    }
};
