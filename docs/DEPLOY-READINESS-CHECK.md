# CareOS — PRE-DEPLOY READINESS CHECK (audit only)

**Audit + report only. No server was touched, nothing was provisioned, no app code changed.** Run on the dev
box against the repo at `30ceeee`, CI green, tree clean.

- **Date:** 2026-08-18 · **HEAD:** `30ceeee` · **CI:** `check -> completed / success`
- **Assets audited:** `docs/DEPLOY-RUNBOOK.md` (11 sections) · `docs/DEPLOY-ENV.production.template` (50 keys)
- **Method:** every claim below was derived from `config/*.php`, the migrations, `routes/`, the CI workflow and
  the seeders — not from the runbook's own prose.

---

> **UPDATE — DEPLOY.PROV (2026-08-18): M1, M2, M3 and M4 are RESOLVED. The verdict is now 🟢 GO.**
> `tenant:create`, `tenant:add-admin` and `plans:seed` exist and are tested; the runbook has a MUST-FILL
> section, runs the production catalog seed in its release sequence, and documents the two provisioning
> commands in place of the old "create their tenant" hand-wave. The two SHOULD-FIXes (S1 unscheduled
> partitions, S2 unguarded demo seeders) remain open and are still non-blocking.
> **One correction this gate surfaced: the role-template count is 26, not the 17 stated below and in the
> runbook — counted from `RbacProvisioner::ROLE_TEMPLATES`. Both documents are now fixed.**
> The original audit is preserved unedited below, since it is what the fixes were built against.

## VERDICT: 🟡 **CONDITIONAL GO** → 🟢 **GO** (see the update above)

**The application is deploy-ready. The gaps are in PROVISIONING and the RUNBOOK, not in the code.**

The build is complete and green (Pest 1250 / 16,351 assertions, CI green on MySQL 8), both production builds
compile clean at HEAD, the `.env` template is accurate (**every one of its 50 keys is genuinely read by
`config/*.php` — verified by set-difference, zero unexplained keys**), and the MySQL-8 parity story is proven by
CI rather than asserted.

**What blocks a first customer is that there is no documented way to create one.** Three provisioning gaps
(§8) and two runbook omissions must be closed first. None requires an application-code change; all are
process/documentation, and each is small.

| | Finding | Severity |
|---|---|---|
| **M1** ✅ **RESOLVED (DEPLOY.PROV)** | **No tenant-creation path exists.** `Tenant::create` appears ONLY in the three demo seeders — no route, no controller, no artisan command. Runbook §11.1 says "Create their tenant" without a mechanism. | 🔴 **BLOCKER** |
| **M2** ✅ **RESOLVED (DEPLOY.PROV)** | **No first-user bootstrap.** There is no `User::create` anywhere in production code outside `StaffInviteService`, which itself requires an already-authenticated admin. Chicken-and-egg for the first org_admin. | 🔴 **BLOCKER** |
| **M3** ✅ **RESOLVED (DEPLOY.PROV)** | **Plans are never seeded.** The runbook's release sequence (§6) runs `migrate --force` but **never `db:seed`**; the only `db:seed` in the document seeds *demo tenants* (§10). `tenants.plan_id` is nullable, and `FeatureService` falls through to `false` when a tenant has no plan — so **every plan-gated feature (telehealth, EVV, ai_drafting) is silently OFF**. | 🔴 **BLOCKER** (silent) |
| **M4** ✅ **RESOLVED (DEPLOY.PROV)** | The `.env` MUST-FILL set (§2) — 13 keys with no safe default. Now a section in the runbook. | 🔴 **BLOCKER** |
| **S1** | `audit:ensure-partitions` exists but is **not scheduled**. Degrades rather than fails (a `p_max` MAXVALUE catch-all absorbs everything), but monthly partitioning/retention is lost. | 🟠 Should-fix |
| **S2** | The four demo seeders carry **no production guard** — no `App::environment()` check, no abort. | 🟠 Should-fix |

---

## 1 — Runbook ↔ template consistency

**The runbook prescribes, in order:** (0) decisions → (1) provision host: PHP 8.2 + `pdo_mysql`/`dom`/`curl`/
`redis`, Composer, Node 22, MySQL 8, Redis, Nginx, Supervisor, Certbot → (2) create DB + user, secure Redis →
(3) clone + `composer install --no-dev --optimize-autoloader` + **both** frontend builds → (4) write `.env` →
(5) `key:generate` + storage link/permissions → (6) `migrate --force` + `migrate:status` + the four caches →
(7) **Horizon under Supervisor + the scheduler cron** → (8) Nginx + HTTPS → (9) the post-deploy checklist →
(10) *optional* demo tenants → (11) per-customer onboarding.

