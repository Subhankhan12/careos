<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPROVAL.P5 — model the ELECTRIC FENCE refusal as a countable, recorded AgentAction outcome.
 *
 * The fence already fires (a handed-off draft throws on approve — see DraftReplyTool::execute);
 * this only RECORDS that refusal as a terminal, queryable status (`fence_refused`) so it can be
 * counted on the governance stat strip and listed in the Resolved view. The `status` column is a
 * plain string, so the new value needs no schema change — only this timestamp of when the fence
 * refused the action (the analog of approved_at / rejected_at). The fence's OWN reason is stored
 * in the existing `rejection_reason` column (the generic "why this action did not post" field),
 * distinguished from a human reject by the status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_actions', function (Blueprint $table): void {
            $table->timestamp('fence_refused_at')->nullable()->after('executed_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_actions', function (Blueprint $table): void {
            $table->dropColumn('fence_refused_at');
        });
    }
};
