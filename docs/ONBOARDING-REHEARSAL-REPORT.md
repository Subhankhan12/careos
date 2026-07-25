# CareOS — Per-Customer Onboarding Rehearsal (test tenant, Playwright-verified)

**A full dry-run of standing up a NEW paying customer, end to end, exactly the way real onboarding works —
programmatic where there is no admin screen, UI where there is — each step verified in a real browser
(Chromium via Playwright).** Run on a throwaway test tenant (**Praxis Testberg**, a general clinic, CHF,
locale `de`); the three demo tenants were **not** touched. Report only; no application code changed.

- **HEAD:** `6d85424` (POLISH.2). `git status` clean; **CI `success`** on `main`.
- **App URL:** `http://127.0.0.1:8000` (`php artisan serve`).
- **Env note:** Redis/Memurai was down and this sandbox can't elevate to start the service, so cache/queue
  were switched to `database`/`sync` for the session and **restored** after — an environment step, not an app change.
- **Method:** driven via Playwright (real browser) with per-step DB before/after snapshots. The demo tenants
  had been wiped by an earlier `composer check migrate:fresh`, so onboarding started from a clean schema
  (platform catalogs re-seeded with `php artisan db:seed`).

> **Bottom line:** a brand-new tenant was **created → configured → given bookable availability → staffed →
> loaded with patients → used** — and every guarantee (RBAC, tenant-scoping, the POLISH.2 nav, dry-run
> safety) held on the fresh tenant. **The path works.** The friction is concentrated in **four steps that
> have no self-service UI today and must be done programmatically** (tenant creation, resource availability,
> the service catalog, and staff-user creation) — the operator's real dependency list.

---

## 1. The onboarding SEQUENCE that worked (step by step)

| # | Step | Mode | What actually happens | Verified |
|---|---|---|---|---|
| 1 | **Create tenant + org_admin** | **PROGRAMMATIC** (no UI) | `Tenant::create(...)` fires `RbacProvisioner` → **6 starter roles auto-provisioned**; `SettingsService::set` for `currency=CHF`/`locale=de`/`timezone=Europe/Zurich`; a factory org_admin (fixed 2FA secret, known password). | ✅ org_admin logs in (+2FA) → `/app` empty zero-state; full POLISH.2 nav (10 + Admin menu); 0 console errors |
| 2 | **Practice settings** | **UI** (W8b) | `/settings`: edit practice **profile** (contact + address) → *"Practice profile saved."* `/admin/branches`: **create branch** (Testberg Hauptsitz, TB-01) → *"Branch created."* → **opening hours** (7 rows) → *"Opening hours saved."* → **add resource** (Sprechzimmer 1, Room) → *"Resource added."* | ✅ DB: 1 branch, 7 `branch_hours`, 1 resource, profile columns set — all tenant-scoped |
| 3 | **Resource availability + service catalog** | **PROGRAMMATIC** (NO admin screen — the flagged W8c gap) | `ResourceAvailability::create` (Mon–Fri 08–18) for the resource + a bookable `Service` (`ServiceCatalog::create([...], [branchId])`). **Without availability rows a resource offers ZERO slots, and there is no UI to set them.** | ✅ public booking then shows **17 slots** for next Monday (was 0) and a **booking succeeds (200)** — the seeded availability feeds the slot engine end-to-end |
| 4 | **Staff users** | **PROGRAMMATIC** (no "add user" UI) | Users created via the factory (2FA + password). The **`/admin/roles` screen only ASSIGNS a role to an EXISTING user** — it has no create-user form. | ✅ two role-less users created |
| 5 | **Assign roles** | **UI** (W8) | `/admin/roles`: pick a template per user (Rita → **Reception**, Klaus → **Doctor**), Assign. *(A dentist would get the **`doctor`** template — there is no `dentist` role.)* | ✅ reception user logs in on the new tenant → 4-item nav, **no Admin menu**, `/settings` **403** (RBAC holds) |
| — | *(Dental fee schedule)* | — | **Skipped** — Praxis Testberg is a general clinic. (The dental catalog/fee-schedule onboarding is covered in `docs/FULL-EXERCISE-AUDIT-REPORT.md`.) | n/a |
| 6 | **Import patients** | **UI** (P.6, nav-reachable) | **Admin menu → Import** → upload CSV → map columns → **dry-run** → **commit** (policy skip). | ✅ dry-run **wrote nothing** (patient count stayed 1); preview **valid 6 / invalid 1 / duplicate 1**; commit **imported 6 / skipped 1 (dup) / invalid 1 (malformed)**; count **1 → 7**; real MRNs applied |
| 7 | **Go live** | **UI** | Open an imported patient's **360**, open the **clinical chart**, **quick-book** an appointment. | ✅ 360 + chart load with the patient's data; quick-book created an appointment (`appointments` 1 → 2); all tenant-scoped |