**Consistency: ✅ verified mechanically.**

- **Every template key is read by the app.** Set-difference of the 50 template keys against the 145 `env()`
  keys in `config/*.php`: **zero unexplained keys**.
- **Nothing the app needs is missing.** The config keys absent from the template are all framework boilerplate
  for drivers this deployment does not use (AWS/S3, Memcached, Beanstalkd, SQS, DynamoDB, Slack, Papertrail,
  Postmark, Resend) plus safe-defaulted internals. None is required under the chosen mysql/redis/smtp/local
  driver set.
- **✅ Zero `env()` calls outside `config/`** — so `config:cache` is safe and cannot strand a runtime lookup.
- **Two deliberate footguns are already called out in both documents** and are real: the code reads
  `LIVEKIT_HOST` (**not** `LIVEKIT_URL` — setting the latter leaves the host at `https://livekit.invalid` and
  telehealth fails silently), and Laravel 12 uses `MAIL_SCHEME` (**not** `MAIL_ENCRYPTION`).
- **⚠️ The one inconsistency: `db:seed` is absent from the release sequence** — see **M3**.

---

## 2 — THE MUST-FILL `.env` LIST

Derived from the real defaults in `config/*.php`. "Failure mode" is what actually happens if left at the code
default.

### REQUIRED — a real value, or the app is wrong (not merely degraded)

| Key | For | Code default | Failure mode if defaulted/wrong |
|---|---|---|---|
| `APP_KEY` | The app's only encryption key | *(none)* | **Boot failure**, and every encrypted value (incl. `two_factor_secret`) is unreadable. Generate on the server: `php artisan key:generate`. Never reuse across environments. |
| `APP_ENV` | Environment | `production` | Must be `production`. |
| `APP_DEBUG` | Debug output | `true` in `.env.example` | 🔴 **Stack traces + config/PHI leakage to the public.** Must be `false`. |
| `APP_URL` | Absolute URL generation | `http://localhost` | Broken links in mail (password reset, 2FA, portal, owner-approval), wrong asset URLs, and **Sanctum's stateful-domain default derives from it**. |
| `DB_CONNECTION` | DB driver | **`sqlite`** | 🔴 App silently targets SQLite — **not** the MySQL 8 you provisioned. Must be `mysql`. |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Connection | `laravel` / `root` / *(blank)* | Connection failure, or connecting to the wrong DB as root. Use a least-privilege app user. |
| `QUEUE_CONNECTION` | Queue driver | **`database`** | 🔴 **The classic silent failure.** Horizon consumes only `redis` (`config/horizon.php` → `connection: redis`, `queue: ['default']`). Left at `database`, **Horizon reports healthy and processes nothing** — appointment reminders and every notification never send. Must be `redis`. |
| `CACHE_STORE` | Cache | `database` | Works, but slow, and it defeats the Redis you provisioned. Set `redis`. |
| `SESSION_DRIVER` | Sessions | `database` | Same. Set `redis`. |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_CLIENT` | Redis | `127.0.0.1` / `6379` / `phpredis` | If `REDIS_CLIENT=phpredis`, the `php8.2-redis` extension **must** be installed, else runtime failure. Use `predis` to avoid the extension. |
| `SESSION_SECURE_COOKIE` | Secure cookie flag | **`env(...)` with NO default → `null`** | 🔴 The session cookie is **not** marked `Secure`, so it can be sent over plaintext. Must be `true` (the AUTH-SEC posture — the remember-me/2FA work assumes an HTTPS-only session floor). |
| `MAIL_MAILER` + `MAIL_HOST`/`PORT`/`USERNAME`/`PASSWORD`/`FROM_ADDRESS` | Real SMTP | **`log`** | 🔴 **Every message is silently written to the log and nothing is delivered.** This breaks: password reset (AUTH-SEC.2), staff invites, appointment reminders, dunning, portal messages, and the **Operator Mode owner-approval notification** — which is email-only, so an operator request would never reach an owner. |

### REQUIRED-IF-USED

| Key | For | Default | Failure mode |
|---|---|---|---|
| `LIVEKIT_HOST` / `LIVEKIT_API_KEY` / `LIVEKIT_API_SECRET` | Telehealth | **`https://livekit.invalid`** / `''` / `''` | Telehealth fails to connect. **Use `LIVEKIT_HOST` — `LIVEKIT_URL` is not read.** Omit the block entirely if telehealth is not sold. |
| `MYSQL_ATTR_SSL_CA` | TLS to a managed MySQL | *(none)* | Only needed if the DB is remote and requires TLS. |
| `MAIL_SCHEME` | Implicit-TLS SMTP | *(none)* | Leave unset for port 587 (STARTTLS); set `smtps` **with** `MAIL_PORT=465`. |

