<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OPMODE.G2 — the REQUEST half of the grant.
        //
        // G1 shipped a grant that was already decided (its guarded internal path creates
        // an ACTIVE row). G2 adds the entry point where an operator ASKS, which for the
        // approval-required tiers must open NOTHING until an owner decides (G3).
        //
        // THE TWO CLOCKS ARE DELIBERATELY SEPARATE (see OPERATOR-MODE-MAP.md §2.2):
        //  - request_expires_at → how long the ASK stays open. Expiry here grants NOTHING.
        //  - expires_at (G1)    → how long an APPROVED/self-granted session lives. Expiry
        //                         there ENDS access.
        // Conflating them is the classic bug in this kind of flow, so they are two columns.
        Schema::table('operator_access_grants', function (Blueprint $table): void {
            // P0P.G15: mutable moments are dateTime(), never timestamp().
            $table->dateTime('requested_at')->nullable()->after('reason');

            // THE REQUEST CLOCK. Null for a grant that was never a request.
            $table->dateTime('request_expires_at')->nullable()->after('requested_at');

            // The session length ASKED FOR. It is not the session itself: for an
            // approval-required tier the session clock only starts when an owner
            // approves (G3), so this is the requested figure, applied at that point.
            $table->unsignedSmallInteger('requested_ttl_minutes')->nullable()->after('request_expires_at');

            // The hot path for the request-TTL sweeper: pending rows whose ask has lapsed.
            $table->index(['status', 'request_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('operator_access_grants', function (Blueprint $table): void {
            $table->dropIndex(['status', 'request_expires_at']);
            $table->dropColumn(['requested_at', 'request_expires_at', 'requested_ttl_minutes']);
        });
    }
};
