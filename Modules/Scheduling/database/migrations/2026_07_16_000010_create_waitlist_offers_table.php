<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id. An offer is
        // a time-boxed hold of a freed slot for one waitlist patient.
        Schema::create('waitlist_offers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('waitlist_entry_id');
            $table->ulid('source_appointment_id')->nullable(); // the freed appointment
            $table->ulid('patient_id'); // the offered-to patient (denormalized for notify/audit)
            $table->ulid('service_id');
            $table->char('branch_id', 26);
            $table->dateTime('slot_starts_at');
            $table->dateTime('slot_ends_at');
            $table->json('resource_ids'); // the resource(s) to re-book on accept
            $table->string('status')->default('offered'); // offered/accepted/declined/expired
            $table->char('offered_by', 26)->nullable();
            $table->dateTime('offered_at');
            $table->dateTime('expires_at');
            $table->dateTime('responded_at')->nullable();
            $table->ulid('booked_appointment_id')->nullable(); // set on accept
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('waitlist_entry_id')->references('id')->on('waitlist_entries')->cascadeOnDelete();
            $table->foreign('source_appointment_id')->references('id')->on('appointments')->nullOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('booked_appointment_id')->references('id')->on('appointments')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'waitlist_entry_id']);
            $table->index(['tenant_id', 'branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_offers');
    }
};
