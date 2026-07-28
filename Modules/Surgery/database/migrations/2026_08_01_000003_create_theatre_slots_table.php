<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G1 — a THEATRE SLOT: a booked surgical BLOCK in a theatre. Per docs/HOSPITAL-PHASE5-SURGERY-MAP.md
 * §2.1 this is a NET-NEW model that REUSES the booking/bed overlap-lock INVARIANT (lock-the-row →
 * assert-no-overlap → insert, in one transaction) but NOT the Scheduling day-board model: a surgery is a
 * BOUNDED, pre-planned block (`starts_at` + `ends_at`), distinct from both a clinic day-grid slot and a bed's
 * open-ended occupancy. Two surgeries cannot overlap in the same theatre — enforced concurrency-safely by
 * `TheatreSchedulingService::bookSlot` (the `BookingService::lockResource`→`assertNoOverlap` idiom).
 *
 * `surgical_case_id` is a SOFT nullable ref to the case the block is for (no FK — the block can be reserved
 * before the case record exists, and Surgery keeps its links decoupled). NO money / judgment stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theatre_slots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('theatre_id');
            $table->ulid('surgical_case_id')->nullable(); // SOFT ref to the case (no FK)
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default('booked');  // booked / in_progress / completed / cancelled
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('theatre_id')->references('id')->on('theatres')->cascadeOnDelete();

            // The overlap query filters by (tenant, theatre, status) over the [starts_at, ends_at] range.
            $table->index(['tenant_id', 'theatre_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theatre_slots');
    }
};
