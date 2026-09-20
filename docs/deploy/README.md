# CareOS — Server Deployment Pack

**Target: one fresh Ubuntu LTS VPS that an operator SSHes into, running the app, MySQL 8, Redis and Nginx on
a single host.** Derived from this repo at `73d8ed9`. Every requirement carries the file it came from.

> **Managed platforms and containers.** Where a step would differ on Forge/Ploi or in Docker, the file says so
> in one line and carries on. This pack deliberately does not ship three variants — the single-VPS path is
> the one written down, and the differences are small and marked.

---

## How this relates to the existing documents

| Document | Role |
|---|---|
| **`docs/DEPLOY-CHECKLIST.md`** | **The source of truth.** The ordered list a person follows, with what success looks like at each step. This pack does not replace it and must not contradict it. |
| `docs/DEPLOY-RUNBOOK.md` | The long-form how, with full commands and file contents. |
| `docs/DEPLOY-READINESS-CHECK.md` | The pre-deploy audit that produced the go/no-go verdict. |
| `docs/DEPLOY-ENV.production.template` | The `.env` template. |
| **`docs/deploy/` (this pack)** | The **artifacts** — real config files, a real release script, and the derived requirements behind them. |

Where this pack found one of those documents wrong, the correction is stated inline **and** carried back into
`DEPLOY-CHECKLIST.md`. The corrections are listed in full at the bottom of this page.

---

## Read in this order

| Part | File | What it gives you |
|---|---|---|
| **1** | [`01-system-requirements.md`](01-system-requirements.md) | PHP version + every extension, Node, MySQL 8, Redis — each derived and each with a VERIFY command |
| **2** | the config files below | Nginx, Horizon, scheduler, log rotation, PHP ini |
| **3** | [`release.sh`](release.sh) | One re-runnable deploy script |
| **4** | [`04-provisioning.md`](04-provisioning.md) | First-customer provisioning, in order, with the two traps |
| **5** | [`05-smoke-test.md`](05-smoke-test.md) | Ten checks proving the install actually works |
| **6** | [`06-capture-protocol.md`](06-capture-protocol.md) | **What to capture at the first failure, before fixing** |

## The config files and where they go

| File | Destination |
|---|---|
| [`nginx-careos.conf`](nginx-careos.conf) | `/etc/nginx/sites-available/careos` |
| [`careos-horizon.service`](careos-horizon.service) | `/etc/systemd/system/careos-horizon.service` |
| [`careos-scheduler.cron`](careos-scheduler.cron) | the `www-data` crontab (`sudo crontab -u www-data -e`) |
| [`careos-logrotate.conf`](careos-logrotate.conf) | `/etc/logrotate.d/careos` — **mostly commented out on purpose; read it first** |
| [`careos-php.ini`](careos-php.ini) | `/etc/php/8.2/fpm/conf.d/99-careos.ini` **and** `/etc/php/8.2/cli/conf.d/99-careos.ini` |

---

## The three decisions this pack makes for you

**1. systemd, not Supervisor, for Horizon.** The existing docs specify a Supervisor program and that
configuration is not wrong — but Supervisor cannot express a dependency on `redis-server`, so on reboot it
can start Horizon before Redis is listening and produce a crash-restart loop with a misleading error. systemd
is already on the box running nginx, MySQL and Redis. Use one or the other, **never both**. Reasoning in full
at the top of `careos-horizon.service`.

**2. `REDIS_CLIENT=predis`, not the template's `phpredis`.** This is the highest-risk line in the whole
deploy and the two existing documents disagree about it — the env template ships `phpredis` while the
checklist names that exact combination as its own #1 predicted production failure. predis needs no extension,
is already a composer `require`, and is what every green test run in this project's history used. Part 1 §1.4.

**3. Install `careos-php.ini`.** No deploy document in this repo sets a single `php.ini` value, and the stock
`upload_max_filesize=2M` silently breaks a documented 10 MiB upload path with a misleading error message.
Part 1 §1.2.

---

## What this pack deliberately leaves to the operator

Not gaps in the research — genuinely not determinable from the repo:

- **Host sizing.** Horizon alone targets ~1.3 GB steady-state in production (`maxProcesses: 10` × a 128 MB
  per-worker restart threshold + a 64 MB master threshold). Nothing in the repo states a minimum RAM.
- **Whether to lower `maxProcesses`** for a small single-tenant install.
- **Backups.** Required before real patient data lands; no schedule, retention or destination is specified
  anywhere.
- **Least-privilege DB user.** `DEFERRED.md:151-153` wants UPDATE/DELETE revoked on `audit_events`; the
  runbook grants `ALL`; the audit migration's own comment claims the hardening is already in place. It is
  not. Part 1 §1.5.