### OPTIONAL — degrades gracefully, honestly

| Key | Default | Behaviour if absent |
|---|---|---|
| `ANTHROPIC_API_KEY` | *(none)* | ✅ **The app boots normally.** The AiCore circuit breaker degrades every AI call; agents stay draft-until-approved and never enter the clinical-decision path. The front-desk agent and KB answers simply do not work. Safe to omit for a non-AI tenant. |
| `AICORE_REGION` | `eu` | Keep `eu` for EU data residency. |
| `AICORE_DEFAULT_MONTHLY_BUDGET_MINOR` | `5000` | Per-tenant AI spend cap. |
| `SANCTUM_STATEFUL_DOMAINS` | derives from `APP_URL` | Set only for a split API/SPA domain. |
| `HORIZON_PATH` / `_DOMAIN` / `_NAME` | `horizon` | Cosmetic. `/horizon` is already gated to super-admins. |
| `LOG_*`, `SESSION_LIFETIME`, `SESSION_SAME_SITE`, `FILESYSTEM_DISK` | safe | `FILESYSTEM_DISK=local` selects the **private** disk; PHI paths hardcode `disk('local')` regardless, so PHI stays private either way. |

---

## 3 — MySQL 8 vs MariaDB 10.4

**Dev is MariaDB 10.4; CI and production are MySQL 8.** The divergences are known, handled, and documented in
`docs/DB-PARITY.md`.

**Proven by CI, not asserted.** `.github/workflows/ci.yml` runs `mysql:8` + `redis:7` and executes
`php artisan migrate --force` followed by an explicit **"Assert zero pending migrations (MySQL 8 from-scratch
parity)"** step, then the full suite plus both frontend builds. **CI is green at HEAD `30ceeee`** — so
migrations and the suite provably run clean from scratch on MySQL 8.

**The divergences this project has actually hit:**

1. **Implicit `ON UPDATE CURRENT_TIMESTAMP`** — MariaDB 10.4 silently gives the first non-nullable `TIMESTAMP`
   an on-update clause; MySQL 8 does not. **Rule (P0P.G15): mutable moments are `dateTime()`, never
   `timestamp()`.** Enforced by `MutableMomentParityTest`, which scans `information_schema` on whatever engine
   it runs on. Followed by all three Operator Mode migrations.
2. **JSON normalisation** — MySQL 8 re-serialises JSON columns (space after the colon, keys reordered), so a
   raw-substring assertion passes on MariaDB and fails on MySQL 8. This shipped CI-red once (APPT.P2, fixed in
   `27fa22c`). **Standing rule: `json_decode` and assert the meaning.**
3. **FULLTEXT `ngram`** — MySQL 8 only; the patients migration falls back to a plain FULLTEXT index.
4. **Spatial NOT NULL**, **CHECK constraints**, **`SIGNAL` triggers**, **RANGE partitioning** — all handled and
   identical across engines.

**Production settings to confirm — all three are already neutralised in code, so confirm rather than change:**

- **`sql_mode`:** `config/database.php` sets `'strict' => true`, so Laravel applies the same explicit sql_mode
  (incl. `ONLY_FULL_GROUP_BY`, `STRICT_TRANS_TABLES`) on every connection regardless of server default. ✅
- **charset/collation:** pinned to `utf8mb4` / **`utf8mb4_unicode_ci`** — deliberately *not* MySQL 8's default
  `utf8mb4_0900_ai_ci`. Create the database with the same collation so later tables match.
- **timezone:** ✅ **A non-issue, verified.** `config/app.php` hardcodes `'timezone' => 'UTC'` (not env-driven),
  and there are **zero** raw `NOW()` / `CURRENT_TIMESTAMP` uses in application code — every recorded moment
  comes from PHP Carbon. The MySQL session timezone therefore cannot shift a stored moment.

---