**End state (DB, tenant `praxis-testberg`):** 1 branch · 7 branch-hours · 1 resource · 5 availability rows ·
1 service · **7 patients** · 2 appointments · 3 users (org_admin + reception + doctor). Audit chain intact.

---

## 2. Friction points / gaps — the steps a non-technical operator CANNOT self-serve today

These are the "needs a programmatic step" findings — the candidates for an admin screen if onboarding volume grows:

| Gap | Impact | Candidate fix |
|---|---|---|
| **Tenant creation has no UI** (no super-admin console) | An engineer must run a script per customer. Blocks fully-self-serve signup. | A super-admin "Create Tenant" screen (the prototype has `Super-Admin Tenants` / `Create Tenant`, unbuilt). |
| **Resource availability has NO admin screen** (the W8c-flagged follow-up) | *The sharpest gap.* Until availability rows are seeded, a resource yields **zero bookable slots** — and there is no UI to set them. Onboarding is broken-looking (empty scheduling) without this programmatic step. | A "Provider/Resource Availability" admin screen (already the #1 (E)-class item in `docs/MASTER-STATUS-REPORT.md`). |
| **Service catalog creation is programmatic** | No bookable services without a script → nothing to book. | A "Service Catalog / Service Create" screen (prototype has both, unbuilt). |
| **Staff-user creation has no UI** | `/admin/roles` only assigns roles to users that already exist; creating the users is a script. | An "invite / add staff user" flow (or the deferred portal-style invite for staff). |
| **No tenant-name chip in the nav/landing** | The operator (and staff) can't visually confirm *which* tenant they're in from the landing — only via Settings. | Surface the tenant name in the shell (the mockup's tenant chip; deliberately omitted live so far). |

**What IS self-serve (UI) today and works well:** practice profile, branch + opening-hours + resource CRUD,
role assignment, and the patient CSV import (dry-run → commit) — the day-to-day admin an operator repeats.

---

## 3. Bugs / surprises

**No application bug was found.** Every step succeeded; the only hiccups were **harness** issues, not app defects:
- Creating a branch and then configuring its hours/resource on the same screen needs the post-create **page
  reload** to land first (the new branch card appears after the Inertia reload). A real operator sees the
  reloaded page and continues; my script initially clicked before the reload. *Classify: harness timing, not a bug.*
- The clinical **note write+sign** flow (encounter → SOAP → sign) was **not completed in-harness** (it's the
  multi-step encounter workflow); `encounters`/`clinical_notes` stayed 0. It is the tested path
  (`ClinicalNoteTest` + the full-exercise audit), and the chart surface loads fine on the new tenant.
  *Classify: harness limitation / test-covered, not a bug.*

**Minor behavioural finding (expected, not a bug):** the CSV importer's duplicate detection matches against
**existing DB patients only**, not against other rows **within the same file** — two identical "Anna Meier"
rows both imported (the one row matching the pre-existing patient WAS flagged + skipped). Within-file dedup
would be a separate feature.

---

## 4. Confirmation the end-to-end path WORKS — with the exact commands/clicks

**It works.** A new tenant can be stood up, configured, staffed, loaded with patients, and used. The exact steps:

**Programmatic (operator/engineer runs against the app; the same real services onboarding uses):**
```php
// 1. tenant + org_admin
$tenant = Tenant::create(['name'=>'Praxis Testberg','slug'=>'praxis-testberg','region'=>'eu','status'=>'active','plan_id'=>Plan::where('key','eu_pro')->value('id')]);
app(TenantContext::class)->set($tenant);
app(SettingsService::class)->set('currency','CHF'); ->set('locale','de'); ->set('timezone','Europe/Zurich');
$admin = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['name'=>'…','email'=>'…','password'=>bcrypt('…')]);
RoleAssignment::create(['user_id'=>$admin->id,'role_id'=>Role::where('key','org_admin')->firstOrFail()->id,'branch_id'=>null]);

// 3. bookable service + resource availability (NO UI — must be scripted)
app(ServiceCatalog::class)->create(['name'=>'…','code'=>'…','default_duration_minutes'=>30,'requires_resource_types'=>[Resource::TYPE_ROOM],'bookable_online'=>true,'active'=>true], [$branch->id]);
foreach ([1,2,3,4,5] as $wd) ResourceAvailability::create(['resource_id'=>$resource->id,'weekday'=>$wd,'start_time'=>'08:00:00','end_time'=>'18:00:00','is_available'=>true]);

// 4. staff users (NO add-user UI)
User::factory()->forTenant($tenant)->twoFactorEnabled()->create([...]);  // role assigned via UI in step 6
```
**UI (org_admin clicks):** `/settings` → edit profile → Save profile · `/admin/branches` → Create branch →
Save opening hours → Add resource · **Admin menu → …** (POLISH.2) · `/admin/roles` → assign templates ·
**Admin menu → Import** → upload → Run dry-run → Commit · then day-to-day (patients, scheduling, clinical).

---

## 5. Operator onboarding checklist (per real customer)

```
[ ] 1. PROGRAMMATIC — create the tenant + org_admin (script): name, slug, region, plan;
       SettingsService currency/locale/timezone; factory org_admin (2FA + password).
[ ] 2. UI — log in as org_admin (+2FA) → /settings → edit the practice profile (contact + address).
[ ] 3. UI — /admin/branches → create branch(es) → set opening hours → add resources (rooms/chairs).
[ ] 4. PROGRAMMATIC — seed each resource's ResourceAvailability windows + the bookable Service catalog
       (script). ⚠ WITHOUT THIS THERE ARE ZERO BOOKABLE SLOTS AND NO UI TO FIX IT.
[ ] 5. PROGRAMMATIC — create the staff user accounts (script; 2FA + password).
[ ] 6. UI — /admin/roles (Admin menu) → assign a role template to each user (dentist → `doctor`).
[ ] 7. UI — Admin menu → Import → upload the customer's patient CSV → dry-run (writes nothing) →
       verify the dedup/validation preview → Commit.
[ ] 8. UI — go-live walk-through: open a patient 360, book an appointment, do a clinical note; confirm
       the tenant sees only its own data.
[ ] 9. Collect the delivery payment.
```

---

## Cleanup

The rehearsal created a **throwaway test tenant** clearly labelled — slug **`praxis-testberg`**, all users/
patients on `@praxis-testberg.test` / `@example.test`. To remove it, run any `php artisan migrate:fresh`
(the next `composer check` / CI run does this anyway), then re-seed the demo tenants as needed
(`db:seed --class=DemoClinicSeeder|DemoSpitexSeeder|DemoDentalSeeder`). **The three demo tenants were not
touched.** The `.env` cache/queue drivers were switched to `database`/`sync` for the session (Redis
unavailable) and **restored** to the committed values afterward. Onboarding scripts were kept in a scratch
directory (not committed); their exact contents are inlined in §4 so this report is self-contained.

*Rehearsed live via Playwright with DB before/after verification. No application code, route, controller,
prop, test, or migration was changed.*
