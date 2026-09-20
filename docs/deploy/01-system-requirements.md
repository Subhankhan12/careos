# Part 1 — System requirements, derived from this repo

**Derived at `73d8ed9`.** Every requirement below carries the file it comes from. Nothing here is copied from
a generic Laravel template — where this repo differs from the usual advice, it says so.

> **Managed platforms (Forge/Ploi) / containers:** the package list and the verify commands are identical;
> only the *installation* differs — those platforms install PHP and its extensions for you, so skip the `apt`
> blocks and go straight to the VERIFY steps, which are the part that actually matters.

---

## 1.1 PHP

| What | Value | Where it comes from |
|---|---|---|
| Version constraint | `^8.2` | `composer.json:9` |
| Version actually proven | **8.2** | `.github/workflows/ci.yml:56` — `php-version: '8.2'`. There is no matrix; 8.3/8.4 are unproven for this repo. |

Install **php8.2**, not "the newest PHP". The suite has only ever run green on 8.2.

### The extension list — and why the existing docs' lists are all different

`composer.json` declares **no `ext-*` requirements at all**, so the list cannot be read off the manifest. The
authoritative source is `composer.lock`'s `packages` array (production dependencies only):

| Extension | Required by | Kind |
|---|---|---|
| `ctype` `filter` `hash` `mbstring` `openssl` `session` `tokenizer` | `laravel/framework` | composer `require` |
| `json` **`pcntl`** **`posix`** | `laravel/horizon` | composer `require` |
| `dom` `libxml` | `tijsverkoyen/css-to-inline-styles` (pulled in by the framework; inlines CSS in mail) | composer `require` |
| `fileinfo` | `league/flysystem-local`, `league/mime-type-detection` | composer `require` |
| `iconv` | `bacon/bacon-qr-code` (the 2FA QR code) | composer `require` |
| `pcre` | `vlucas/phpdotenv` | composer `require` |
| `date` | `webmozart/assert` | composer `require` |
| `pdo_mysql` | `config/database.php:60`; production runs MySQL | code |
| `curl` | the `Http::` client — e.g. `Modules/Comms/src/Providers/Telehealth/LiveKitProvider.php:33` | code |
| **`zip`** | `app/Http/Controllers/GovernanceLedgerExportController.php:92` — `new \ZipArchive`, on the live route at `routes/web.php:742` | code |
| `redis` | **only if `REDIS_CLIENT=phpredis`** — see §1.4 | conditional |

Reproduce the composer-derived half yourself at any time:

```bash
php -r '$l=json_decode(file_get_contents("composer.lock"),true); foreach($l["packages"] as $p){ $e=[]; foreach(($p["require"]??[]) as $k=>$v){ if(str_starts_with($k,"ext-")) $e[]=$k; } if($e) printf("%-45s %s\n",$p["name"],implode(" ",$e)); }'
```

> **Corrections to the existing docs — all four disagree with each other, and none matches `composer.lock`.**
>
> - `DEPLOY-RUNBOOK.md:58-59` says `gd/bcmath/intl/gmp/zip/sodium` are "NOT used by any current code path …
>   install or omit freely". **The `zip` half is wrong** — omit `php8.2-zip` and the governance-ledger export
>   fatals with `Class "ZipArchive" not found`. `gd`, `bcmath`, `intl`, `gmp` and `sodium` genuinely are
>   unused in production code, so the rest of that sentence stands.
> - **`pcntl` and `posix` appear in none of the four documents**, yet both are hard `require`s of
>   `laravel/horizon`. The repo already knows this: `routes/console.php:26` explains that local Windows has no
>   pcntl, "so `php artisan horizon` exits right after startup". Without them the queue daemon dies at startup
>   and nothing queued ever runs.
> - `DEPLOY-RUNBOOK.md:84` ("the real must-haves are `pdo_mysql`, `redis`, `mbstring`, `openssl`") and
>   `DEPLOY-READINESS-CHECK.md:307` (`pdo_mysql`, `dom`, `curl`, `redis`) are two different, both-incomplete
>   lists; `.github/workflows/ci.yml:57` is a third.
> - `DEPLOY-RUNBOOK.md:79-81` calls its verify grep "informational — none of these three is a hard blocker"
>   while listing four things, two of which (`mbstring`, `openssl`) stop `composer install` outright.

