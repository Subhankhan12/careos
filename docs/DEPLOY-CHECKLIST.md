# DEPLOY-CHECKLIST.md — the ordered list a person follows on the server

**Written 2026-09-14 against `be9adc7`.** Every step carries its command, the output that means success, and
what breaks if it is skipped. Steps are marked:

- **✅ VERIFIED HERE** — actually driven on this machine against a scratch database (see §Dry run below).
- **🖥️ SERVER-ONLY** — cannot be verified on a dev box; unverified here, and flagged as such.

This supplements `docs/DEPLOY-RUNBOOK.md` (which has the full commands and file contents). The runbook tells
you *how*; this tells you *in what order, and how to know it worked*.

---

## 0. Read this first — three things that fail SILENTLY

These cost an afternoon each because the app reports success.

| # | Trap | Symptom | Check |
|---|---|---|---|
| 1 | `QUEUE_CONNECTION=database` (the code default) | Horizon dashboard looks healthy and processes nothing. Jobs pile into the MySQL `jobs` table | Step 12 |
| 2 | `MAIL_MAILER=log` (the code default) | Every message is accepted with **no exception** and written to `storage/logs/laravel.log`. Password resets, invites, reminders, dunning and the **email-only** operator owner-approval all vanish | Step 13 |
| 3 | **🔴 `reminders` queue has no consumer — OPEN DEFECT, not a config error** | Appointment reminders never send **even with `QUEUE_CONNECTION=redis` set correctly** | Step 12b |

> **⚠️ Trap 3 is a live code defect found by this dry run and NOT yet fixed.**
> `Modules/Scheduling/src/Services/ReminderDispatcher.php:72-74` dispatches `->onQueue('reminders')`. It is the
> **only `onQueue()` in the entire codebase.** `config/horizon.php`'s sole supervisor consumes
> `'queue' => ['default']`. **Measured:** with `QUEUE_CONNECTION=redis`, redis `reminders` = 1 job, redis
> `default` = 0, Horizon consuming `["default"]`. The job waits forever.
> **Remedy (one line, not applied here — this was a read-only task):** in `config/horizon.php`, make the
> supervisor's queue `['default', 'reminders']`. Do this **before** go-live or accept that no appointment
> reminder will ever be delivered.

---

## Phase A — the host (🖥️ SERVER-ONLY, none of this is verifiable on a dev box)

### 1. Provision the host and packages 🖥️
`docs/DEPLOY-RUNBOOK.md` §3. Ubuntu, PHP 8.2 (`php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis
php8.2-mbstring …`), MySQL 8, redis-server, nginx, supervisor, certbot, Node.
**If skipped:** nothing runs.

### 2. ⚠️ VERIFY THE REDIS EXTENSION — the step this checklist adds 🖥️
```bash
php -m | grep -i redis        # expect: redis
```
**Expected:** the line `redis`.
**If it is missing and you proceed:** the first request or artisan command that touches cache, queue or
session dies with a hard fatal — **`Error: Class "Redis" not found`** — because `config/database.php`
defaults `REDIS_CLIENT` to `phpredis`. **Measured on this machine** (phpredis absent): that exact fatal.
**Either** install `php8.2-redis`, **or** set `REDIS_CLIENT=predis` in `.env` (the `predis/predis ^3.5`
package is already required by composer, and is what development runs on).
**Why this step exists:** the runbook installs the extension and documents the predis alternative, so this is
not a documentation defect — but the failure is a fatal with a misleading message, and it is a leading
candidate for the undiagnosed staging error (§Parked error below).

### 3. MySQL 8 database and user 🖥️
Runbook §4. **If skipped:** step 8 fails to connect.

### 4. Clone, `composer install --no-dev --optimize-autoloader` 🖥️

### 5. `.env` from the template 🖥️
Copy `docs/DEPLOY-ENV.production.template`. Fill **every** MUST-FILL key (§MUST-FILL below).
```bash
php artisan key:generate --force
```
**If `APP_KEY` is missing:** boot fails, and every encrypted value — including `two_factor_secret` — is
unreadable.

