<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT.P6 — rate/timing limits + the agent KIND.
 *
 * Additive, all nullable:
 *  - `kind`: the canonical capability an agent is an instance OF (an AgentRegistry key). Distinct
 *    from the per-agent unique `key`, so an admin can create a NEW named agent of a real kind (the
 *    kind is what the remit/ceiling/ledger-attribution derive from). Backfilled = `key` for every
 *    existing agent (they ARE their kind), so nothing changes for the seeded canonical agents.
 *  - `max_drafts_per_hour`: a real per-agent rate cap the runtime CONSULTS (null = no cap).
 *  - `quiet_hours_start` / `quiet_hours_end`: a real window (hour-of-day 0–23) the runtime CONSULTS;
 *    an agent does not act during quiet hours (null = no quiet window).
 *
 * These are CONFIG the runtime reads on the Agent-entity path (the P1 governed-container path). No
 * autonomy is added — a limit can only STOP the agent, never widen it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->string('kind')->nullable()->after('key');
            $table->unsignedSmallInteger('max_drafts_per_hour')->nullable()->after('tool_keys');
            $table->unsignedTinyInteger('quiet_hours_start')->nullable()->after('max_drafts_per_hour');
            $table->unsignedTinyInteger('quiet_hours_end')->nullable()->after('quiet_hours_start');
        });

        // Every existing agent IS its kind — backfill so remit/ceiling/attribution are unchanged.
        DB::table('agents')->update(['kind' => DB::raw('`key`')]);
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'max_drafts_per_hour', 'quiet_hours_start', 'quiet_hours_end']);
        });
    }
};