## 4 — Horizon / queue / scheduler

**Green tests do not run daemons. Two long-running processes must exist beyond the web server.**

| Process | How to start | Failure mode if absent |
|---|---|---|
| **Horizon** (queue workers) | Supervisor program (runbook §7), `autostart=true, autorestart=true`, `php artisan horizon` | 🔴 **Nothing queued ever runs.** Appointment reminders and all `SendNotificationJob` deliveries queue in Redis forever. Requires `QUEUE_CONNECTION=redis` — otherwise Horizon idles on an empty redis queue while jobs pile into the database driver. |
| **The scheduler** | One cron line: `* * * * * cd /var/www/careos && php artisan schedule:run >> /dev/null 2>&1` | 🔴 **All 9 scheduled commands stop.** |

**The 9 scheduled commands** (`routes/console.php`, each `withoutOverlapping()` + `onOneServer()`):

| Command | Cadence | If it never runs |
|---|---|---|
| `audit:verify-chains` | 01:30 | Audit-chain tampering goes undetected |
| `credentials:refresh-status` | 02:10 | Expired staff credentials keep reading as valid |
| `nursing:materialize-visits` | 02:20 | The rolling 8-week visit horizon stops — **the dispatcher board empties** |
| `clinical:evaluate-recalls` | 02:30 | Patient recalls never become due |
| `hospital:accrue-bed-days` | 05:30 | **Bed-day charges silently stop accruing** — inpatient revenue is lost, invisibly |
| `billing:dunning-run` | 06:00 | The dunning ladder halts |
| `billing:reconcile` | 06:30 | **The launch-blocker δ=0 alarm never fires** |
| `appointments:dispatch-reminders` | every 15 min | Reminders never even enqueue (needs Horizon too) |
| `scheduling:expire-waitlist-offers` | every 5 min | **Held waitlist slots never release** — a freed slot stays blocked forever |

**Not scheduled, deliberately:** there is **no operator-request-TTL sweeper in production**, and that is correct
— Operator Mode has no HTTP surface (G4–G11 deferred, D-164), so no live requests exist to sweep.
`OperatorGrantService::expireDueRequests()` exists and is tested; wire it only when G4 lands.

**Not used:** no Reverb/websockets (no broadcast driver configured; the UI polls).

---

## 5 — Assets / build

**Verified clean at HEAD** (both run during this audit):

- `npm run build` → `public/build/` + `manifest.json` — ✅ built in 23.7s.
- `npm run build:pwa` → `public/nurse-pwa/sw.js` + `workbox-*.js`, 6 precache entries — ✅ generated.

**Both are required and produce disjoint outputs — the main build never contains the PWA.**

⚠️ **Both output directories are gitignored** (`.gitignore:5-6`), so **they do not arrive with `git pull`** and
**must be built on the server** (or shipped as a CI artifact) on every release.

| Missing/stale | Failure mode |
|---|---|
| `public/build/manifest.json` | Every Inertia page fails to boot — `@vite` cannot resolve any asset. A blank/500 app. |
| Stale `public/build` after a release | Users get the old JS against new server props — subtle, hard-to-diagnose breakage. |
| `public/nurse-pwa/sw.js` | The offline Nurse PWA will not install or serve offline; nurses lose day-pack sync. |

---

## 6 — The parked staging error

**No detail exists. Stated plainly: this is a bare flag, not a report.**

Every mention across `PROJECT-STATE.md`, `DEFERRED.md` and `memory/LOG.md` is a *pointer* to the same
undiagnosed item — `PROJECT-STATE.md` itself says *"It is not diagnosed and not written up."* **No symptom, no
error text, no failing step and no environment detail was ever captured.**

**Consequence for the deployment:** treat it as **the first live-debug item, with zero prior information.** Do
not expect a known fix. Concretely: run the runbook from a clean host and **capture everything at the first
failure** — the exact command, full stderr, `storage/logs/laravel-*.log`, `php artisan about`, and
`sudo supervisorctl status` — then write it up *before* attempting a fix, so it is never lost twice.

Given this audit, the most likely candidates are, in order: **M3** (features silently off because the tenant has
no plan), **`QUEUE_CONNECTION`** left at `database`, **`MAIL_MAILER`** left at `log`, a missing `php8.2-redis`
with `REDIS_CLIENT=phpredis`, or a stale/absent `public/build`.

---

## 7 — Hammer tests on deploy hardware

