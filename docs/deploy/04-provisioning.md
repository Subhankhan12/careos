# Part 4 — First-customer provisioning

**The verified sequence from DEPLOY-FIX.1, in order.** Every command signature and every expected output
below was read off the command class at `73d8ed9`, not copied from another document — two of the blocks in
`DEPLOY-CHECKLIST.md` turn out to be missing lines the commands really print, and those are corrected here.

Run these **once**, by hand, on the first install. They are not part of `release.sh` and must not be
automated: `tenant:create` takes an **immutable** `--region`.

> **Never use Tinker for any of this.** `DEPLOY-READINESS-CHECK.md:258-265` still prints a copy-pasteable
> `php artisan tinker` recipe that calls `Tenant::create([...])` directly. It is hedged at `:254` as
> superseded, but the block is still there to be pasted. It bypasses the duplicate-slug refusal, the
> region/status validation and the role-template verification, and — critically — it writes **no
> `timezone` setting**, which then silently breaks trap 2 below. Use the commands.

---

## The order, and why it is this order

```
migrate --force  →  db:seed --force  →  plans:seed  →  tenant:create
                 →  tenant:add-branch  →  tenant:add-admin
                 →  first login  →  forced 2FA enrolment  →  day-board loads
```

---

### 1. `php artisan migrate --force`

**Expected:** every migration `DONE`. On a fresh database, **216 migrations** (5 app + 211 module) producing
**178 tables**.

```bash
php artisan migrate:status | grep -i pending      # expect: no output
```

The 42-row permission catalog is created by *migrations*, before any seeder runs.

> `DEPLOY-RUNBOOK.md:35` says "all 194 module migrations". That number is 17 short — there are 211 module
> migration files today. `DEPLOY-CHECKLIST.md:110`'s measured 216 total is the correct figure.

---

### 2. `php artisan db:seed --force`

**Expected, verbatim:**
```
   INFO  Seeding database.

  Database\Seeders\PermissionCatalogSeeder ....... DONE
  Database\Seeders\PlanCatalogSeeder ............. DONE
```

`DatabaseSeeder` calls **only** these two (`database/seeders/DatabaseSeeder.php:36-37`). It contains no demo
seeder, so this is safe and required in production.

**If skipped:** `plans` is empty, every tenant has no plan, and **every plan-gated feature — telehealth, EVV,
ai_drafting — is silently OFF.**

> **One caveat none of the four docs mentions.** On a box running `APP_ENV=local` or `testing` the *same*
> command also creates a platform super-admin from the framework skeleton
> (`database/seeders/DatabaseSeeder.php:39-46`, guarded by the same allow-list as the demo seeders). On a
> production box that block returns early and no such account is created. This matters in both directions:
> a demo box ships with a super-admin nobody documented, and a production box has **no super-admin at all**
> — see §8 below.

---

### 3. `php artisan plans:seed`

**Expected, verbatim:**
```
Plans seeded: 2 total (0 new).
  eu_pro       EU Pro       +telehealth -evv +ai_drafting
  eu_starter   EU Starter   -telehealth -evv -ai_drafting
Assign one to every tenant — a tenant with no plan has every feature OFF.
```

`(0 new)` is the **expected** result after step 2 — `db:seed` already ran `PlanCatalogSeeder`. The command is
idempotent; run it here as a *verification* that plans exist and to read their feature flags.

---

### ⚠️ TRAP 1 — `plans:seed` must precede `tenant:create`

If you skip steps 2 and 3, `tenant:create` refuses:

```
Unknown plan [eu_pro].
  No plans exist yet — run: php artisan plans:seed
```

*(`app/Console/Commands/CreateTenantCommand.php:159-161`)*

**This reads like a bug and is the product being correct.** A tenant created against a non-existent plan would
have every plan-gated feature off with no indication why — so the command refuses instead of creating a
half-configured customer. The fix is to run `plans:seed`, not to bypass the check.

---

### 4. `php artisan tenant:create`

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

| Option | Default | Notes |
|---|---|---|
| `--slug` | derived from the name | must be unique |
| `--plan` | `eu_pro` | see trap 1 |
| `--region` | `eu` | **IMMUTABLE after creation** (`CreateTenantCommand.php:42`) |
| `--currency` | `EUR` | drives money rendering for every user in the tenant |
| `--locale` | `en` | drives date/UI rendering |
| `--timezone` | `Europe/Zurich` | **the branch inherits this — see trap 2** |
| `--status` | `active` | |

**Set `--region`, `--currency` and `--locale` deliberately.** `--region` cannot be changed afterwards.

> `DEPLOY-RUNBOOK.md:486-488` gives this command **without `--region`**, so a US customer onboarded from the
> runbook is silently pinned to `eu` forever. Always pass it explicitly.

---

### 5. `php artisan tenant:add-branch`

```bash
php artisan tenant:add-branch praxis-example --name="Hauptstandort" --code=HAUPT
```

