<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BRANCH.P2 — the per-tenant DEFAULT/PRIMARY branch (the wireframe's "main" tag) as a REAL,
 * invariant-guarded flag: EXACTLY ONE primary branch per tenant, always.
 *
 * Additive (default false). The backfill gives every EXISTING tenant exactly one primary — the
 * earliest-created ACTIVE branch (falling back to the earliest branch overall if none is active),
 * which aligns with the implicit "first branch" default some billing paths already use
 * (Branch::query()->firstOrFail()). Runtime keeps the invariant: the first branch of a tenant is
 * primary; set-primary atomically moves it; deactivating the primary reassigns it (see BranchService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false)->after('active');
        });

        // Backfill: exactly one primary per tenant (earliest active, else earliest overall).
        foreach (DB::table('branches')->distinct()->pluck('tenant_id') as $tenantId) {
            $target = DB::table('branches')
                ->where('tenant_id', $tenantId)->where('active', true)
                ->orderBy('created_at')->orderBy('id')->value('id')
                ?? DB::table('branches')
                    ->where('tenant_id', $tenantId)
                    ->orderBy('created_at')->orderBy('id')->value('id');

            if ($target !== null) {
                DB::table('branches')->where('id', $target)->update(['is_primary' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('is_primary');
        });
    }
};
