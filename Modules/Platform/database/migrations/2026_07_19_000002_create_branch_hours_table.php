<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch weekly opening hours (structurally like resource_availability, but at
 * the BRANCH level). One row per (branch, weekday). A branch with NO rows is
 * "unconfigured" — the slot engine falls back to its default scan window (backward
 * compatible). A branch WITH rows is hours-managed: a weekday row is either
 * is_closed, or an [open_time, close_time] window the booking engine must respect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_hours', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('branch_id');
            $table->unsignedTinyInteger('weekday'); // Carbon dayOfWeek: 0 = Sunday … 6 = Saturday
            $table->boolean('is_closed')->default(false);
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->unique(['tenant_id', 'branch_id', 'weekday']);
            $table->index(['tenant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_hours');
    }
};