**Expected — including three lines `DEPLOY-CHECKLIST.md:256-264` omits:**
```
Branch created for Praxis Example
  id        01m2ctfkd741k7j3h9efaw91rh
  name      Hauptstandort
  code      HAUPT
  timezone  Europe/Zurich  (from the tenant setting)
  primary   yes

This is the tenant's FIRST branch, so it is their primary site and the day-board
now has somewhere to render. Next: add bookable resources (rooms/chairs) in the app
under Admin → Branches — a resource needs availability before it can be booked.
```

The first branch of a tenant is automatically its primary site — `Branch::booted()` owns that invariant on
every creation path, which is exactly why this command **never passes `is_primary`** itself.

The command is not bootstrap-only; use it again for a second site.

---

### ⚠️ TRAP 2 — the branch takes the **tenant's** timezone, not UTC

`AddTenantBranchCommand.php:94-96`: when `--timezone` is omitted the command reads
`$settings->get('timezone', config('app.timezone'))` — the value `tenant:create` wrote **for this tenant**.
That is why the output says `(from the tenant setting)`.

Two things follow:

- **In the UI this trap is live.** `Admin → Branches → Add branch` defaults its timezone select to the first
  option, which is `UTC` (`resources/js/pages/Admin/Branches.vue:70`, `BranchController.php:32`). Set it
  explicitly there. The command does not have this problem.
- **If the tenant was created by Tinker, the fallback fires.** A hand-made tenant has no `timezone` setting,
  so `config('app.timezone')` — `UTC` (`config/app.php:68`) — is used and the branch silently lands in the
  wrong zone. Another reason not to use Tinker.

---

### 6. `php artisan tenant:add-admin`

```bash
php artisan tenant:add-admin praxis-example \
  --email=admin@praxis-example.test --name="Dr. Example"
```

**Expected — the `name` line is missing from `DEPLOY-CHECKLIST.md:173-176`:**
```
Administrator created for Praxis Example
  login     admin@praxis-example.test
  name      Dr. Example
  role      org_admin (all branches)

  TEMPORARY PASSWORD (shown once — deliver it out of band, then have them change it):
      0bZ54#as2A[.gX#r

First login: they will be REQUIRED to enrol two-factor authentication before
reaching the app. 2FA is mandatory and has no skip path.
```

**The password is printed once.** Capture it before the terminal scrolls. Pass `--password=` to set one
yourself, in which case the temporary-password block is not printed at all.

**If skipped:** nobody can log in. The invite flow structurally cannot create the first admin.

---

### 7. First login and forced 2FA enrolment

Sign in with the temporary password. You are redirected to `/two-factor/enrollment`. Scan the QR or use the
manual key, then enter a six-digit code.

**Measured trap:** the first attempt can fail **silently** — no error, the page simply stays — because the
code expired during a slow request. If enrolment appears to do nothing, enter a fresh code immediately.

Confirm success:
```sql
SELECT two_factor_confirmed_at FROM users WHERE email='admin@praxis-example.test';
```
**Expected:** not `NULL`.

2FA is mandatory and has **no skip path** — without enrolment the admin cannot reach the app at all.

---

### 8. The day-board loads

Navigate to `/scheduling/day-board`. **Expected:** HTTP 200, rendered in the tenant's locale.

With a branch but no bookable resources yet, the board renders an honest empty state
(*"No bookable resources yet…"*) rather than an error. With **no branch at all** it renders a different empty
state — `DEPLOY-FIX.1a` (`b8d5777`) replaced what used to be an HTTP 404.

Then set up resources and their availability:

- **Resources** (rooms, chairs, devices, practitioners): `Admin → Branches`.
- **Availability**: `Scheduling → Availability` at **`/scheduling/availability`**
  (`routes/web.php:199-208`, gated on `appointment.manage`).

> **Correction — the most expensive error in the existing docs, and it appears four times.**
> `DEPLOY-RUNBOOK.md:503` and `:19` and `:556`, and `DEPLOY-READINESS-CHECK.md:352-353`, all state that
> **"availability windows have no admin screen yet"** and must be **"seeded programmatically"**. That is
> **wrong**. `SCHED.P3` (`cc0ed68`, an ancestor of HEAD) shipped the screen: `AvailabilityController`
> with index/store/impact/update/destroy routes and `resources/js/pages/Scheduling/Availability.vue`,
> covering every resource type (`practitioner`, `room`, `chair`, `device`) and reading the same
> `AvailabilityService` the slot finder uses. An operator following the docs would write a seeder for
> something the product already does through the UI.

---

### ✅ Closed no-branch availability gap

`DEPLOY-FIX.2` (`2a39c2e`, D-235) closed the sibling of the day-board's no-branch 404. The availability
controller now authorises first, resolves an active branch with `first()`, and renders the page's honest empty
state when none exists. Both the fresh-tenant and mature-tenant-after-deactivation paths are covered; this is
not an outstanding provisioning defect.

---

### 9. Confirm no demo data reached the box

```sql
SELECT slug FROM tenants;
```
**Expected:** only your customer's slug. If you see `praxis-lindenhof`, `spitex-sonnengarten`,
`zahnarztpraxis-morgenstern`, `klinik-bergblick` or `simulated-june-clinic`, a demo seeder ran — stop and
rebuild the database.
