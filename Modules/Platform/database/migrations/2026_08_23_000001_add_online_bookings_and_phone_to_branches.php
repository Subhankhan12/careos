<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BRANCH.P1 — per-branch online-booking state (soft-suspend) + phone.
 *
 * `accepts_online_bookings` (default true, so existing branches are unchanged) is the SOFT-SUSPEND
 * control: setting it false stops NEW online bookings at the branch while `active` stays true —
 * existing appointments and the internal day-board are untouched. It is DISTINCT from the HARD
 * `active=false` deactivation (which is blocked while future appointments exist, W8b). `phone` is a
 * nullable contact field. Additive + defaulted/nullable — no existing behaviour changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->boolean('accepts_online_bookings')->default(true)->after('active');
            $table->string('phone')->nullable()->after('accepts_online_bookings');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn(['accepts_online_bookings', 'phone']);
        });
    }
};