**Current risk: NONE — conditional only.** ✅ **The runbook never runs the test suite on the server** (verified:
no `composer check` / `pest` / `artisan test` anywhere in it), and **there is no deploy workflow** — `ci.yml` is
the only GitHub workflow. §9 is a manual functional checklist.

**But if a deploy-gate is ever added that runs the suite on the deploy box, expect intermittent red.** The 7
`*ParallelHammerTest` files each spawn **8 concurrent full-app `artisan` boots** against a **30-second
per-process ceiling** (`new Process([...], base_path(), null, null, 30)`). On contended hardware those boots
exceed 30s and the test dies with **`ProcessTimedOutException`** — a timeout, **not** a failed invariant. This
happened once locally at 145s; the same test passes in isolation at ~138s, right at the edge.

**Recommended handling — in preference order, none of which weakens the tests:**

1. **Do not run the full suite on the deploy box at all.** CI already proves the suite on MySQL 8; re-running it
   on production hardware tests the hardware, not the code. Use the §9 functional checklist instead.
   *(Preferred — and it is the current state, so this is a "keep doing this".)*
2. If a deploy-gate suite is wanted, **exclude the hammer group** from that run (they are concurrency proofs for
   CI, not smoke tests) and run `composer test:smoke` instead — 4 tests, ~2 minutes, real middleware stack.
3. If they must run on the box, **raise the per-process ceiling** (30s → 120s). That changes only how long the
   test waits, not what it asserts — the invariant (exactly one winner) is untouched.
4. **Do not** run them serially — the parallelism *is* the assertion.

**Report only — no test was changed.**

---

## 8 — First-customer provisioning ⚠️ (the blockers)

**M1 — There is no way to create a tenant.** Verified: `Tenant::create` appears in the codebase only inside
`DemoClinicSeeder`, `DemoDentalSeeder` and `DemoHospitalSeeder` (plus the provider that registers the `created`
hook). There is **no route, no controller and no artisan command**. The `/admin` shell is a single stub page,
and `resources/js/pages/Admin/` contains only *tenant-scoped* admin screens (Branches, Roles, Settings…) — the
Super-Admin **Tenants** console is an Operator Mode screen (G6+), deliberately not built.

**The only working mechanism at the time of this audit was tinker** (SUPERSEDED by `tenant:create` in DEPLOY.PROV — use the command, not this). The good news: it is a genuinely complete path, because
`Tenant::created` fires `RbacProvisioner::provisionTenant()`, which **calls `syncPermissionCatalog()` first** —
so the permission catalog self-heals and all **26 role templates** are seeded for the new tenant automatically. *(Counted at DEPLOY.PROV; this document originally said 17, inherited from the runbook rather than counted.)*

```bash
php artisan tinker
>>> $t = \Modules\Platform\Models\Tenant::create([
...   'name' => 'Praxis Example', 'slug' => 'praxis-example',
...   'region' => 'eu', 'status' => 'active',
... ]);                                    # fires RbacProvisioner -> 26 role templates
>>> $t->plan_id = \Modules\Platform\Models\Plan::where('key','eu_pro')->value('id');  # M3 — do NOT skip
>>> $t->save();
```

**M2 — There is no first-user bootstrap.** There is **no `User::create` in production code** outside
`StaffInviteService`, and that service is the *invite* path, which presupposes an already-authenticated admin
holding `admin.manage`. So the **first** org_admin of a tenant cannot be invited — it must be created directly
and assigned the `org_admin` role, again via tinker. **2FA then bootstraps itself:** the mandatory-2FA
middleware routes the un-enrolled user to `/two-factor/enrollment`, and AUTH-VIS added the "Can't scan?" manual
secret — so the first admin enrols unaided, and every subsequent user arrives through the real invite flow.

**M3 — Plans are never seeded.** `php artisan db:seed --force` (which runs `PermissionCatalogSeeder` +
`PlanCatalogSeeder`) is **absent from the runbook's release sequence**. `tenants.plan_id` is nullable and
`FeatureService` returns `false` for any feature when the tenant has no plan — so **telehealth, EVV and
ai_drafting are silently off**. Run the seeder, then assign a plan to every tenant.

### Demo seeders must NOT run in production — ⚠️ **not enforced by code**

**Audited: the four demo seeders contain ZERO production guard** — no `App::environment()` check, no abort.