### Install

```bash
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt install -y \
  php8.2-fpm php8.2-cli php8.2-common php8.2-mysql php8.2-xml \
  php8.2-mbstring php8.2-curl php8.2-zip php8.2-redis
```

`php8.2-redis` is listed because the shipped env template selects it (§1.4). If you choose `predis`, drop it.

### VERIFY — one loop, because a silent no-op is the failure mode this pack exists to prevent

```bash
for e in ctype filter hash mbstring openssl session tokenizer json pcntl posix \
         dom libxml fileinfo iconv pcre date pdo_mysql curl zip; do
  php -m | grep -qx "$e" && echo "ok      $e" || echo "MISSING $e"
done
php -v | head -1
```

**Expected:** nineteen `ok` lines, no `MISSING`, then a line beginning `PHP 8.2.`

Run it for **both SAPIs**. `php -m` reports the CLI; the web worker loads a different ini:

```bash
php -i | grep '^Loaded Configuration File'          # CLI
php-fpm8.2 -i | grep '^Loaded Configuration File'   # FPM — usually a DIFFERENT file
```

---

## 1.2 PHP ini settings — absent from every existing document

No deploy doc in this repo sets a single `php.ini` value, and two of them are load-bearing.

| Setting | Required | Derived from |
|---|---|---|
| `upload_max_filesize` | **≥ 12M** | `Modules/Clinical/src/Http/Controllers/DocumentUploadController.php:25` and `Modules/Dental/src/Http/Controllers/DentalImageController.php:83` both validate `max:10240`. Laravel's `max:` on a file is **kilobytes**, so 10240 KB = 10 MiB = 10,485,760 bytes. PHP's stock default is **2M**. |
| `post_max_size` | **≥ 16M** | Must exceed `upload_max_filesize` plus the rest of the multipart body. Stock default 8M. |
| `memory_limit` (CLI) | **≥ 256M** | `config/horizon.php:208` restarts a worker once it passes `'memory' => 128` MB, so the CLI limit must sit above that threshold. |

The third upload path is smaller and needs nothing extra:
`Modules/Import/src/Http/Controllers/ImportBatchController.php:58` validates `max:5120` (5 MiB, CSV import).

**The failure mode if you skip this is silent and misleading.** Nginx is already configured for 25M
(`DEPLOY-RUNBOOK.md:400`), so a 10 MiB clinical document is accepted by nginx and then discarded by PHP, and
the user sees **"A file is required"** — a validation error, not a size error. Nginx and PHP disagree by a
factor of five today, and only nginx's side is written down anywhere.

Install `docs/deploy/careos-php.ini` to **both** `/etc/php/8.2/fpm/conf.d/99-careos.ini` and
`/etc/php/8.2/cli/conf.d/99-careos.ini`.

**VERIFY:**
```bash
php -i | grep -E '^(upload_max_filesize|post_max_size|memory_limit)'
php-fpm8.2 -i | grep -E '^(upload_max_filesize|post_max_size)'
```
**Expected:** `upload_max_filesize => 12M => 12M`, `post_max_size => 16M => 16M`, `memory_limit => 256M => 256M`.

---

## 1.3 Node.js

| What | Value | Where it comes from |
|---|---|---|
| Minimum | **22** | `package-lock.json` — `@intlify/core-base`, `@intlify/message-compiler` and `@intlify/shared` (via `vue-i18n`) each declare `"node": ">= 22"`. Several `@asamuzakjp/*` packages and `@vitejs/plugin-vue` declare `^20.19.0 \|\| ^22.12.0 \|\| >=24.0.0`. |
| Proven | **22** | `.github/workflows/ci.yml:63` — `node-version: '22'` |