### 6. Build the frontend ON the server 🖥️
```bash
npm ci && npm run build      # and npm run build:pwa if the nurse PWA is served
```
**Expected:** `public/build/manifest.json` exists.
**If skipped:** every authenticated page 500s on a missing Vite manifest. `public/build` is **not** committed.

### 6b. If you run the test suite on the server, raise PHPStan timeout first
```bash
grep -A2 "parallel" phpstan.neon     # processTimeout defaults to 600s
```
**Measured on the dev box:** `composer check` aborted with *"Internal error: Child process timed out
after 600.0 seconds ... while communicating with parallel worker"* at 9% of 871 files, ending in
**"Result is incomplete because of severe errors"**. Run alone on a quiet machine the same analysis
returned **`[OK] No errors`**, so this is CONTENTION, not a code defect. The danger is that the run
*looks* like a failure and its exit code is unreliable either way (RULE 3: read the log text).
**If you hit it:** add `parallel: processTimeout: 1200` to `phpstan.neon`, or run the analysis when
nothing else is competing. **Do not interpret it as a code error** — check whether any actual error
lines were printed before the internal error, and re-run before concluding anything.

### 7. Permissions, nginx, TLS 🖥️
Runbook §5, §8, §9.

---

## Phase B — the application (✅ all of this was driven on a scratch database)

> **Run these in this order.** Steps 8–11 were driven end to end here; the outputs below are the real ones.

### 8. Migrate ✅ VERIFIED HERE
```bash
php artisan migrate --force
```
**Expected:** every migration `DONE`. Measured on a fresh database: **216 migrations, 0 pending, 178 tables.**
Verify with `php artisan migrate:status` — expect **no** `Pending`.
**If skipped:** nothing works.
**Note:** the 42-row permission catalog is created by *migrations*, before any seeder runs.

### 9. Seed the production catalogs ✅ VERIFIED HERE
```bash
php artisan db:seed --force
```
**Expected, verbatim:**
```
INFO  Seeding database.
Database\Seeders\PermissionCatalogSeeder ....... DONE
Database\Seeders\PlanCatalogSeeder ............. DONE
```
`DatabaseSeeder` calls **only** these two. It contains **no demo seeder** — `db:seed --force` is safe and
required in production.
**If skipped:** `plans` is empty, every tenant has no plan, and **every plan-gated feature (telehealth, EVV,
ai_drafting) is silently OFF**.

### 10. Confirm the plans ✅ VERIFIED HERE
```bash
php artisan plans:seed
```
**Expected, verbatim:**
```
Plans seeded: 2 total (0 new).
  eu_pro       EU Pro       +telehealth -evv +ai_drafting
  eu_starter   EU Starter   -telehealth -evv -ai_drafting
Assign one to every tenant — a tenant with no plan has every feature OFF.
```
**`(0 new)` is the expected result after step 9** — `db:seed` already ran `PlanCatalogSeeder`. This command is
**idempotent**; run it as a *verification* that plans exist and to read their feature flags.
**If skipped:** nothing breaks provided step 9 ran. If step 9 was skipped, this is the command that saves you.

### 11. Create the customer's tenant ✅ VERIFIED HERE
```bash
php artisan tenant:create "Praxis Example" \
  --slug=praxis-example --plan=eu_pro --region=eu \
  --currency=CHF --locale=de --timezone=Europe/Zurich
```
**Expected:**
```
Tenant created: Praxis Example
  id        01m2crztmea17kkb7hy2wvx4ks
  slug      praxis-example
  region    eu  (immutable)
  status    active
  plan      eu_pro
  roles     26 starter templates seeded
Next: php artisan tenant:add-admin praxis-example --email=... --name="..."
```
**`--region` is IMMUTABLE.** Get it right the first time.
**Defaults if you omit them:** `--plan=eu_pro`, `--region=eu`, `--currency=EUR`, `--locale=en`,
`--timezone=Europe/Zurich`. Set `--currency` and `--locale` deliberately — they drive money and date
rendering for every user in the tenant.

