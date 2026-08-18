<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Platform\Models\Plan;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\RbacProvisioner;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

/**
 * DEPLOY.PROV (M1) — create a REAL (non-demo) tenant.
 *
 * WHY THIS EXISTS. Before this command there was NO way to create a tenant:
 * `Tenant::create` appeared only inside the three demo seeders — no route, no
 * controller, no command — so the runbook's "Create their tenant" step had no
 * mechanism behind it and the first customer could only be provisioned by
 * undocumented Tinker. This is that step, made real and repeatable.
 *
 * It creates a MINIMAL, real tenant — a name, a slug, a region, a plan and the
 * locale/currency/timezone settings. It seeds NO patients, staff, appointments or
 * money; it is emphatically not a demo seeder.
 *
 * The role templates come for free and are NOT re-implemented here: `Tenant::created`
 * fires {@see RbacProvisioner::provisionTenant()}, which
 * syncs the permission catalog and seeds all 26 starter role templates for the new
 * tenant. This command verifies that actually happened and reports the count rather
 * than assuming it.
 *
 * Safe by construction: a duplicate slug is REFUSED before anything is written, so a
 * re-run can never half-create a tenant or silently attach to an existing customer.
 */
class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {name : The practice/clinic name, e.g. "Praxis Example"}
        {--slug= : URL-safe identifier (unique). Derived from the name when omitted}
        {--plan=eu_pro : Plan key (see plans:seed). A tenant with NO plan has every feature OFF}
        {--region=eu : Data region — eu|us. IMMUTABLE after creation}
        {--currency=EUR : Tenant currency label (amounts are always integer minor units)}
        {--locale=en : Default UI locale for this tenant}
        {--timezone=Europe/Zurich : Tenant display timezone}
        {--status=active : provisioning|active. Defaults to active — this is a real customer}';

    protected $description = 'Create a real (non-demo) tenant, with its 26 starter role templates.';

    public function handle(TenantContext $tenants, SettingsService $settings): int
    {
        $name = trim((string) $this->argument('name'));
        $slug = Str::slug((string) ($this->option('slug') ?: $name));
        $region = (string) $this->option('region');
        $status = (string) $this->option('status');

        if ($name === '') {
            $this->error('A tenant name is required.');

            return self::FAILURE;
        }

        if ($slug === '') {
            $this->error('Could not derive a slug from that name — pass --slug explicitly.');

            return self::FAILURE;
        }

        if (! in_array($region, ['eu', 'us'], true)) {
            $this->error("Region must be 'eu' or 'us' — it is immutable after creation.");

            return self::FAILURE;
        }

        if (! in_array($status, ['provisioning', 'active'], true)) {
            $this->error("Status must be 'provisioning' or 'active'.");

            return self::FAILURE;
        }

        // REFUSE a duplicate rather than half-creating or silently reattaching.
        if (Tenant::query()->where('slug', $slug)->exists()) {
            $this->error("A tenant with slug [{$slug}] already exists — refusing to create a second.");

            return self::FAILURE;
        }

        $plan = $this->resolvePlan();

        if ($plan === false) {
            return self::FAILURE;
        }

        // Creating the tenant fires Tenant::created -> RbacProvisioner::provisionTenant(),
        // which syncs the permission catalog and seeds the starter roles.
        $tenant = Tenant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'region' => $region,
            'status' => $status,
            'plan_id' => $plan?->getKey(),
        ]);

        // The locale/currency/timezone live in tenant-scoped settings, so they need the
        // context. Restore whatever was there so a caller's context is never clobbered.
        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $settings->set('currency', (string) $this->option('currency'));
            $settings->set('locale', (string) $this->option('locale'));
            $settings->set('timezone', (string) $this->option('timezone'));
        } finally {
            $previous instanceof Tenant ? $tenants->set($previous) : $tenants->forget();
        }

        // Verify the hook actually fired rather than assuming it — a tenant with no roles
        // cannot be administered, and that would be a silent, expensive discovery later.
        $roleCount = $tenants->system(fn (): int => Role::query()->where('tenant_id', $tenant->getKey())->count());

        if ($roleCount === 0) {
            $this->error('Tenant created but NO role templates were seeded — check RbacProvisioner.');

            return self::FAILURE;
        }

        $this->info("Tenant created: {$tenant->name}");
        $this->line("  id        {$tenant->getKey()}");
        $this->line("  slug      {$tenant->slug}");
        $this->line('  region    '.$tenant->region.'  (immutable)');
        $this->line('  status    '.$tenant->status);
        $this->line('  plan      '.($plan instanceof Plan ? $plan->key : 'NONE — every feature is OFF'));
        $this->line("  roles     {$roleCount} starter templates seeded");
        $this->newLine();
        $this->comment("Next: php artisan tenant:add-admin {$tenant->slug} --email=... --name=\"...\"");

        return self::SUCCESS;
    }

    /**
     * The plan, or null when explicitly opted out. Returns false when the request
     * cannot be satisfied (so the caller aborts before creating anything).
     */
    private function resolvePlan(): Plan|null|false
    {
        $key = (string) $this->option('plan');

        if ($key === '' || $key === 'none') {
            $this->warn('No plan requested — this tenant will have EVERY feature OFF (FeatureService returns false).');

            return null;
        }

        $plan = Plan::query()->where('key', $key)->first();

        if (! $plan instanceof Plan) {
            $available = Plan::query()->orderBy('key')->pluck('key')->implode(', ');

            $this->error("Unknown plan [{$key}].");
            $this->line($available === ''
                ? '  No plans exist yet — run: php artisan plans:seed'
                : "  Available: {$available}");

            return false;
        }

        return $plan;
    }
}