`package.json` has **no `engines` field**, so this had to be derived from the lockfile.

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

**VERIFY:** `node -v` → expect `v22.` · `npm -v` → 10.x or newer.

> **Correction:** `DEPLOY-CHECKLIST.md:47` says only "Node", with no version. An operator who runs
> `apt install nodejs` from Ubuntu's own repo lands below the floor and the build fails in a confusing way.

---

## 1.4 Redis — and the parked staging error

```bash
sudo apt install -y redis-server
sudo systemctl enable --now redis-server
```

**VERIFY:** `redis-cli ping` → `PONG`

### The client choice is the highest-risk decision in this pack

`config/database.php:146` reads `'client' => env('REDIS_CLIENT', 'phpredis')`, and
`docs/DEPLOY-ENV.production.template:58` ships `REDIS_CLIENT=phpredis`.

With phpredis selected and the extension absent, Laravel constructs `new Redis` directly. `config/app.php`
declares no `aliases` array, so the framework's friendly *"Please make sure the PHP Redis extension is
installed and enabled"* `LogicException` is **never reached** — PHP throws `Error: Class "Redis" not found`
first. Because session, cache and queue are all on Redis in the shipped template, that fatals on the **first
request**.

**This path has zero automated coverage.** Development uses predis (`.env.example:45`) and CI pins
`REDIS_CLIENT: predis` (`.github/workflows/ci.yml:45`). The phpredis branch is exercised by nothing, anywhere
— exactly the shape of a failure that can only appear on a server. `DEPLOY-CHECKLIST.md:355` already names it
the leading candidate for the parked staging error, and the code corroborates that call completely.

**Two valid choices. Pick deliberately:**

1. **`REDIS_CLIENT=predis`** — matches dev, CI and `.env.example`; needs no extension; `predis/predis ^3.5` is
   already a composer `require` (`composer.json:17`). **Lower risk, and the client every green test run in
   this project's history has used.**
2. `REDIS_CLIENT=phpredis` — faster; needs `php8.2-redis`; **must** be verified with the §1.1 loop before the
   first request reaches the box.

**VERIFY whichever you chose:**
```bash
grep '^REDIS_CLIENT' .env
php -m | grep -qx redis && echo "phpredis present" || echo "phpredis ABSENT — REDIS_CLIENT must be predis"
php artisan tinker --execute="Illuminate\Support\Facades\Redis::connection()->set('careos:probe','1'); echo Illuminate\Support\Facades\Redis::connection()->get('careos:probe');"
```
**Expected:** the probe prints `1`. Anything else — especially `Class "Redis" not found` — stop and fix it here.

> **Correction:** the env template ships the riskier client while the checklist names that exact combination
> as its own #1 predicted production failure. Each document is internally consistent and they contradict each
> other. **This pack resolves it: predis, unless you have a measured reason to prefer phpredis.**

---

## 1.5 MySQL 8

```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation
```

| Requirement | Value | Derived from |
|---|---|---|
| Minimum version | **8.0.16** | `CHECK` constraints are only *enforced* from 8.0.16. `Modules/Billing/database/migrations/2026_07_10_000010_create_payments_table.php:32` adds `CHECK (amount_minor > 0)`. On an older 8.0.x every money-integrity CHECK migrates successfully and is **silently inert**. `docs/DB-PARITY.md:51-54` knows this threshold; none of the three operator-facing docs carries it. |
| Database collation | **`utf8mb4` / `utf8mb4_unicode_ci`**, on the DATABASE — not just the tables | `config/database.php:54-55` makes Laravel append the collation to every `CREATE TABLE`. But `audit_events` is built from raw DDL — `Modules/Audit/database/migrations/2026_07_08_000001_create_audit_events_table.php:38` — and that heredoc carries **no `CHARACTER SET` or `COLLATE` clause at all** (verified: zero matches in the file), so it inherits the *database* default. On a stock MySQL 8 that is `utf8mb4_0900_ai_ci`, leaving the PHI audit ledger on a different collation from every other table. |
| Session time zone | **UTC** | `config/app.php:68` is `'timezone' => 'UTC'`, but `config/database.php` sets **no** connection `timezone`. MySQL converts `TIMESTAMP` columns through the session time zone on write *and* read, and this schema has hundreds of them (168 `->timestamps()` calls plus explicit `->timestamp()` columns). A non-UTC server shifts stored moments. |
| Strict mode | on | `config/database.php` — `'strict' => true` |

