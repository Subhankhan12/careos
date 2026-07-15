<?php

namespace App\Console\Commands;

use App\Audit\PlatformAuditContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\IntegrityCheck;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

/**
 * Daily proof that every active tenant's audit chain is still intact.
 *
 * The chain is hash-linked at insert and verified by replay: each row's
 * prev_hash must match the preceding row, and each row's stored hash must match
 * its own content. A break means a row was altered or removed by something that
 * went around both the model guards and the DB triggers — the strongest signal
 * of tampering the system has. Nobody would notice it on their own, so this
 * looks, every day, on purpose.
 *
 * Lives in the APPLICATION layer, not the Audit module: it needs Platform's
 * Tenant + TenantContext, and Audit may not depend on Platform. Same reason
 * {@see PlatformAuditContext} lives here.
 *
 * Each run leaves an append-only `integrity_checks` row per tenant — pass or
 * fail — so "the check ran and was clean" is provable later, and a check that
 * silently stopped running is visible as an absence. A failure additionally
 * logs at ERROR level with the offending row id.
 *
 * Safe to run repeatedly: verification is a pure read, and the row it appends
 * is the point of the run, not a side effect.
 */
class VerifyAuditChainsCommand extends Command
{
    protected $signature = 'audit:verify-chains';

    protected $description = 'Verify every active tenant\'s audit hash-chain and record the result.';

    public function handle(TenantContext $tenants, AuditService $audit): int
    {
        $previousTenant = $tenants->current();
        $broken = 0;
        $checked = 0;

        try {
            foreach (Tenant::query()->where('status', 'active')->orderBy('id')->get() as $tenant) {
                $tenants->set($tenant);
                $checked++;

                $result = $audit->verifyChain($tenant->getKey());
                $ok = $result['ok'] === true;

                IntegrityCheck::query()->create([
                    'kind' => IntegrityCheck::KIND_AUDIT_CHAIN,
                    'checked_at' => now(),
                    'ok' => $ok,
                    'detail' => $ok
                        ? ['events' => $result['count'] ?? 0]
                        : [
                            'broken_at' => $result['broken_at'],
                            'index' => $result['index'] ?? null,
                            'reason' => $result['reason'] ?? null,
                        ],
                ]);

                if ($ok) {
                    $this->line(sprintf('CHAIN:OK %s (%d events)', $tenant->slug, $result['count'] ?? 0));

                    continue;
                }

                $broken++;

                Log::error('Audit chain verification FAILED — the tenant\'s audit trail may have been tampered with.', [
                    'tenant_id' => $tenant->getKey(),
                    'tenant_slug' => $tenant->slug,
                    'broken_at' => $result['broken_at'],
                    'index' => $result['index'] ?? null,
                    'reason' => $result['reason'] ?? null,
                ]);

                $this->error(sprintf(
                    'CHAIN:BROKEN %s at %s (%s)',
                    $tenant->slug,
                    $result['broken_at'] ?? 'unknown',
                    $result['reason'] ?? 'unknown reason',
                ));
            }
        } finally {
            if ($previousTenant !== null) {
                $tenants->set($previousTenant);
            } else {
                $tenants->forget();
            }
        }

        if ($broken === 0) {
            Log::info('Audit chain verification passed for every active tenant.', ['tenants_checked' => $checked]);

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
