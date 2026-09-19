<?php

namespace App\Console\Commands;

use App\Services\BranchService;
use Illuminate\Console\Command;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

/**
 * DEPLOY-FIX.1a — create a branch for a tenant from the command line.
 *
 * WHY THIS EXISTS. `tenant:create` + `tenant:add-admin` leave a tenant with ZERO branches, and the
 * day-board is scoped per branch — so the provisioning dry run reached the reception home screen of a
 * brand-new customer and got an HTTP 404. The 404 itself is fixed separately (the board now renders an
 * honest empty state); this command closes the other half, so the documented provisioning sequence can
 * produce a tenant that is actually ready to use without anyone opening a browser first.
 *
 * IT MIRRORS {@see AddTenantAdminCommand} DELIBERATELY. A branch is the same category of thing as the
 * first administrator: something that must exist before the product is usable, that the operator must
 * NAME rather than have invented for them, and that no in-app flow can create until somebody is already
 * inside. Hence a sibling command rather than a flag on `tenant:create` — which knows no branch CODE,
 * and `code` is NOT NULL and unique per tenant, so a flag would have had to silently invent a
 * customer-visible identifier.
 *
 * IT IS NOT A BOOTSTRAP-ONLY COMMAND. Unlike `tenant:add-admin`, which steps aside once an org_admin
 * exists, a practice legitimately opens further sites — so this stays usable for the second and third
 * branch. The exactly-one-primary invariant is NOT re-implemented here: `Branch::booted()` makes the
 * first branch of a tenant primary on every creation path, and this command deliberately does not pass
 * `is_primary` at all (it is fillable, and passing it would be the one way to create a second primary).
 *
 * THE TIMEZONE IS THE TENANT'S, NOT UTC. `Branch::$attributes` defaults `timezone` to `'UTC'`, and the
 * dry run found a first branch silently created in UTC for a Europe/Zurich practice. The branch timezone
 * is what the booking engine reads, so this defaults it from the tenant's own `timezone` setting — the
 * one `tenant:create` already wrote — and only falls back to the platform default when that is absent.
 */
class AddTenantBranchCommand extends Command
{
    protected $signature = 'tenant:add-branch
        {tenant : Tenant slug or id}
        {--name= : The branch/site name, e.g. "Hauptstandort"}
        {--code= : Short unique identifier within the tenant, e.g. "HAUPT"}
        {--timezone= : IANA zone. Defaults to the tenant\'s configured timezone}
        {--phone= : Optional contact number for this site}';

    protected $description = 'Create a branch for a tenant (the first one makes the tenant usable).';

    public function handle(TenantContext $tenants, SettingsService $settings, BranchService $branches): int
    {
        $identifier = (string) $this->argument('tenant');
        $name = trim((string) $this->option('name'));
        $code = trim((string) $this->option('code'));

        $tenant = Tenant::query()
            ->where('slug', $identifier)
            ->orWhere('id', $identifier)
            ->first();

        if (! $tenant instanceof Tenant) {
            $this->error("No tenant found for [{$identifier}] — create it first with tenant:create.");

            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('A --name is required — the branch is named by the operator, never invented.');

            return self::FAILURE;
        }

        if ($code === '') {
            $this->error('A --code is required — it is the unique short identifier for this site.');

            return self::FAILURE;
        }

        // Branch is tenant-scoped, so both the uniqueness check and the write need the context.
        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            if (Branch::query()->where('code', $code)->exists()) {
                $this->error("Tenant [{$tenant->slug}] already has a branch with code [{$code}].");

                return self::FAILURE;
            }

            /*
             * The tenant's own zone, not the platform default and not UTC. `SettingsService` is
             * tenant-scoped, so this reads the value `tenant:create` wrote for THIS tenant.
             */
            $timezone = trim((string) $this->option('timezone'));
            if ($timezone === '') {
                $timezone = (string) $settings->get('timezone', (string) config('app.timezone'));
            }

            if (! in_array($timezone, timezone_identifiers_list(), true)) {
                $this->error("[{$timezone}] is not a recognised IANA timezone.");

                return self::FAILURE;
            }

            $firstBranch = ! Branch::query()->exists();

            /*
             * THE EXISTING SERVICE, not a parallel creation path — so the `creating` hook that seeds the
             * exactly-one-primary invariant and the audited `Branch::created` hook both fire exactly as
             * they do for the admin UI. `is_primary` is deliberately absent from this payload.
             */
            $branch = $branches->create([
                'name' => $name,
                'code' => $code,
                'timezone' => $timezone,
                'phone' => ($phone = trim((string) $this->option('phone'))) !== '' ? $phone : null,
            ]);
        } finally {
            $previous instanceof Tenant ? $tenants->set($previous) : $tenants->forget();
        }

        $this->info("Branch created for {$tenant->name}");
        $this->line('  id        '.$branch->id);
        $this->line('  name      '.$branch->name);
        $this->line('  code      '.$branch->code);
        $this->line('  timezone  '.$branch->timezone.($this->option('timezone') ? '' : '  (from the tenant setting)'));
        $this->line('  primary   '.($branch->is_primary ? 'yes' : 'no'));

        if ($firstBranch) {
            $this->newLine();
            $this->line('This is the tenant\'s FIRST branch, so it is their primary site and the day-board');
            $this->line('now has somewhere to render. Next: add bookable resources (rooms/chairs) in the app');
            $this->line('under Admin → Branches — a resource needs availability before it can be booked.');
        }

        return self::SUCCESS;
    }
}
