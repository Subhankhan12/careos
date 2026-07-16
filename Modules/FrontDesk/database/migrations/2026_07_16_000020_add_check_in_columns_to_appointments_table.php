<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self check-in (P0P.G7) is stored ON the appointment (chosen over a separate
 * appointment_checkins table): check-in is a 1:1 attribute of an appointment and
 * lives next to its lifecycle, so no join is needed on the day-board or kiosk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('checked_in_at')->nullable()->after('status_changed_at');
            $table->string('check_in_source')->nullable()->after('checked_in_at'); // kiosk/portal/reception
            $table->string('check_in_code')->nullable()->after('check_in_source'); // short per-appointment kiosk-match code

            // Kiosk lookup: today's booked appointments at a branch by code.
            $table->index(['tenant_id', 'branch_id', 'starts_at', 'check_in_code'], 'appointments_kiosk_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_kiosk_lookup');
            $table->dropColumn(['checked_in_at', 'check_in_source', 'check_in_code']);
        });
    }
};
