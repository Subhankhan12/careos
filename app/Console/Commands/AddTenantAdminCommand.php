<?php

namespace App\Console\Commands;

use App\Services\StaffInviteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Http\Middleware\EnsureTwoFactorEnabled;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

/**
 * DEPLOY.PROV (M2) — create the FIRST org_admin of a tenant.
 *
 * WHY THIS EXISTS — the chicken-and-egg. Every other way to create staff runs through
 * {@see StaffInviteService}, which requires an ALREADY-AUTHENTICATED admin
 * holding `admin.manage` to send the invite. So the first administrator of a brand-new
 * tenant could never be invited: there was nobody to send it. There is also no
 * `User::create` anywhere else in production code. This command is the one bootstrap
 * that needs no existing user — and it is deliberately the ONLY one.
 *
 * After this, normal service resumes: every subsequent staff member arrives through the
 * real invite flow, which this command does not touch or weaken.
 *
 * IT DOES NOT WEAKEN 2FA. The new user is created with no `two_factor_secret`, so
 * {@see EnsureTwoFactorEnabled} redirects them to
 * `/two-factor/enrollment` on first login and they cannot reach the app until they have
 * enrolled. Mandatory 2FA (SETTINGS.P4) still applies in full — there is no skip path,
 * and this command does not create one.
 *
 * Safe by construction: refuses when the tenant already has an org_admin (this is a
 * BOOTSTRAP, not a user-management tool — use the invite flow for the second admin), and
 * refuses a duplicate email. Both checks happen before anything is written.
 */
class AddTenantAdminCommand extends Command
{
    protected $signature = 'tenant:add-admin
        {tenant : Tenant slug or id}
        {--email= : The administrator email (their login)}
        {--name= : Their display name}
        {--password= : Temporary password. Generated and printed once when omitted}';

    protected $description = 'Create the FIRST org_admin of a tenant (the bootstrap the invite flow cannot do).';

    public function handle(TenantContext $tenants): int
    {
        $identifier = (string) $this->argument('tenant');
        $email = strtolower(trim((string) $this->option('email')));
        $name = trim((string) $this->option('name'));

        $tenant = Tenant::query()
            ->where('slug', $identifier)
            ->orWhere('id', $identifier)
            ->first();

        if (! $tenant instanceof Tenant) {
            $this->error("No tenant found for [{$identifier}] — create it first with tenant:create.");

            return self::FAILURE;
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid --email is required — it is the administrator login.');

            return self::FAILURE;
        }

        if ($name === '') {
            $this->error('A --name is required.');

            return self::FAILURE;
        }

        // Email is globally unique (one human, one login — multi-tenant membership is deferred).
        if (User::query()->where('email', $email)->exists()) {
            $this->error("A user with email [{$email}] already exists.");

            return self::FAILURE;
        }

        $role = $tenants->system(fn (): ?Role => Role::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', 'org_admin')
            ->first());

        if (! $role instanceof Role) {
            $this->error('This tenant has no org_admin role template — was it created with tenant:create?');

            return self::FAILURE;
        }

        // BOOTSTRAP ONLY: if an org_admin already exists there is someone who can invite
        // the next one, so this command steps aside rather than becoming a second path.
        $existing = $tenants->system(fn (): int => RoleAssignment::query()
            ->where('role_user.tenant_id', $tenant->getKey())
            ->where('role_id', $role->getKey())
            ->count());

        if ($existing > 0) {
            $this->error("Tenant [{$tenant->slug}] already has {$existing} org_admin(s).");
            $this->line('  This command only bootstraps the FIRST one — invite further staff from inside the app.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(16));

        // RoleAssignment is tenant-scoped, so the write needs the context.
        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $user = DB::transaction(function () use ($tenant, $name, $email, $password, $role): User {
                // The REAL user-creation path — the model hash-casts the password.
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                ]);

                // tenant_id is not fillable; set it explicitly, as the invite flow does.
                $user->forceFill(['tenant_id' => $tenant->getKey()])->save();

                // The REAL RBAC path — RoleAssignment::create fires the audited role.assigned.
                // branch_id null = ALL branches: a branch-scoped assignment does not answer
                // gate checks that pass no branch.
                RoleAssignment::create([
                    'user_id' => $user->getKey(),
                    'role_id' => $role->getKey(),
                    'branch_id' => null,
                ]);

                return $user;
            });
        } finally {
            $previous instanceof Tenant ? $tenants->set($previous) : $tenants->forget();
        }

        $this->info("Administrator created for {$tenant->name}");
        $this->line("  login     {$user->email}");
        $this->line("  name      {$user->name}");
        $this->line('  role      org_admin (all branches)');

        if (! $this->option('password')) {
            $this->newLine();
            $this->warn('  TEMPORARY PASSWORD (shown once — deliver it out of band, then have them change it):');
            $this->line("      {$password}");
        }

        $this->newLine();
        $this->comment('First login: they will be REQUIRED to enrol two-factor authentication before');
        $this->comment('reaching the app. 2FA is mandatory and has no skip path.');

        return self::SUCCESS;
    }
}