### 12. Bootstrap the first administrator ✅ VERIFIED HERE
```bash
php artisan tenant:add-admin praxis-example \
  --email=admin@praxis-example.test --name="Dr. Example"
```
**Expected:**
```
Administrator created for Praxis Example
  login     admin@praxis-example.test
  role      org_admin (all branches)
  TEMPORARY PASSWORD (shown once — deliver it out of band, then have them change it):
      0bZ54#as2A[.gX#r
First login: they will be REQUIRED to enrol two-factor authentication before
reaching the app. 2FA is mandatory and has no skip path.
```
**The password is printed ONCE.** Capture it before the terminal scrolls.
**If skipped:** nobody can log in. The invite flow structurally cannot create the first admin.

### 12a. ⚠️ Queue driver — the first silent trap 🖥️ *(mechanism ✅ verified here)*
```bash
grep '^QUEUE_CONNECTION' .env       # must be: redis
php artisan horizon:status          # expect: Horizon is running
```
**Measured here:** with `QUEUE_CONNECTION=database`, a dispatched job landed in the MySQL `jobs` table
(`queue=reminders`, 1 row) while **redis held 0 jobs on every queue** — so Horizon idles on an empty queue
and reports nothing wrong. `horizon:status` returns **`Horizon is inactive.`** when Horizon is not running,
which is the *only* loud signal you get.
**If wrong:** every notification and reminder queues forever.

### 12b. 🔴 Apply the `reminders` queue fix — see §0 trap 3 🖥️
```bash
grep -A3 "supervisor-1" config/horizon.php     # queue must include 'reminders'
```
**If skipped:** appointment reminders never send, with a correct-looking config and a healthy Horizon.

### 13. ⚠️ Mail — the second silent trap 🖥️ *(mechanism ✅ verified here)*
```bash
grep '^MAIL_MAILER' .env            # must NOT be 'log'
```
**Measured here** with `MAIL_MAILER=log`: three messages (portal invite, password reset, owner approval) were
accepted with **no exception thrown** and written to **`storage/logs/laravel.log`** — 3 entries, subjects
intact, nothing delivered.
**Measured with a working transport:** all three reached the transport with correct recipients and subjects.
**Measured with a broken SMTP host:** `Symfony\Component\Mailer\Exception\TransportException` — i.e. a
*misconfigured* SMTP fails **loudly**. **`log` is dangerous precisely because it is the only setting that
fails silently.**
**If wrong:** password reset (AUTH-SEC.2), staff invites, appointment reminders, dunning, portal messages and
the **email-only** Operator Mode owner-approval all vanish into the log.

### 14. Start Horizon and the scheduler 🖥️
Supervisor program for `php artisan horizon` (runbook §7), plus one cron line:
```
* * * * * cd /var/www/careos && php artisan schedule:run >> /dev/null 2>&1
```
**If skipped:** all **9** scheduled commands stop (`audit:verify-chains`, `credentials:refresh-status`,
`nursing:materialize-visits`, `clinical:evaluate-recalls`, `hospital:accrue-bed-days`, `billing:dunning-run`,
`billing:reconcile`, `appointments:dispatch-reminders`, `scheduling:expire-waitlist-offers`).
**Known gap (DEFERRED S1):** `audit:ensure-partitions` is **not** scheduled. It degrades rather than fails —
a `p_max` catch-all absorbs rows past the last partition — but add the line before go-live.

---

## Phase C — first login and making the tenant usable

> **This phase is the one the runbook was missing.** Provisioning completes successfully and the tenant is
> still not usable. Everything here was driven on the scratch database.

### 15. First login + mandatory 2FA enrolment ✅ VERIFIED HERE
Sign in with the temporary password. You are redirected to `/two-factor/enrollment`. Scan the QR **or** use
the manual key the page shows, then enter a 6-digit code.
**⚠️ Measured trap:** the first attempt **failed silently** — no error appeared, the page simply stayed — because
the code expired during a slow request. **If enrolment appears to do nothing, the code expired: enter a fresh
one immediately.** Confirm success by checking `users.two_factor_confirmed_at` is no longer `NULL`.
**If skipped:** 2FA is mandatory and has **no skip path** — the admin cannot reach the app at all.