**Mitigating (and why this is 🟠 not 🔴):** they are **not** wired into `DatabaseSeeder`, which calls only
`PermissionCatalogSeeder` and `PlanCatalogSeeder`. So **`php artisan db:seed --force` is safe and required in
production**; a demo tenant can only appear if someone explicitly types `--class=Demo…`. The runbook's §10
documents doing exactly that for a *demo instance* — the risk is copying that line onto a customer host.

**Rule for a customer instance: run `db:seed --force` (catalogs only). NEVER run any `--class=Demo*` seeder.**
Recommended hardening (its own small gate, not this audit): add
`if (app()->environment('production')) { $this->command->error('Demo seeders are non-production.'); return; }`
to each demo seeder.

---

## 9 — THE ORDERED PRE-DEPLOY CHECKLIST

**Before touching a server**

1. [ ] Decide the domain, and whether telehealth (LiveKit) and AI (Anthropic) are in scope for this customer.
2. [ ] **Close M1/M2:** agree the tenant + first-admin bootstrap (the tinker snippets in §8, or write a small
       `tenant:create` command as its own gate). Rehearse it once on a throwaway DB.
3. [ ] **Fix the runbook:** add `php artisan db:seed --force` to §6, and the §8 bootstrap to §11.1.

**Provision (runbook §1–2)**

4. [ ] Host + PHP 8.2 with `pdo_mysql`, `dom`, `curl`, and `redis` **if** `REDIS_CLIENT=phpredis`.
5. [ ] MySQL 8 database created **`utf8mb4` / `utf8mb4_unicode_ci`**; least-privilege app user; Redis with
       `requirepass`.

**Code + assets (§3)**

6. [ ] Clone; `composer install --no-dev --optimize-autoloader`.
7. [ ] **`npm ci && npm run build && npm run build:pwa`** — both; the outputs are gitignored and never arrive
       from git.

**Configure (§4–5)**

8. [ ] Write `.env` from the template; fill **every REQUIRED key in §2**.
9. [ ] Re-read three lines before moving on: **`APP_DEBUG=false`**, **`QUEUE_CONNECTION=redis`**,
       **`SESSION_SECURE_COOKIE=true`**.
10. [ ] `php artisan key:generate` · `storage:link` · storage/cache writable by the web user.

**Database (§6)**

11. [ ] `php artisan migrate --force` → `php artisan migrate:status` shows **zero pending**.
12. [ ] **`php artisan db:seed --force`** ← the missing step (catalogs only; **never** `--class=Demo*`).
13. [ ] `config:cache && route:cache && view:cache && event:cache` — **after** the `.env` is final.

**Daemons (§7) — the part people forget**

14. [ ] Horizon under Supervisor (`autostart`/`autorestart`); `supervisorctl status` → RUNNING.
15. [ ] The scheduler cron line installed; confirm with `php artisan schedule:list`.
16. [ ] *(Optional, S1)* schedule `audit:ensure-partitions` monthly.

**Serve (§8)**

17. [ ] Nginx → `public/`; HTTPS with a valid cert matching `APP_URL`.

**Verify (§9)**

18. [ ] Trigger a 404 → clean page, **no stack trace**.
19. [ ] `php artisan about` → env=production, cache/queue/session = redis, config cached.
20. [ ] **Send a real email** (a password reset) → it arrives. Proves `MAIL_*` and kills the `log`-driver trap.
21. [ ] Horizon processing; scheduler fired; a bogus id → **404 not 500**; PHI not publicly reachable; a
        low-permission role → 403.

**First customer (§11)**

22. [ ] Create the tenant (§8) → **assign a plan** (M3) → branches / opening hours / timezone.
23. [ ] Create the first org_admin → they self-enrol 2FA → every later user via the real invite flow.
24. [ ] ⚠️ **Seed resource availability programmatically** — there is no admin UI for it, and **until a resource
        has availability rows the slot finder returns zero bookable slots**, so scheduling looks broken.
25. [ ] Roles (`doctor` for a dentist — there is no `dentist` template) → fee schedule → CSV import
        (dry-run → commit).
26. [ ] Backups on (DB + `storage/app/private`), and confirm a restore.

---

## Summary

**Conditional GO.** The code is ready and proven; **the deployment is blocked not by the application but by the
absence of a documented way to create the first customer** (M1/M2), one missing seed step with a silent failure
mode (M3), and the `.env` must-fill set (M4). All four are small, and none needs an app-code change.

Close those, follow §9 in order, and apply the first-failure discipline in §6 — and this deploys.