```sql
CREATE DATABASE careos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'careos'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON careos.* TO 'careos'@'localhost';
FLUSH PRIVILEGES;
```

`CREATE/ALTER/INDEX/DROP/REFERENCES` are required because `migrate --force` runs as this user.

Add to `/etc/mysql/mysql.conf.d/mysqld.cnf`, then restart MySQL:
```ini
[mysqld]
default-time-zone = '+00:00'
```

**VERIFY:**
```bash
mysql -u careos -p -e "SELECT VERSION(), @@global.time_zone, @@session.sql_mode\G"
mysql -u careos -p -e "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='careos';"
```
**Expected:** version ≥ `8.0.16`; time zone `+00:00`; charset `utf8mb4`, collation `utf8mb4_unicode_ci`.

After migrating (Part 4), confirm the one table that cannot self-correct:
```bash
mysql -u careos -p careos -e "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_NAME='audit_events';"
```
**Expected:** `utf8mb4_unicode_ci`. If it reports `utf8mb4_0900_ai_ci`, the database default was wrong when
you migrated — fix the database default and re-migrate onto a clean database rather than patching the table.

> **Note on grants — a three-way disagreement this pack does not resolve.** `DEPLOY-RUNBOOK.md:99` grants
> `ALL PRIVILEGES`. `DEFERRED.md:151-153` records that production "should run under a DB user with
> UPDATE/DELETE revoked on `audit_events`". The audit migration's own comment at `:16-17` asserts production
> "also runs under a least-privilege user" **as though it were already true** — it is not; that is an
> aspiration written in the present tense. The grant above is narrower than `ALL` but still includes
> UPDATE/DELETE on the audit ledger. Revoking them is a real, unimplemented hardening; until then the
> append-only `BEFORE UPDATE`/`BEFORE DELETE` triggers are the only active guard. **Operator decision.**

---

## 1.6 Nginx

```bash
sudo apt install -y nginx
```

Config: `docs/deploy/nginx-careos.conf` (Part 2).

**VERIFY:** `sudo nginx -t` → `syntax is ok` / `test is successful`.

---

## 1.7 What this pack does NOT decide for you

These are genuinely not determinable from the repo and are left to the operator:

- **Host sizing.** Horizon runs `maxProcesses: 10` in production (`config/horizon.php:218`) with a per-worker
  restart threshold of 128 MB (`:208`) and a 64 MB master threshold (`:186`). That is roughly a **1.3 GB
  steady-state planning figure for Horizon alone** — a recycle target, not an enforced cap; transient RSS can
  exceed it. Add PHP-FPM, MySQL and a `npm run build` (which is memory-hungry) on top. Nothing in the repo
  states a minimum RAM, and a 2 GB box is unlikely to be comfortable.
- **Whether to lower `maxProcesses`** for a small single-tenant install.
- **Backups.** `DEPLOY-RUNBOOK.md:456` requires a nightly off-box `mysqldump` before real patient data lands
  and gives no schedule, retention or destination. Still true, still undecided.
- **TLS certificate source** (certbot vs a provided cert).
- **Firewall / fail2ban / unattended-upgrades.** No document in this repo mentions any of them.
- **Swap.** `npm run build` on a small box is the usual reason to want it.