- **TLS certificate source**, firewall, fail2ban, unattended-upgrades, swap.
- **`audit:ensure-partitions` and `horizon:snapshot`** — neither is scheduled; the two docs disagree on
  whether the first is a go-live gate. Cron lines for both are supplied, commented, in
  `careos-scheduler.cron`.

---

## Corrections this pack makes to the existing documents

Carried into `DEPLOY-CHECKLIST.md` where they affect an ordered step.

**Wrong, and would break a deploy:**

1. **`ext-zip` is required, not optional.** `DEPLOY-RUNBOOK.md:58-59` says `zip` is "NOT used by any current
   code path — install or omit freely". `app/Http/Controllers/GovernanceLedgerExportController.php:92` uses
   `new \ZipArchive` on the live route `routes/web.php:742`.
2. **`ext-pcntl` and `ext-posix` appear in none of the four documents**, yet both are hard `require`s of
   `laravel/horizon`. Without them the queue daemon dies at startup.
3. **The nginx block cannot serve the Nurse PWA the same runbook tells you to smoke-test.** `index index.php`
   only, and the PWA is a static SPA with no Laravel route.
4. **No document sets `upload_max_filesize`.** Stock 2M against a documented 10 MiB upload path, failing with
   "A file is required".
5. **"Availability windows have no admin screen — seed programmatically"** is wrong in **four** places
   (`DEPLOY-RUNBOOK.md:19`, `:503`, `:556`, `DEPLOY-READINESS-CHECK.md:352-353`). `/scheduling/availability`
   has shipped since `cc0ed68`.

**Wrong, and would mislead during debugging:**

6. **`horizon:status` never prints "ACTIVE".** It prints `Horizon is running.` / `Horizon is inactive.` /
   `Horizon is paused.`
7. **The `/horizon` dashboard cannot be opened on a fresh production install** — it needs a super-admin, and
   no production path creates one. `DEPLOY-RUNBOOK.md:429` tells you to log in as one.
8. **The `MAIL_MAILER=log` trap leaves no log entry** under the shipped `LOG_LEVEL=warning`, because the
   transport logs at debug. `DEPLOY-CHECKLIST.md:21` says the message is written to the log.
9. **`tail storage/logs/laravel.log` reads a file that does not exist** under the template's
   `LOG_STACK=daily` — the file is `laravel-YYYY-MM-DD.log`.
10. **The runbook's mail test is unusable on a fresh tenant** — a reminder is gated on patient consent, so it
    silently skips. Use a password reset.
11. **Checklist step 6b's grep returns nothing.** `phpstan.neon` has no `parallel` key; the 600s figure is a
    PHPStan built-in default. The suggested fix is also not valid NEON as written.

**Stale or incomplete:**

12. **Three demo seeders named, four exist** (`DemoHospitalSeeder` missing) — `DEPLOY-RUNBOOK.md:462-469`.
13. **Runbook §10's demo-seed commands now throw.** QA-FIX.9b allows only `local`/`testing`; a demo box on
    `APP_ENV=production` gets a `RuntimeException`.
14. **`DEPLOY-READINESS-CHECK.md:280-282` says the demo seeders have "ZERO production guard".** They have had
    one since `4e610b0`.
15. **"The four demo seeders are guarded" is incomplete** — `SimulatedBillingMonthSeeder` has no guard and
    creates a full fake tenant.
16. **"194 module migrations"** is 17 short; there are 211 (216 total).
17. **The checklist's `tenant:add-admin` and `tenant:add-branch` expected output blocks are missing lines the
    commands really print** — a `name` line, and the three-line first-branch note.
18. **`DEPLOY-RUNBOOK.md:486-488` omits `--region`** from `tenant:create`, which is immutable.
19. **No MySQL minimum version anywhere**, while nine `CHECK` constraints need 8.0.16+ to be enforced.
20. **No Node version in the checklist** (`:47` says only "Node"); the floor is 22.
21. **The runbook's `chown` locks the deploy user out of its own next step** (`:269-270` then `:296-299`).
22. **`MAIL_ENCRYPTION=tls` in the runbook's paste-in block** is read by nothing — Laravel 12 uses
    `MAIL_SCHEME`, as the same runbook says at `:240`.

**Closed deploy gap:** `DEPLOY-FIX.2` (`2a39c2e`, D-235) replaced the availability screen's no-active-branch
`firstOrFail()` with its honest empty state. It covers both the fresh tenant and a mature practice that
deactivates its only site. This pack's other “known” entries were re-checked against the repository in
DOC-SYNC.1: they are either documented constraints or historical-document corrections, not open app defects.
