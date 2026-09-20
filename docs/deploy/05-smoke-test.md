# Part 5 — Smoke test, to run on the server after provisioning

Nine checks, in order. Each has a **literal** expected result — every string below was read off the code at
`73d8ed9`, because a smoke test an operator has to interpret is not a smoke test.

Stop at the first failure and run **Part 6 (capture protocol)** before attempting a fix.

---

### 1. The app answers at all

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://app.careos.example/up
```
**Expected:** `200`

`/up` is a real health endpoint this app already publishes (`bootstrap/app.php:24` — `health: '/up'`). It
boots the framework without requiring a session, so it separates "PHP/nginx/opcache is wrong" from "the app
is wrong". **No existing deploy document uses it.**

---

### 2. The login page renders — a page, not a 500

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://app.careos.example/login
```
**Expected:** `200`.

Then open it in a browser and confirm the form is **styled**. A 200 with unstyled HTML means
`public/build/manifest.json` is missing or stale — the Vite build did not run on this server.

Confirm `APP_DEBUG=false` at the same time: request a bogus URL and expect a clean 404 page, **not** a
Laravel stack trace. A stack trace on a healthcare box leaks PHI paths and config.

---

### 3. A real login completes, including 2FA

In a browser: sign in as the admin from Part 4 §6, then complete the forced 2FA enrolment.

**Expected:** you reach the app. **Verify server-side rather than trusting the redirect:**
```sql
SELECT email, two_factor_confirmed_at FROM users WHERE email='<admin email>';
```
**Expected:** `two_factor_confirmed_at` is not `NULL`.

If enrolment appears to do nothing and no error shows, the six-digit code expired mid-request — enter a fresh
one immediately.

---

### 4. The day-board loads

Navigate to `/scheduling/day-board`.

**Expected:** HTTP 200, rendered in the tenant's locale. With a branch and no resources yet you get an honest
empty state, not an error. (Before `DEPLOY-FIX.1a`/`b8d5777` a tenant with no branch got an HTTP 404 here.)

---

### 5. Horizon is running, and consuming the queue the config declares

```bash
php artisan horizon:status
```
**Expected, verbatim:** `Horizon is running.`

> **The word "ACTIVE" is not what Horizon prints.** `vendor/laravel/horizon/src/Console/StatusCommand.php`
> emits exactly three strings — `Horizon is running.` (:48), `Horizon is inactive.` (:35) and
> `Horizon is paused.` (:43). Match on those. `Horizon is inactive.` is the **only loud signal** you get that
> the queue is dead.

Now confirm the running supervisor matches the config, which is the check that would have caught the
`DEPLOY-FIX.1b` defect years earlier:

```bash
php artisan tinker --execute="echo json_encode(config('horizon.defaults.supervisor-1.queue'));"
sudo systemctl status careos-horizon --no-pager | head -5
```
**Expected:** `["default"]`, and a unit that is `active (running)`.

> **The `/horizon` dashboard is NOT a usable check on a fresh production install.**
> `DEPLOY-RUNBOOK.md:429` says to "log in as a super-admin to view it". On a production box **no super-admin
> can exist**: `config/horizon.php:86` requires the `super-admin` middleware and the gate at
> `app/Providers/HorizonServiceProvider.php:31` requires `isSuperAdmin()`, which means `tenant_id === null`
> (`Modules/Platform/src/Models/User.php:76`) — but `tenant:add-admin` always sets a tenant
> (`AddTenantAdminCommand.php:126`), and the only `tenant_id`-null account in the seeders is skipped outside
> `local`/`testing`. **`horizon:status` is the only Horizon check you actually have.** Treat the dashboard as
> unavailable rather than as something that is broken.

---

### 6. A queued job is actually consumed

This proves the whole chain — connection, supervisor, worker — rather than just that a daemon is alive.

```bash
# before
redis-cli -n 0 llen queues:default

# push one real job through the app's own dispatcher
php artisan appointments:dispatch-reminders

# after — then watch it drain
redis-cli -n 0 llen queues:default
sleep 5
redis-cli -n 0 llen queues:default
```
**Expected:** the count rises (if any reminder is due) and returns to `0` within seconds as Horizon consumes
it. If it rises and **stays**, Horizon is not consuming `default` — recheck §5.

With no reminder due the count stays `0` throughout, which proves nothing. In that case confirm the
connection pin directly:
```bash
grep -n "onConnection" Modules/Scheduling/src/Services/ReminderDispatcher.php | grep -v '\*'
```
**Expected:** one line — `->onConnection('redis');`

