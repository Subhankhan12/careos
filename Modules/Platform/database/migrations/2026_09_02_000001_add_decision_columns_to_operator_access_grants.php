<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OPMODE.G3 — the OWNER DECISION half.
        //
        // A DOWNGRADE SUPERSEDES, IT DOES NOT MUTATE. G1/G2 make a grant's facts
        // (tier, scope, tenant, operator, justification) permanently immutable, and that
        // rule is not bent here: when an owner grants LESS than was asked, the request row
        // is closed as `declined` and a NEW active grant is created at the narrower
        // tier/scope, pointing back at the request it answers via `supersedes_id`.
        //
        // That is the ARDETAIL.P6 withdrawal recipe, and it is also what the wireframe
        // shows the operator — "YOU REQUESTED Full support / INSTEAD OWNER GRANTED
        // Read-only" — two facts, both permanently recorded, neither overwriting the other.
        Schema::table('operator_access_grants', function (Blueprint $table): void {
            // WHO decided, WHEN, and WHY (the owner's note, shown to the operator).
            // P0P.G15: mutable moments are dateTime(), never timestamp().
            $table->dateTime('decided_at')->nullable()->after('requested_ttl_minutes');
            $table->unsignedBigInteger('decided_by')->nullable()->after('decided_at');
            $table->text('decision_note')->nullable()->after('decided_by');

            // The request this grant was issued IN ANSWER TO (a downgrade only).
            $table->ulid('supersedes_id')->nullable()->after('decision_note');

            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('supersedes_id')->references('id')->on('operator_access_grants')->nullOnDelete();

            $table->index('supersedes_id');
        });
    }

    public function down(): void
    {
        Schema::table('operator_access_grants', function (Blueprint $table): void {
            $table->dropForeign(['decided_by']);
            $table->dropForeign(['supersedes_id']);
            $table->dropIndex(['supersedes_id']);
            $table->dropColumn(['decided_at', 'decided_by', 'decision_note', 'supersedes_id']);
        });
    }
};
