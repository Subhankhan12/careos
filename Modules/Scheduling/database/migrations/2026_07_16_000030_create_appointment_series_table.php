<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring / series clinic appointments (P0P.G8). A series is the RRULE + the
 * booking template; the individual occurrences are ordinary appointments (booked
 * through the no-double-book BookingService) linked back by series_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): every row carries tenant_id.
        Schema::create('appointment_series', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('service_id');
            $table->char('branch_id', 26);
            $table->json('resource_ids'); // the resource(s) each occurrence books
            $table->text('rrule'); // RFC 5545
            $table->string('timezone');
            $table->string('start_time'); // local wall-clock, e.g. 09:00
            $table->unsignedInteger('duration_minutes');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status')->default('active'); // active/ended
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('services')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->ulid('series_id')->nullable()->after('rescheduled_from_id');
            $table->date('occurrence_date')->nullable()->after('series_id');

            $table->foreign('series_id')->references('id')->on('appointment_series')->nullOnDelete();
            $table->index(['tenant_id', 'series_id']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['series_id']);
            $table->dropIndex(['tenant_id', 'series_id']);
            $table->dropColumn(['series_id', 'occurrence_date']);
        });

        Schema::dropIfExists('appointment_series');
    }
};
