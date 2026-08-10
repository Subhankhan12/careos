<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AiCore\Services\AgentRegistry;

/**
 * AGENT.P1 — the per-tenant Agent entity (a GOVERNED CONTAINER, never a source of authority).
 *
 * An agent maps to a real code-class agent (`key`), carries a CONFIGURED autonomy level + a status,
 * and a whitelist of the tools it MAY call (`tool_keys`). These are CONFIG that can only NARROW: the
 * effective autonomy for any (agent, tool) is resolved server-side as MIN(configured, tool ceiling,
 * role ceiling) — the config can never raise past the AutonomyPolicy cap or the role RBAC ceiling.
 *
 * Additive. Backfills the canonical agents for every EXISTING tenant so they each start governed;
 * new tenants get theirs via the Tenant `created` hook (AppServiceProvider). Default level = suggest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('key');                 // the code-class agent key (AgentRegistry)
            $table->string('name');
            $table->string('autonomy_level')->default('suggest'); // CONFIGURED; capped at resolve time
            $table->string('status')->default('active');          // active | paused
            $table->json('tool_keys');             // the whitelist (narrows only)
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'status']);
        });

        // Backfill: give every existing tenant its canonical agents (idempotent per tenant/key).
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach (AgentRegistry::AGENTS as $key => $spec) {
                $exists = DB::table('agents')->where('tenant_id', $tenantId)->where('key', $key)->exists();
                if ($exists) {
                    continue;
                }
                DB::table('agents')->insert([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'key' => $key,
                    'name' => $spec['name'],
                    'autonomy_level' => 'suggest',
                    'status' => 'active',
                    'tool_keys' => json_encode($spec['tools']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
