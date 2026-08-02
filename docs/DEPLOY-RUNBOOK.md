# CareOS — Production Deployment Runbook
**A step-by-step guide to take CareOS from "built and green in CI" to "live on a server a paying customer can reach."** Execute against this on deploy day, top to bottom.

> Assumptions from the build: Laravel 12, PHP 8.2+, **MySQL 8** (parity proven — `docs/DB-PARITY.md`), Redis 7, Node 22, Horizon for queues, a scheduler, an offline Nurse PWA, private-disk storage for PHI (patient docs, dental images, PDFs). Substitute your real values for every `CHANGE_ME`.

---

> ### ⚙️ Corrections applied (this doc vs. the original draft)
> This version was reconciled against the actual codebase (read-only audit, 2026-07-25). Eight edits, all verified with file:line evidence. Nothing below is a code bug — items 7–8 are already-documented deferred features.
>
> | # | Section | Fix | Why it mattered |
> |---|---------|-----|-----------------|
> | 1 | §4 `.env` | `LIVEKIT_URL` → **`LIVEKIT_HOST`** | App reads `LIVEKIT_HOST` ([config/telehealth.php:16](../config/telehealth.php#L16)); `LIVEKIT_URL` leaves host at `https://livekit.invalid` → telehealth breaks silently. |
> | 2 | §3 build | **`npm run build:pwa` is mandatory** (de-hedged) | Main build never includes the Nurse PWA; skipping leaves `public/nurse-pwa` empty. |
> | 3 | §7 queue | `QUEUE_CONNECTION=redis` is **load-bearing** | Code default is `database`; if unset, Horizon (redis-only) never processes jobs while `horizon:status` reports healthy. |
> | 4 | §4 `.env` | Removed guessed `CREDENTIAL_ENCRYPTION_KEY` | No such var exists; encryption uses `APP_KEY` ([config/app.php:100](../config/app.php#L100)). |
> | 5 | §7 sched | "P.2 automation" → **`billing:reconcile` + `billing:dunning-run`** | "P.2" is a phase label, not a job. |
> | 6 | §11 step 3 | No **`dentist`** role → assign **`doctor`** | No dentist template exists; `doctor` carries `dental.chart` ([RbacProvisioner.php:58-104](../Modules/Platform/src/Services/RbacProvisioner.php#L58-L104)). |
> | 7 | §11 step 2 | Resource availability has **no admin screen** yet (seed programmatically) | Deferred W8c; a resource with no availability rows yields zero bookable slots. |
> | 8 | §1 provisioning | **ext-sodium is NOT critical** (de-blocked); gd/bcmath/intl/gmp unused | JWT uses `hash_hmac`, credentials use `Crypt` (openssl) — both base PHP. |
>
> Verified-clean and kept as-is: the `env()`-after-`config:cache` trap (zero `env()` outside `config/`), PHI private-disk isolation, Horizon install, `utf8mb4_unicode_ci` collation parity, all three demo seeder class names, the P.6 CSV import dry-run→commit flow, and the `migrate:fresh`-is-demo-only fencing.

---

## 0 — Before you touch a server (decisions)

- **Host:** a single Linux VM to start (Ubuntu 22.04/24.04 LTS). A 2–4 vCPU / 4–8 GB box is plenty for the first customers. (DigitalOcean, Hetzner, AWS Lightsail — any.)
- **Domain:** pick the app domain (e.g. `app.careos.example`) and point an A record at the server's IP *before* you request HTTPS.
- **Two-server-later note:** for now, app + MySQL + Redis on one box is fine. When you have paying load, split the DB onto managed MySQL. Don't prematurely split.
- **Secrets you'll need on hand:** DB password, a fresh `APP_KEY` (generated below), SMTP credentials (host/user/pass), and the LiveKit host URL + API key + secret. **`APP_KEY` is the app's only encryption key** — it backs the credential-encryption path (`Crypt`); there is no separate credential/gateway key variable.

---

## 1 — Provision the host

SSH in as a sudo user, then:

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.2 + extensions. The genuinely-required set (transitive from laravel/framework) is
# ctype, filter, hash, mbstring, openssl, session, tokenizer (all bundled in base php8.2 +
# php8.2-mbstring), plus pdo_mysql (php8.2-mysql), dom (php8.2-xml), curl (php8.2-curl), and
# ext-redis (php8.2-redis — default REDIS_CLIENT=phpredis). gd/bcmath/intl/gmp/zip/sodium are
# NOT used by any current code path; they are harmless future-proofing — install or omit freely.
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y \
  php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis php8.2-mbstring \
  php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl \
  php8.2-sodium php8.2-gmp

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 22 (for building frontend assets)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs

# MySQL 8, Redis, Nginx, Supervisor, Certbot
sudo apt install -y mysql-server redis-server nginx supervisor certbot python3-certbot-nginx

# Verify the required extensions loaded (informational — none of these three is a hard blocker
# on its own, but pdo_mysql + redis being absent WILL break the app at runtime):
php -m | grep -iE 'pdo_mysql|redis|mbstring|openssl'
```

> **On ext-sodium:** the original draft called it "REQUIRED — the JWT/credential path uses it." That is not accurate for this build — the LiveKit JWT path signs with `hash_hmac` (ext-hash, core) and the kiosk credential path uses Laravel `Crypt` (ext-openssl). Installing sodium is fine, but **do not treat a missing sodium as a stop-the-deploy condition.** The real must-haves are `pdo_mysql`, `redis`, `mbstring`, and `openssl`.

---

## 2 — Database + Redis

```bash
# MySQL 8: secure it, create the app DB + user
sudo mysql_secure_installation   # set a root password, answer the prompts

sudo mysql -u root -p
```
```sql
CREATE DATABASE careos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'careos'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON careos.* TO 'careos'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**Charset matters:** `utf8mb4_unicode_ci` — this exactly matches the app's configured collation (`config/database.php` sets `DB_COLLATION` default `utf8mb4_unicode_ci`, and `docs/DB-PARITY.md` explicitly rejects MySQL 8's default `utf8mb4_0900_ai_ci`). Using `unicode_ci` avoids "Illegal mix of collations" at runtime.

Redis is fine on defaults for a single box, but **set a password** in `/etc/redis/redis.conf` (`requirepass CHANGE_ME_REDIS_PASSWORD`), then `sudo systemctl restart redis`. (You'll reference it in `.env`.)

---

## 3 — Get the code onto the server

```bash
# App lives here (adjust to taste)
sudo mkdir -p /var/www/careos
sudo chown -R $USER:www-data /var/www/careos
cd /var/www/careos

# Clone (use a deploy key or a token — the repo is private)
git clone git@github.com:Subhankhan12/careos.git .

# PHP deps — production flags (no dev packages, optimized autoloader)
composer install --no-dev --optimize-autoloader

# Frontend build — BOTH builds are required and produce DISJOINT outputs:
#   npm run build      -> public/build       (the main Inertia/Vue app)
#   npm run build:pwa  -> public/nurse-pwa   (the offline Nurse PWA + service worker)
# The main build never includes the PWA, so build:pwa is MANDATORY — do not skip it.
npm ci
npm run build
npm run build:pwa
```

> **Why both builds:** the main `vite build` input is only `resources/css/app.css` + `resources/js/app.ts` — it contains no PWA plugin. The Nurse PWA is a separate Vite config (`nurse-pwa/vite.config.ts`, `emptyOutDir` scoped to `public/nurse-pwa`). Skipping `build:pwa` leaves the offline Nurse PWA unbuilt (no service worker, no manifest). Order between the two is irrelevant — outputs don't overlap.

---

## 4 — The `.env` production file

Create `/var/www/careos/.env`. **This is the highest-risk file** — get `APP_DEBUG`, the cache/queue drivers, and the LiveKit host name right.

```ini
APP_NAME=CareOS
APP_ENV=production
APP_KEY=                      # generated in the next step — leave blank for now
APP_DEBUG=false               # NON-NEGOTIABLE in prod — true leaks stack traces + data
APP_URL=https://app.careos.example

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=warning             # not debug in prod

# Database (MySQL 8)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=careos
DB_USERNAME=careos
DB_PASSWORD=CHANGE_ME_STRONG_DB_PASSWORD

# Redis (cache, queue, Horizon). REDIS_CLIENT defaults to phpredis; set =predis to use the
# bundled predis package instead and drop the php8.2-redis extension.
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=CHANGE_ME_REDIS_PASSWORD
REDIS_PORT=6379

CACHE_STORE=redis             # L12 key name (CACHE_STORE, not CACHE_DRIVER)
QUEUE_CONNECTION=redis        # LOAD-BEARING: default fallback is 'database'; Horizon only
                              # consumes 'redis'. If this is unset, jobs silently never run.
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# Mail (real SMTP — reminders, portal, dunning all depend on this)
MAIL_MAILER=smtp
MAIL_HOST=CHANGE_ME_SMTP_HOST
MAIL_PORT=587
MAIL_USERNAME=CHANGE_ME_SMTP_USER
MAIL_PASSWORD=CHANGE_ME_SMTP_PASS
MAIL_ENCRYPTION=tls
[email protected]
MAIL_FROM_NAME="${APP_NAME}"

# Telehealth (LiveKit) — NOTE: the endpoint var is LIVEKIT_HOST, NOT LIVEKIT_URL.
# Setting LIVEKIT_URL does nothing (host stays at the 'https://livekit.invalid' default).
LIVEKIT_HOST=CHANGE_ME_LIVEKIT_HOST_URL
LIVEKIT_API_KEY=CHANGE_ME_LIVEKIT_KEY
LIVEKIT_API_SECRET=CHANGE_ME_LIVEKIT_SECRET
# Optional (safe defaults if omitted):
# TELEHEALTH_PROVIDER=livekit
# TELEHEALTH_MAX_TOKEN_TTL=600

# Filesystem. FILESYSTEM_DISK=local selects the PRIVATE disk (root storage/app/private).
# NOTE: PHI paths hardcode disk('local') regardless, so PHI stays private even if this changes.
FILESYSTEM_DISK=local
```

> **There is no `CREDENTIAL_ENCRYPTION_KEY`.** The original draft carried a commented guess for it — remove it. Encryption uses Laravel's standard `APP_KEY` (AES-256-CBC, `config/app.php`). Just make sure `APP_KEY` is generated (next step).

> **The `env()` trap (verified clean):** once you run `config:cache`, `env()` calls *outside* config files return `null`. Audit confirmed the codebase has **zero** `env()` calls outside `config/` in production code — so `config:cache` is safe here. Step 8 still spot-checks it. Never introduce an `env()` call in application code on a config-cached deploy.

---

## 5 — App key + storage

```bash
cd /var/www/careos

php artisan key:generate         # writes APP_KEY into .env (the app's only encryption key)

php artisan storage:link         # public symlink for genuinely-public assets only

# Permissions: web server needs to write storage + cache
sudo chown -R www-data:www-data /var/www/careos/storage /var/www/careos/bootstrap/cache
sudo chmod -R 775 /var/www/careos/storage /var/www/careos/bootstrap/cache
```

**Private-disk / PHI check (do NOT skip):** patient documents, dental images, and PDFs must be on a **private** disk with **no public URL**. Verified in the build: every PHI write hardcodes `Storage::disk('local')` (root `storage/app/private`), and every download streams through an authenticated, Gate-checked controller — no `Storage::url()` / `temporaryUrl()` on PHI. `storage:link` only symlinks the *public* disk (`storage/app/public`); `storage/app/private` is never web-reachable. Confirm no patient file is served from `public/` and none is reachable by a guessable URL before go-live.

---

## 6 — Migrate + cache (the release sequence)

```bash
cd /var/www/careos

# Migrate the production DB (--force because prod is non-interactive; forward-only, no wipe)
php artisan migrate --force

# Confirm zero pending
php artisan migrate:status      # every migration should read "Ran"

# Cache config, routes, views, events (production performance)
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> Run these four caches **after** the `.env` is final. If you change `.env` later, re-run `php artisan config:cache` (or `config:clear`), or the change won't take effect.

---

## 7 — Queue (Horizon) + scheduler — the part people forget

**These are not optional.** Without them: reminders never send, the reconcile alarm never fires, the audit-chain verification never runs, and queued work never processes. Green tests don't run the daemon — you must.

> **Two-part reminder pipeline:** the scheduler only *enqueues* reminders (`appointments:dispatch-reminders` pushes `SendAppointmentReminderJob` onto redis every 15 min). The actual send happens in the queued job. So **reminders require BOTH the cron AND Horizon consuming redis** — and that in turn requires `QUEUE_CONNECTION=redis` in the deployed `.env` (see §4). The synchronous sweeps (reconcile, dunning, audit-chain) run inside their commands and don't need a worker.

### Horizon under Supervisor (survives reboots)

Horizon **is** installed (`laravel/horizon`, `config/horizon.php`); `php artisan horizon` is the correct daemon — a single master process that fans out its own workers per `config/horizon.php`. **Do not** substitute `queue:work`, and do not set `numprocs > 1`.

Create `/etc/supervisor/conf.d/careos-horizon.conf`:

```ini
[program:careos-horizon]
process_name=%(program_name)s
command=php /var/www/careos/artisan horizon
autostart=true
autorestart=true
user=www-data
stopsignal=SIGTERM
redirect_stderr=true
stdout_logfile=/var/www/careos/storage/logs/horizon.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start careos-horizon
sudo supervisorctl status          # should show RUNNING
```

On future redeploys, gracefully cycle workers with `php artisan horizon:terminate` (Supervisor restarts it) rather than a hard kill.

### The scheduler cron (runs the scheduled jobs)

A single `schedule:run` drives **all eight** scheduled commands (defined in `routes/console.php`):

| Command | Cadence | Purpose |
|---|---|---|
| `audit:verify-chains` | daily 01:30 | audit hash-chain verification |
| `credentials:refresh-status` | daily 02:10 | staff credential status refresh |
| `nursing:materialize-visits` | daily 02:20 | Spitex visit materialization |
| `clinical:evaluate-recalls` | daily 02:30 | recall due-date evaluation |
| `billing:dunning-run` | daily 06:00 | dunning letters (the billing automation) |
| `billing:reconcile` | daily 06:30 | reconciliation + raise/clear the reconcile alarm |
| `appointments:dispatch-reminders` | every 15 min | enqueue due appointment reminders |
| `scheduling:expire-waitlist-offers` | every 5 min | expire stale waitlist offers |

> The original draft named "the P.2 automation" — that's a phase label, not a job. The real billing sweeps are **`billing:reconcile`** and **`billing:dunning-run`**, both listed above.

```bash
sudo crontab -u www-data -e
```
Add exactly:
```
* * * * * cd /var/www/careos && php artisan schedule:run >> /dev/null 2>&1
```

**Verify the scheduler actually fires** (step 8) — a missing/incorrect cron is silent; nothing errors, things just never happen. There is no live heartbeat if `schedule:run` stops (detection is after-the-fact via the append-only `integrity_checks` / `reconciliation_runs` rows).

---

## 8 — Nginx + HTTPS

Create `/etc/nginx/sites-available/careos`:

```nginx
server {
    listen 80;
    server_name app.careos.example;
    root /var/www/careos/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }

    client_max_body_size 25M;   # allow document/image/CSV uploads
}
```

```bash
sudo ln -s /etc/nginx/sites-available/careos /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# HTTPS (A record must already point here)
sudo certbot --nginx -d app.careos.example
sudo systemctl reload nginx
```

---

## 9 — Post-deploy verification (the go-live checklist)

Run every one of these before you tell a customer it's live. This is the "local-green ≠ prod-works" insurance the build taught you.

**App & config**
- [ ] `https://app.careos.example` loads over HTTPS (valid cert, no mixed-content).
- [ ] `APP_DEBUG=false` confirmed (trigger a 404 — you get a clean page, *not* a Laravel stack trace).
- [ ] `php artisan migrate:status` — zero pending, all "Ran".
- [ ] `php artisan about` — env=production, cache drivers=redis, config cached.
- [ ] **Config-cache check:** log in, load a page that uses mail/LiveKit/DB config — confirm no null-config errors (the `env()`-after-cache trap).

**Queue & schedule (the silent failures)**
- [ ] `sudo supervisorctl status` → `careos-horizon RUNNING`.
- [ ] **`QUEUE_CONNECTION=redis` confirmed in the deployed `.env`** — otherwise Horizon reports healthy but processes nothing.
- [ ] Horizon dashboard at `/horizon` (or `php artisan horizon:status`) shows it processing. **Note:** the dashboard is restricted to **super-admin** users in production — log in as a super-admin to view it.
- [ ] **Scheduler fired:** wait ~2 minutes, check `storage/logs` / a scheduled-job side effect, or run `php artisan schedule:list` and confirm the cron is installed. (If nothing ever runs, the cron line is wrong.)
- [ ] Send a test email (trigger a reminder or a portal action) → it arrives. (Reminders need the cron **and** a running Horizon worker.)

**Data integrity & safety (CareOS-specific — do NOT skip)**
- [ ] **Fail-closed tenancy:** hit a bogus tenant/record id → **404, not a 500 and not a leak** (the C-1 property).
- [ ] **Route-smoke equivalent:** log in as each role, click every major nav item → **200, no 500** (the FIX.5 property in prod).
- [ ] **Private disk / PHI:** confirm a patient document / dental image is **not** reachable by a public URL.
- [ ] **Fences (spot-check in prod):** a vital renders raw (no colour/flag); a signed note is read-only; the kiosk on a bad code shows the generic "see reception" with no PHI; the dental diagnosis screen suggests nothing.
- [ ] **Billing reconciles:** the reporting/aging figures match the backend (issue a test invoice in a throwaway tenant if needed).
- [ ] **RBAC by URL:** a low-permission role hitting a privileged route → 403.
- [ ] **LiveKit:** start a telehealth session → the staff + patient can connect; confirm "not recorded" holds. (If it fails to connect, first check `LIVEKIT_HOST` — not `LIVEKIT_URL` — is set.)

**Backups (before real patient data lands)**
- [ ] A nightly MySQL dump (cron: `mysqldump` → off-box storage) is configured. *Do this before importing a real customer's patients — healthcare data with no backup is a non-starter.*

---

## 10 — Reset the demo tenants (optional, for a demo instance)

If this box is (also) your demo environment, re-seed to a clean state — the audit/UI work left mutations in the demo tenants. All three class names are confirmed correct:

```bash
# Reset + re-seed all three demo tenants (clinic, Spitex, dental)
php artisan migrate:fresh --force          # DESTROYS data — demo box only, NEVER a customer box
php artisan db:seed --class=DemoClinicSeeder     # praxis-lindenhof (CHF)
php artisan db:seed --class=DemoSpitexSeeder     # spitex-sonnengarten (EUR)
php artisan db:seed --class=DemoDentalSeeder     # zahnarztpraxis-morgenstern (CHF)
```

> **NEVER run `migrate:fresh` on a box holding a customer's real data.** For customer tenants you only ever `migrate --force` (forward migrations, no wipe).

---

## 11 — Per-customer onboarding (the delivery step)

Once the platform is live, bring each paying customer online. Same sequence per customer:

1. **Create their tenant** (their practice), set locale/currency (CHF for the Swiss clinic; the dentist's currency), and the practice profile (W8b) — branches, opening hours, timezone. (Branch CRUD + 7-day opening-hours editor + timezone exist under `admin.branches.*` / `settings.*`.)
2. **Set up their branch(es) + resources** (W8b/W8c): rooms/chairs via `admin.resources.*`. **⚠️ Availability windows have no admin screen yet** (the W8c availability UI is a documented deferred follow-up) — a resource's availability rows must be **seeded programmatically** today. This matters: **until a resource has availability rows, the slot finder returns zero bookable slots for it.** Plan to seed availability as part of onboarding, or scheduling will appear empty.
3. **Assign roles** (W8): create their users and assign the built role templates. Available templates are **org_admin, coordinator, doctor, nurse, reception, billing** — there is **no `dentist` template**. For a dentist, assign **`doctor`** (it carries the `dental.chart` / odontogram permission). A dedicated dentist/hygienist/assistant split is a later dental gate.
4. **For the dentist:** load their **fee schedule** via `dental.fee-schedule.*` (the tenant-authored dental procedure catalog — their own codes/fees; no CDT/SSO code set is bundled). Start from the generic starter template, then edit codes/fees. `billing.manage`-gated.
5. **Import their patients** via the **P.6 CSV tool** (`import.*`): upload their export → **dry-run** (`validate` — writes nothing, shows the preview + dedup) → verify the mapping and the duplicate handling (skip / import_as_new / merge) → **commit**. Their existing patients are now in, through the real services (MRN/audit/tenancy applied).
6. **Configure their KB** (W10) via `governance.kb.*` if they use the front-desk agent. Note: this curates KB **content** only — the agent's answer/refuse/escalate behaviour and electric-fence are fixed in code, not a tenant-configurable setting. Wire their real mail sender if per-tenant.
7. **Walk them through it** — the demo you'd give, now on their real data. Confirm the flows they care about.
8. **Collect the remaining payment** — this is the delivery milestone the advance was against.

---

## A note on updates (after go-live)

For future releases, the safe redeploy sequence is:
```bash
cd /var/www/careos
php artisan down                     # maintenance mode (brief)
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build && npm run build:pwa   # BOTH builds — build:pwa is not optional
php artisan migrate --force          # forward only — NEVER migrate:fresh on prod
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan horizon:terminate        # Supervisor restarts Horizon on new queued-job code
php artisan up
```
Then re-run the step-9 verification (at least: 404-not-stacktrace, Horizon running + consuming redis, scheduler firing, a smoke click-through, fail-closed 404).

---

## The one-line summary
Provision → DB/Redis (`utf8mb4_unicode_ci`) → code + **both builds (`build` + `build:pwa`)** → `.env` (`APP_DEBUG=false`, **`LIVEKIT_HOST`**, **`QUEUE_CONNECTION=redis`**) → key + **private-disk PHI check** → `migrate --force` + cache → **Horizon under Supervisor + the scheduler cron** → Nginx + HTTPS → **verify (fail-closed 404, no stack trace, queue+scheduler running, fences hold, backups on)** → onboard each customer (branches → resources *(seed availability)* → roles *(`doctor` for a dentist)* → fee schedule → **CSV import dry-run→commit**) → collect payment.

That's built-and-green-in-CI turned into live-and-paying.