### 16. CREATE THE FIRST BRANCH ✅ VERIFIED HERE
```bash
php artisan tenant:add-branch praxis-example --name="Hauptstandort" --code=HAUPT
```
**Expected:**
```
Branch created for Praxis Example
  id        01m2ctfkd741k7j3h9efaw91rh
  name      Hauptstandort
  code      HAUPT
  timezone  Europe/Zurich  (from the tenant setting)
  primary   yes
```
`--timezone` overrides it; omitted, it takes **the tenant's own timezone**, not UTC. The first branch of a
tenant is automatically its primary site (`Branch::booted()` owns that invariant on every creation path).
The command is not bootstrap-only — use it again for a second site.
**Or in the UI:** `Admin → Branches → Add branch` (`/admin/branches`), a 3-step wizard.
**⚠️ In the UI the timezone select defaults to `UTC`, not the tenant's timezone** — set it explicitly there.
The command does not have this trap.
**If skipped:** the day-board renders its "No branch configured yet" empty state rather than a board. That
is honest, but the practice cannot schedule anything until a branch exists.

> **CORRECTION to this checklist's first edition (`5dac745`).** It said *"There is no artisan command for
> this… and the runbook never says so."* The first half was true then and is now fixed by
> `tenant:add-branch` (DEPLOY-FIX.1a). **The second half was simply wrong**, and it is withdrawn: the
> runbook DOES tell the operator to create branches — `DEPLOY-RUNBOOK.md:501-503` (*"set the practice
> profile … branches, opening hours, timezone … in the app"*, then *"Set up their branch(es) + resources"*)
> and again in the summary sequence at `:556`. What the runbook did not say — and could not have known —
> was that skipping it produced an **HTTP 404** rather than an empty screen. That 404 is now fixed too.

### 17. Create bookable resources and services ✅ *(gap observed here)*
With a branch but no resources, the day board loads and says, honestly:
> *"No bookable resources yet. Rooms and chairs are set up under Admin → Branches. A resource needs its
> availability…"*
**If skipped:** nothing can be booked.

### 18. Smoke-test the tenant ✅ VERIFIED HERE
- `/scheduling/day-board` → 200, rendered in the tenant's locale (verified: German for `--locale=de`).
- Register a patient → verified: `MRN-000001` created on the fresh tenant.
- Confirm **no demo data**: `SELECT slug FROM tenants;` must return only your customer.
  **Measured on the scratch database: 1 tenant, 1 user, 1 branch, 1 patient — no demo tenant.**

### 19. Import the customer's data 🖥️
P0P.G6 CSV tool. Runbook §11.

---

## MUST-FILL `.env` keys

> **Re-derived from `config/*.php` at `be9adc7`.** `config/` is **byte-identical** to the readiness check's
> HEAD (`30ceeee`) — **no env key was added** by the parity programme, the ten QA phases or the fourteen fix
> gates. The list is current.
>
> **Correction:** the readiness check calls this "13 keys". It is **12 table rows / 23 distinct keys**. There
> is no reading of that table that yields 13.

| Key(s) | Code default | If left at the default |
|---|---|---|
| `APP_KEY` | none | Boot failure; every encrypted value unreadable |
| `APP_ENV` | `production` | Keep `production` |
| `APP_DEBUG` | `false` in config, **`true` in `.env.example`** | Stack traces and PHI to the public |
| `APP_URL` | `http://localhost` | Broken mail links; wrong asset URLs |
| `DB_CONNECTION` | **`sqlite`** | Silently targets SQLite, not your MySQL 8 |
| `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` | `laravel`/`root`/blank | Connection failure, or the wrong DB as root |
| `QUEUE_CONNECTION` | **`database`** | §0 trap 1 |
| `CACHE_STORE` | `database` | Slow; wastes the Redis you installed |
| `SESSION_DRIVER` | `database` | As above |
| `REDIS_HOST/PORT/CLIENT` | `127.0.0.1`/`6379`/**`phpredis`** | **Step 2** — fatal `Class "Redis" not found` without the extension |
| `SESSION_SECURE_COOKIE` | **no default → null** | Session cookie not marked `Secure` |
| `MAIL_MAILER` + `MAIL_HOST/PORT/USERNAME/PASSWORD/FROM_ADDRESS` | **`log`** | §0 trap 2 |

**REQUIRED-IF-USED:** `LIVEKIT_HOST/API_KEY/API_SECRET` (telehealth), `MYSQL_ATTR_SSL_CA`, `MAIL_SCHEME`.
**OPTIONAL, degrades honestly:** `ANTHROPIC_API_KEY`, `AICORE_*`, `SANCTUM_STATEFUL_DOMAINS`, `HORIZON_*`,
`LOG_*`, `SESSION_LIFETIME`, `SESSION_SAME_SITE`, `FILESYSTEM_DISK`.

---

## Demo-seeder safety — now enforced by CODE, not convention

> **This supersedes the readiness check**, which says at §8 that it is *"not enforced by code"* and relies on
> the seeders not being wired into `DatabaseSeeder`.

**QA-FIX.9b (`4e610b0`) added a real guard**: each of the four demo seeders refuses to run outside an
allow-list of environments. `DatabaseSeeder` still calls only the two catalog seeders, so **both** protections
now hold: `db:seed --force` is safe in production, and a demo seeder invoked explicitly on a production host
**refuses by its own guard** rather than relying on nobody typing `--class=Demo…`.

---

## The parked staging error — what is actually recorded

**Nothing.** Verified across `PROJECT-STATE.md`, `DEFERRED.md`, `docs/DEPLOY-RUNBOOK.md`, `memory/LOG.md` and
git history: the only substantive mention is `PROJECT-STATE.md:1679` — *"a staging error was hit earlier and
never debugged. It is not diagnosed and not written up."* No command, no error text, no stack trace, no
environment, no date, no runbook step. The readiness check §6 says the same thing in its own words.

**This dry run narrows the candidates it listed:**

| §6 candidate | Status after this dry run |
|---|---|
| M3 — features silently off (no plan) | **Much less likely.** `db:seed --force` seeds plans and `tenant:create` defaults `--plan=eu_pro` |
| `QUEUE_CONNECTION=database` | **Still live.** Verified silent (§0 trap 1) |
| `MAIL_MAILER=log` | **Still live.** Verified silent (§0 trap 2) |
| **`REDIS_CLIENT=phpredis` without the extension** | **⭐ Leading candidate.** Verified to produce a hard fatal, `Class "Redis" not found`. Development runs **predis**, so this class of failure *cannot* surface on a dev box — exactly the shape of an error that appears only on staging |
| stale/absent `public/build` | Still live; step 6 |

### CAPTURE PROTOCOL — run this at the first failure, before attempting any fix

```bash
# 1. the failing command, verbatim, with full verbosity
<the exact command> -vvv 2>&1 | tee /tmp/careos-fail.txt

# 2. the environment
php -v; php -m | sort; php artisan --version
php artisan about                       # redact secrets before sharing
php artisan migrate:status | tail -20

# 3. the logs, at the moment of failure
tail -n 200 storage/logs/laravel.log
sudo supervisorctl status
sudo journalctl -u nginx --since "10 min ago" | tail -50

# 4. the env keys that matter, secrets redacted
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|DB_CONNECTION|DB_HOST|DB_DATABASE|QUEUE_CONNECTION|CACHE_STORE|SESSION_DRIVER|REDIS_CLIENT|REDIS_HOST|MAIL_MAILER|MAIL_HOST)=' .env
```
**Write it up before fixing it.** The whole cost of this item is that it was fixed-or-abandoned once without a
record, so the second occurrence starts from zero again.

---

## The dry run behind this checklist

Driven on 2026-09-14 at `be9adc7`, on a **scratch database** (`careos_deploy_dryrun`), with the demo database
verified untouched throughout (4 tenants / 43 users / 35 patients before and after).

The shell-environment override was proven to target the scratch database **before** any migration ran
(`production` / `careos_deploy_dryrun`, while the control still read `local` / `careos`) — Laravel loads
`.env` immutably, so shell variables win.

**Verified:** steps 8, 9, 10, 11, 12, 15, 16, 17, 18, plus the queue and mail mechanisms behind 12a/12b/13.
**Not verified (server-only):** steps 1–7, 14, 19, and the SMTP leg of 13 — no SMTP server exists on a dev box,
so a working transport was demonstrated with Laravel's `array` transport instead.
