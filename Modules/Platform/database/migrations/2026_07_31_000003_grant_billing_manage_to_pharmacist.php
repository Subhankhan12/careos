<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;

/**
 * PHARMACY.G5 — grant the existing `billing.manage` permission to the `pharmacist` role for every tenant
 * (the pharmacist bills dispensed meds through the existing engine). Re-provisions from the updated
 * RbacProvisioner consts — the `add_billing_manage_permission` precedent. No new permission (billing.manage
 * already exists), so the `RbacTest` permission-count stays green. New tenants get it via the Tenant
 * `created` hook. (No `down()` role-detach: role grants are re-derived from the consts on any re-provision.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->orderBy('id')->each(function (Tenant $tenant): void {
            app(RbacProvisioner::class)->provisionTenant($tenant);
        });
    }

    public function down(): void
    {
        // The grant is derived from the RbacProvisioner consts; no destructive rollback.
    }
};
