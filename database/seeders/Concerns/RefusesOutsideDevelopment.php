<?php

namespace Database\Seeders\Concerns;

use Database\Seeders\DatabaseSeeder;
use RuntimeException;

/**
 * QA-FIX.9b (D-219) — THE DISPOSABLE-ENVIRONMENT RULE, owned by the seeders that need it.
 *
 * Until this trait, the safety of the demo seeders rested entirely on their NOT being referenced from
 * {@see DatabaseSeeder}. DEPLOY.PROV pinned that wiring — `ProvisioningCommandsTest`
 * asserts `DatabaseSeeder.php` does not contain the string `Demo`, and that `db:seed --force` leaves zero
 * tenants — which is a real and useful test, but it pins a PROPERTY OF THE CURRENT FILE, not a refusal. A
 * future edit wiring a demo seeder in would put a fake clinic, fake patients and fake clinical records
 * into a customer's database, and nothing at runtime would object.
 *
 * The guard therefore lives on the seeder itself, so it holds however the seeder is reached: `--class=`,
 * a call from `DatabaseSeeder`, a nested `$this->call()`, tinker, or a job.
 *
 * PERMITTED: `local` and `testing`, and nothing else. Those are the two environments the product's own
 * development actually uses — `.env`/`.env.example` set `local` (dev, and the QA audit's browser drives)
 * and `phpunit.xml` sets `testing` (the suite, in CI too). The list is an ALLOW-list rather than a
 * `!== 'production'` check, so a newly invented environment name — `staging`, `demo`, `uat` — refuses by
 * default instead of silently qualifying as "not production". Fail closed.
 *
 * `app()->environment(...)` is the repo's own existing idiom (`bootstrap/app.php:80`), not a new one.
 *
 * `DatabaseSeeder` uses this trait too, for {@see isDisposableEnvironment()} rather than the assertion:
 * the production seed path must still run the catalogs, and only the skeleton's super-admin account is
 * conditional. One list, one source of truth.
 */
trait RefusesOutsideDevelopment
{
    /**
     * The only environments in which disposable accounts or demo data may be created.
     *
     * @var list<string>
     */
    private const DISPOSABLE_ENVIRONMENTS = ['local', 'testing'];

    /** Whether this database is disposable — see the class docblock for why the list is an allow-list. */
    protected function isDisposableEnvironment(): bool
    {
        return app()->environment(self::DISPOSABLE_ENVIRONMENTS);
    }

    /**
     * Refuse unless this database is disposable. Called first in a demo seeder's run().
     */
    protected function assertDisposableEnvironment(): void
    {
        if ($this->isDisposableEnvironment()) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s creates demo data and refuses to run in the "%s" environment (permitted: %s).',
            static::class,
            (string) app()->environment(),
            implode(', ', self::DISPOSABLE_ENVIRONMENTS),
        ));
    }
}