---

### 7. A real email is SENT and RECEIVED

**Trigger a password reset** for a mailbox you control, from `/forgot-password`.

**Expected:** the message arrives in that inbox.

> **Use a password reset, not a reminder.** `DEPLOY-RUNBOOK.md:431` suggests triggering "a reminder or a
> portal action". A reminder is the wrong test: `SendAppointmentReminderJob.php:70-71` skips silently unless
> the patient holds `comms.email` consent **and** has an email contact row. A reminder that does not arrive
> is indistinguishable from a broken mailer. `DEPLOY-READINESS-CHECK.md:344` gets this right.

**This check cannot be skipped, because its failure mode is silent.** With `MAIL_MAILER=log` (the code
default) every message is accepted with **no exception thrown**.

> **And it is worse than the checklist says.** `DEPLOY-CHECKLIST.md:21` states the message is "written to
> `storage/logs/laravel.log`". Laravel's log transport writes at **debug** level
> (`LogTransport::send()` → `$this->logger->debug(...)`), while both production env files set
> `LOG_LEVEL=warning`. A debug record at warning level is **discarded**. So on a by-the-book install the
> email is neither delivered **nor logged** — there is no trace at all. That makes this the hardest failure
> in the whole deploy to diagnose after the fact, and the reason to test it deliberately now.

```bash
grep '^MAIL_MAILER' .env        # must NOT be 'log'
```

---

### 8. The scheduler has ticked

```bash
sudo crontab -u www-data -l          # the line is installed
php artisan schedule:list            # all nine, with next due times
```

Then prove it actually **fires** — installation is not execution, and a wrong `cd` path makes the whole cron
line a silent no-op:

```bash
sudo grep CRON /var/log/syslog | tail -5
```
**Expected:** a `schedule:run` invocation within the last minute.

The fastest real side effect is the five-minute waitlist sweep. Wait six minutes and confirm something moved
rather than assuming.

---

### 9. The audit chain verifies

```bash
php artisan audit:verify-chains
```
**Expected, one line per ACTIVE tenant:**
```
CHAIN:OK praxis-example (0 events)
```
*(`app/Console/Commands/VerifyAuditChainsCommand.php:69` — the literal format is
`CHAIN:OK %s (%d events)`.)*

**A silent run is not a pass.** The loop only covers tenants with `status = 'active'`
(`VerifyAuditChainsCommand.php:48`), so **zero active tenants produces no output and exit code 0**. If you
see nothing, you have no active tenant — not a clean chain. Check the exit code *and* that you got a line per
tenant you expect.

Failures print `CHAIN:BROKEN <slug> at <id>` on stdout (not stderr) and set a non-zero exit code.

> No deploy document in this repo quotes `CHAIN:OK`, so an operator has had nothing to match against.

---

### 10. The Nurse PWA loads and syncs from its own origin

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://app.careos.example/nurse-pwa/
curl -sS -o /dev/null -w '%{http_code}\n' https://app.careos.example/nurse-pwa/sw.js
```
**Expected:** `200` for both.

**This is the check most likely to fail on a by-the-book install.** The PWA is a static SPA built to
`public/nurse-pwa/` with `base: '/nurse-pwa/'` (`nurse-pwa/vite.config.ts:7,30`) and **no Laravel route** —
`grep -rn nurse-pwa routes/` returns nothing. The runbook's nginx block declares only `index index.php`
(`DEPLOY-RUNBOOK.md:380`), so a request for `/nurse-pwa/` finds no `index.php` there, falls through to
Laravel and 404s — while the same runbook asks you to verify this exact URL at `:446`.
`docs/deploy/nginx-careos.conf` fixes it.

Then in a browser, log in as a nurse and confirm the day-pack loads.

**Why "its own origin" matters:** the API is **token-only**. `bootstrap/app.php:27` carries an explicit
instruction not to re-add `statefulApi()`, because Sanctum would treat the PWA's own origin as a first-party
SPA and CSRF-check it — which is what made every PWA `POST` return 419 while `GET /day-pack` still returned
200: patient data reached the device and no recorded care could come back (QA-FIX.4a, D-201). If PWA reads
work but writes fail with 419, that regression is back.

Finally confirm the service worker is **not** being cached:
```bash
curl -sSI https://app.careos.example/nurse-pwa/sw.js | grep -i cache-control
```
**Expected:** `no-cache, no-store, must-revalidate`. A long-cached `sw.js` pins every nurse device to a stale
precache manifest that `registerType: 'autoUpdate'` can then never replace.
