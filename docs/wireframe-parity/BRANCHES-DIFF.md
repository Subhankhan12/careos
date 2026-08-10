# Admin Branches — Wireframe-Parity Diff (audit only)

**Scope:** diff the LIVE `/admin/branches` against the decoded wireframe
`resources/prototype/branches.wireframe.html` (+ the companion create flow
`resources/prototype/branch-create.wireframe.html`) on every axis (layout, list/detail, cards, fields,
controls, styling, states, copy, AND real actions/guards). **This is an audit. No app code was changed.**
Page 3 of the wireframe-parity pass (Admin Settings ✓, Approval Queue ✓).

- **Date:** 2026-08-09 · **Branch:** `main` · **HEAD:** `ea0e9b3` · **CI:** green.
- **Env:** `migrate:fresh --seed` + `DemoClinicSeeder`. Driven in Playwright as
  `andrea.lindenhof@praxis-lindenhof.test` (org_admin, 2FA).
- **Seed reality:** the demo tenant has **ONE** branch (`Zürich Oberstrass`, code `ZH-OBS`, 10 active
  resources, 0 upcoming appts). The wireframe mocks **two** (Oberstrass + Altstetten). This is demo-data, not
  page shape — the live page renders one card **per** branch.

> **THE DISCIPLINE ON THIS PAGE IS INVERTED.** The Branches backend is BUILT and RICH (CLINIC.W8b branch +
> hours + W8c resources, with real cross-module deactivation guards). So the parity job is: bring the VISUAL to
> the wireframe's master-detail **without regressing the richer live functionality or weakening a real guard**.
> Correctly-more-real is expected to be the LARGEST class here. The one genuine product question is
> **suspend-vs-deactivate** (§5) — surfaced, not resolved.

---

## 1. The live page found & how it maps to the wireframe

| Live | Detail |
|---|---|
| Route | `GET /admin/branches` + `POST` store / `{branch}/update` / `{branch}/hours` / `{branch}/deactivate` / `{branch}/activate` / `{branch}/resources` (+ `resources/{resource}/update|deactivate|activate`) — all `admin.manage` |
| Controller | `App\Http\Controllers\BranchController` (+ `ResourceController`) — **app-layer** (the deactivation guard spans Platform's `Branch` + Scheduling's `Appointment`/`Resource`, which module boundaries forbid inside a module) |
| Services | `App\Services\BranchService` (`create`/`update`/`setActive`/`setHours`/`futureAppointmentCount`/`activeResourceCount`) · `App\Services\ResourceService` |
| Models | `Modules\Platform\Models\Branch` (name, code, address_line1/2, city, postal_code, country, timezone, active) · `BranchHours` (weekday 0–6, is_closed, open/close) · `Modules\Scheduling\Models\Resource` (type room/chair/vehicle/**practitioner**, active) |
| Vue | `resources/js/pages/Admin/Branches.vue` |

**Mapping:** the live page is a **single-column vertical stack** — an "Add a branch" form card at the top, then
**one expanded Card per branch** (status row + editable profile form + per-day hours editor + resource CRUD).
The wireframe is a **two-pane master-detail** (300px selectable branch LIST | 1fr DETAIL with 4 stacked cards
for the selected branch). Same *content domains* (profile, hours, resources, status), very different *shape and
chrome*. The live app is **functionally richer** almost everywhere; the wireframe is **visually richer**.

---

## 2. Section-by-section diff

Class: **(a)** visual/layout · **(b)** feature/backend gap · **(c)** guard/behavior reconciliation · **(d)**
correctly-more-real (KEEP). Sev = distance from parity, weighted by governance.

| Section | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| **Page frame** | Glass top-nav pill (Dashboard/Approvals/KB/Settings) + breadcrumb "Settings › Branches" + euca-wash | AppLayout app bar; eyebrow "Administration" → "Branches" → subtitle + a "← Back to settings" link | Different chrome/IA; no breadcrumb; live has a back-link | (a) | Low |
| **Header + Add** | "Branches" + "Two locations · resources and hours are set per branch." + **[+ Add branch]** button → a separate **Create** screen | "Branches" + "Manage your locations, their opening hours and status." + a full **"Add a branch" form card inline** | Live adds a branch **inline** (no wizard/modal); more direct, less guided | (a)/(d) | Med |
| **Master list (300px)** | Selectable branch rows: home icon, name, **"N resources · main"**, badges **● Active** + **Online booking on/off**; left-accent on selected | **absent** — no list pane; every branch is a full expanded card | No master list / selection model | (a) | High |
| **Branch detail — profile** | Card: **Branch name**, **Phone**, **Address** (single line) + **[Save branch]** + an **"Accept online bookings" toggle** | Form: **Branch name**, **Code**, **Address line 1**, **City**, **Postal code**, **Country (2-letter)**, **Timezone** (select) + **[Save details]** | Live has **code/city/postal/country/timezone** (richer, structured); wireframe has **Phone** (no live field) + a per-branch **online-booking toggle** (no live field) | (b)+(d) | High |
| **Opening hours** | Read-only grouped display: **Mon–Thu** 08–18 · **Friday** 08–16 · **Saturday** 08–12 · **Sun·holidays "Closed"** + [Save hours] | **Editable per-day** (7 rows): day label + **Closed** checkbox + **open/close `<input type=time>`** each; client+server validate close>open; default 07:00–19:00 note + [Save opening hours] | Live is a **real editor** (7 days, validated); wireframe is a static grouped view | (a)+(d) | Med |
| **Resources** | Rows: avatar initials + name + **type chip [Practitioner/Room]** + **status chip [Active]** + [+ Add resource] | Rows: **Active/Inactive** pill + **editable name** input + **type `<select>` (Room/Chair/Vehicle)** + [Save details] + [Deactivate]/[Activate] (guarded) + an **add-resource** row (name + type + [Add resource]) | Live is **full CRUD + guard** (richer). BUT the type select offers only room/chair/vehicle — a **practitioner** resource can't be represented (see §5/§6) | (d)+(b) | Med |
| **Suspend / status** | **Terracotta "Suspend this branch"** danger card: *"Stops new online bookings immediately. Existing appointments are kept; staff can still work the day-board."* + [Suspend branch] | Status row: **Active/Inactive** pill + counts + **[Deactivate]** (red button, **disabled when future appts > 0**) / **[Activate]**; hint "…reassign/cancel first" | **Different safety model** — live is a HARD block, wireframe a SOFT suspend. See §5 | **(c)** | **High** |
| **"main" meta** | "N resources · **main**" on the primary branch | **absent** — no primary/default-branch concept (`Branch` has `code`, no `is_primary`) | Wireframe implies a flag that doesn't exist | (b) | Low |
| **Styling** | Full Eucalyptus Glow (glass blur 24px, gradient border, radius-20, pills, status-pill-with-dot, terracotta danger, `eucardIn`) | Plain `Card` (radius **20px** ✓, soft green shadow ✓, **backdrop-filter: none**, transparent bg, no gradient border, no `eucardIn`); standard Button variants | Radius/shadow match; **glass/blur/gradient-border/eucardIn/status-dot/terracotta absent** | (a) | Med |
| **States** | (mock) active + booking-on/off | Real: Active/Inactive pill; "0 upcoming appointments"; "N active resources"; empty-resources note; deactivate-blocked hint; hours-invalid disables Save | Live states are real + richer | (d) | — |

---

## 3. Visual deltas to reach master-detail parity (measured)

| Token | Wireframe | Live (measured) | Delta |
|---|---|---|---|
| Layout | 2-pane grid `300px 1fr`; select a branch → detail | single-column stack, all branches expanded | **restructure to master-detail** (largest visual item) |
| Card | glass: `blur(24px)`, white→sage gradient border, radius 20, `eucardIn` | radius **20 ✓**, shadow `rgba(53,70,47,.05/.11)` ✓, **blur none**, bg transparent, no gradient border, no entrance | add glass/blur + gradient border + `eucardIn`; keep radius/shadow |
| Branch list row | pill badges **● Active** (dot) + **Online booking on/off**; left green accent on selected | Active/Inactive pill (no dot); no booking badge; no selection | add the dotted status pill + selection accent; booking badge needs a real source (§4) |
| Danger card | terracotta panel (`#B4552D`, `rgba(243,226,217,.4)`) + outline button | red `Button variant=danger` in the status row | build the terracotta suspend/deactivate panel |
| Buttons | pill/gradient green primary; secondary glass | `Button` (radius ~12, gradient green primary) | pill radius + glass secondary |
| Type | Inter ✓ | Inter ✓ | match |
| Canvas | euca-wash radial glows | euca-wash via AppLayout ✓ | match |

---

## 4. Feature / backend gaps (wireframe shows something with no/partial live backend)

1. **Per-branch "Accept online bookings" toggle (b).** `Branch` has **no** `accepts_online_bookings` column — only
   `active`. Online-booking exposure today is governed by **service-level `bookable_online` + branch `active` +
   branch hours** (not a per-branch switch). Surfacing the wireframe toggle honestly requires **either** (i) a new
   `Branch.accepts_online_bookings` column + its enforcement in the slot/booking path (a real backend change → its
   own gate), **or** (ii) re-labelling the control to reflect the real gate (e.g. show booking-eligibility derived
   from active + bookable-online services), not a faked switch. **Do not add a toggle that persists a value nothing
   reads.**
2. **"main" / primary-branch flag (b).** No `is_primary`/`is_default` on `Branch`. The wireframe's "· main" is
   **cosmetic today**. Either add a real default-branch flag (backend gate) or drop the label — **do not fabricate**.
3. **Phone field (b).** `Branch` has no `phone`. The wireframe's Phone field has no backend. Add a nullable
   `Branch.phone` column (small, additive) **or** omit — don't render a field that saves nowhere.
4. **Soft-suspend state (b/c).** The wireframe's "Suspend" (stop-new-online-bookings-only, keep existing) is a
   **distinct state** the model doesn't have (only `active` bool). See §5 — this is the key product decision.
5. **Create wizard (a/b).** The wireframe's create is a **3-step modal wizard** (Profile → Hours → Review) with
   "copy hours from" + booking-off-default; live is a flat inline form. The wizard is UX polish over the existing
   `store` endpoint (no new backend except optional "copy hours from" + phone). See §7.

---

## 5. Guard / behavior reconciliations (surface for a decision — do NOT weaken)

### 5.1 SUSPEND (wireframe) vs DEACTIVATE (live) — the crux

**Live behavior (verified in code + browser):** `[Deactivate]` → `BranchController::deactivate` →
`futureAppointmentCount(branch) > 0` **blocks** it (`back()->withErrors(['branch'=>'has_appointments'])`, with the
count); only when there are **zero** future blocking-status appointments does `setActive(false)` run. The UI also
disables the button while `future_appointments > 0` and shows a "reassign/cancel first" hint. Deactivation is a
**soft `active=false`** (never a hard delete). A deactivated branch drops from the day-board/booking; its
resources' guards mirror this. **This is a HARD safety guard: you cannot take a branch offline while it has
scheduled care.**

**Wireframe behavior:** "Suspend this branch — **Stops new online bookings immediately. Existing appointments are
kept; staff can still work the day-board.**" This is a **SOFT** state: it does **not** block on existing
appointments; it only stops *new online bookings* while leaving the branch operational.

**These are two different safety models.** The live guard is *more-real and safer*. Options (a PRODUCT decision —
**not** to be resolved by weakening the guard):

- **(a) Keep the live hard guard; relabel the wireframe intent.** Treat the wireframe's "Suspend" copy as
  aspirational and keep Deactivate's hard block. Visual: build the terracotta danger card but with the **real**
  copy ("Deactivating is blocked while N upcoming appointments exist — reassign or cancel them first"). Zero
  backend change. Safest; recommended default.
- **(b) Add a SOFT-SUSPEND as a NEW, DISTINCT action alongside Deactivate.** A real new branch state
  (`accepts_online_bookings=false` while `active=true`) that stops *new online bookings* but keeps existing
  appointments + the day-board — exactly the wireframe's semantics. This is a **real backend change** (a new
  column + enforcement in the booking/slot path + audit + its own tests) and must be **its own gate**. It does
  **not** replace or weaken Deactivate — it's a lighter, reversible control that sits above the hard guard.
- **(c) Reconcile:** ship (a) now (visual parity + honest copy), and queue (b) as a separate backend gate if the
  product wants the softer control. The two are independent (suspend online-bookings ≠ deactivate branch).

**Rule:** whichever is chosen, **the hard "can't-deactivate-with-future-appointments" guard stays** — it is the
more-real safety property and must not be softened to match a mock.

### 5.2 The online-booking gate

Confirmed real + branch-scoped: patients only ever see **active + bookable-online services at an active branch,
within its hours** (service `bookable_online` + `Branch.active` + `BranchHours` bound the slot finder). The
wireframe's "Online booking on/off" badge + "Accept online bookings" toggle should **reflect this real gate**, not
introduce a parallel switch that isn't enforced (tie to §4.1). Match the visual; keep the real gate authoritative.

---

## 6. Correctly-more-real items (KEEP — do NOT trim to the mockup)

*(Expected to be the largest class on this page.)*

- **Structured address + code + timezone** — live has `code`, `address_line1/2`, `city`, `postal_code`,
  `country`, `timezone` (a real per-branch tz select). The wireframe only shows a single free-text Address + Phone.
  **Keep the structured fields.**
- **Editable per-day opening hours** with a Closed toggle, `type=time` pickers, and **close>open validation**
  (client + server) + the "default 07:00–19:00" note. The wireframe is a static grouped display. **Keep the real
  editor.**
- **Full resource CRUD** — add/rename/retype + **activate/deactivate**, each with its **own** hard guard
  (`resource.future_appointments > 0` blocks deactivation, button disabled + hint). The wireframe resource list is
  read-only-ish. **Keep the CRUD + guards.**
- **The hard deactivation guard** (branch + resource) — the anti-orphaning safety property (§5.1). **Keep.**
- **Real counts + states** — "N active resources", "N upcoming appointments", Active/Inactive, empty-resources
  note, blocked hints — all from live data. **Keep.**
- **Audited writes** — `branch.created/updated/activated/deactivated/hours_changed` via the AppServiceProvider
  model hooks; app-layer service keeps module boundaries. **Keep.**

**One nuance to fix, not trim:** practitioner-type resources appear in the roster (correctly-more-real — the
wireframe shows practitioners too), but the edit **type `<select>` only offers room/chair/vehicle**, so a
practitioner resource has no matching option and saving could mis-set its type. Practitioner resources are
staff-profile-driven (W8c excludes them from creation) → their type (and name) should be **read-only** in this
roster, not editable via the room/chair/vehicle select. Small correctness fix, its own item.

---

## 7. Branch Create flow diff

**Wireframe (`branch-create.wireframe.html`) — a 3-step MODAL WIZARD over the dimmed Branches list:**
- **Step 1 · Profile:** Branch name, Address, Phone, **"Copy hours from" (existing branch)** + slate note *"The
  branch starts with online booking off — turn it on after you've added resources and services."* · Cancel /
  Continue · "Step 1 of 3".
- **Step 2 · Hours** (the wizard's hours step).
- **Step 3 · Review:** read-only summary (Name, Address, Hours "Copied from Oberstrass", Resources "Add after
  creating", Online booking **"Off · default"**) → **[Create branch]** / ← Back · then "Branch created · add
  resources to get started."

**Live:** a flat **"Add a branch" form card** inline at the top of `/admin/branches` — Branch name, Code, Address
line 1, City, Postal code, Country (2-letter), Timezone; **[Create branch]** (disabled until name+code). No
wizard, no modal, no "copy hours from", no phone, no explicit "booking off by default" note (booking-eligibility
is already derived, not a stored flag).

**Diff/classify:** (a) wizard/modal chrome is missing (visual/UX); (b) "copy hours from" + phone + the
booking-off-default messaging need small backend/copy support; (d) live captures **code + structured address +
timezone** the wizard omits — keep. **Note:** the live create requires a unique **Code** (real constraint) the
wireframe never mentions — keep the code field.

---

## 8. Prioritized parity punch-list

**Visual-parity first (safe, frontend-only — no guard touched). B = needs backend.**

1. **[High · a] Master-detail restructure** — a 300px branch **list pane** (selectable rows: name, "N resources"
   meta, **● Active** dotted pill + booking-state badge) beside a **detail pane** for the selected branch (profile
   / hours / resources / danger). Collapse the all-expanded stack into select-one-detail. `F`.
2. **[Med · a] Glass + eucardIn + pills + terracotta** — apply the glass card (blur/gradient border), `eucardIn`
   entrance, pill buttons, the dotted status pill, and the **terracotta danger card** for the deactivate/suspend
   action (keep radius/shadow which already match). `F`.
3. **[Med · a] Create as a modal wizard** (Profile → Hours → Review) over the dimmed list, over the existing
   `store` endpoint; keep the real **Code** field. `F` (+ small `B` for "copy hours from" / phone if adopted).
4. **[High · c] SUSPEND-vs-DEACTIVATE decision (§5.1)** — ship option (a) now: terracotta card with **honest**
   copy that states the real hard guard ("blocked while N upcoming appointments exist"). Do **not** weaken the
   guard. Queue option (b) (a real soft-suspend state) as a **separate backend gate** if the product wants it. `F`
   now; `B` gate later.
5. **[Med · b] Online-booking control** — either wire a real `Branch.accepts_online_bookings` (backend gate) or
   render booking-eligibility **derived** from active + bookable-online services; never a faked switch. Decision +
   possible `B` gate.
6. **[Low · b] "main"/default-branch flag** — add a real `is_primary` (backend gate) or drop the "· main" label.
   Don't fabricate. Decision.
7. **[Low · b] Phone field** — add nullable `Branch.phone` (additive) or omit. Decision.
8. **[Med · d/correctness] Practitioner resources read-only in the roster** — the type select must not offer
   room/chair/vehicle for a `practitioner` resource (it can't represent it); show practitioner name/type
   read-only. Small `F` (+ guard the server if needed). `d`+fix.
9. **[Keep · d]** structured address/code/timezone, the per-day validated hours editor, full resource CRUD +
   guards, the hard deactivation guard, real counts/states, audited writes — **do not trim to the mockup.**

**Fence/guard for the whole page:** every visual item must reach the wireframe's master-detail look **while
preserving** the hard deactivation guard, the resource guards, the branch-scoped booking gate, and the structured
data model. Where the wireframe implies a control the backend doesn't have (soft-suspend, online-booking toggle,
default-branch, phone), it's a **flagged decision / separate gate**, never a faked control.

---

## 9. Parity progress (RESOLVED status per punch-list)

Updated as BRANCH.P1–… land. One commit per part.

| Punch-list item | Status | Commit |
|---|---|---|
| 4/5 · Soft-suspend (`accepts_online_bookings`) as a REAL state — chosen option (b): a distinct control alongside the hard deactivate guard | **RESOLVED (P1)** | `BRANCH.P1` |
| 7 · Phone field (`Branch.phone`) | **RESOLVED (P1)** | `BRANCH.P1` |
| 5 · Online-booking control wired to the REAL gate | **RESOLVED (P1)** — real, not a faked switch | `BRANCH.P1` |
| 1 · Master-detail restructure | pending (P2+) | — |
| 2 · Glass + eucardIn + pills + terracotta | pending (P2+) | — |
| 3 · Create modal wizard | pending | — |
| 6 · "main"/default-branch flag (`is_primary`, exactly-one-per-tenant invariant) | **RESOLVED (P2)** | `BRANCH.P2` |
| 8 · Practitioner resources read-only in the roster (type not editable via the facility select) | **RESOLVED (P3)** | `BRANCH.P3` |

**P3 note — practitioner-resource type read-only (correctness FIX, not a trim):** resolves punch-list item 8 (§6
nuance).
- **The model:** a `practitioner` resource is PERSON-BACKED (`type=practitioner` + a `staff_profile_id` →
  `StaffProfile`; the model invariant lets ONLY a practitioner link a staff profile). Facility resources
  (room/chair/vehicle) are free-standing. The admin type select offers facility types only, which **cannot
  represent a practitioner** — so editing a practitioner's type via it was the bug.
- **The FIX:** `ResourceController::update` now branches — for a **practitioner** it validates/updates the NAME
  only and **ignores any submitted `type`** (the practitioner stays a practitioner; a forged `type=room` has no
  effect); for a **facility** it keeps name+type editable within the facility-only `in:room,chair,vehicle` rule (so
  a facility can't become a practitioner either — 422). UI: practitioner rows render a read-only "Practitioner"
  label instead of the dropdown; facility rows keep the editable select.
- **A FIX, not a trim:** no capability removed — practitioner name/status (activate/deactivate, guarded) stay
  editable; facility-resource CRUD is unchanged. Only the invalid type-edit is stopped. i18n adds
  `resources.type.practitioner`. admin.manage-gated + tenant-scoped. **No existing behaviour test modified**;
  W8c ResourceCrud + P1/P2 + fence suites green. Locked by
  `tests/Feature/Scheduling/BranchPractitionerResourceTest.php` (6). Correctly-more-real (practitioner rows in the
  roster) un-regressed — kept, just shown with their true non-editable type.

**P2 note — `is_primary` default-branch flag + exactly-one-primary invariant + the "main" badge:** resolves
punch-list item 6 — a REAL, invariant-guarded state, NOT a cosmetic label.
- **Study:** no per-tenant default existed; the implicit default was "first branch" (`Branch::query()->firstOrFail()`
  used by some billing charge-attribution paths, and `StaffProfile.primary_branch_id` is a distinct PER-STAFF
  concept). `is_primary` formalizes the tenant default; the billing paths are **not** rewired (existing behaviour
  unchanged) — a documented follow-up could later point them at `is_primary`.
- **Migration** (additive, default false) + **backfill**: every existing tenant gets exactly one primary — the
  earliest-created ACTIVE branch (else earliest overall), aligning with the implicit "first branch" default.
- **THE INVARIANT — exactly one primary per tenant, ALWAYS.** Seeded by the `Branch` `creating` hook (the tenant's
  FIRST branch is primary — covers every path incl. the demo seeders' direct `Branch::create`). `setPrimary` is an
  **atomic** swap (clear current + set target in one transaction — never zero, never two). Deactivating the primary
  **reassigns** it to the earliest other active branch (atomic) before deactivating; the SOLE branch **keeps**
  primary even when deactivated (still exactly one, never zero). There is **no un-set** action — the flag is only
  ever moved. `ensurePrimary()` is the idempotent runtime mirror of the backfill (safety net + tested).
- **Interaction with deactivate — the P1 hard guard is UNCHANGED.** `BranchController::deactivate` runs the P1
  hard guard first (blocked while future appointments exist); only after it passes does the reassign happen in
  `BranchService::setActive`. Set-primary requires the target be ACTIVE (an inactive branch can't be the default).
- **UI:** a "Primary" badge on the primary branch + a "Set as primary" button on active non-primary branches
  (minimal; full visual is P4). i18n unique `branchesAdmin.primary.*` + a `primarySet` flash. admin.manage-gated,
  tenant-scoped, audited (`branch.primary_set`). **No existing behaviour test modified**; Branches/booking/fence
  suites green. Locked by `tests/Feature/Platform/BranchPrimaryTest.php` (8 tests). Correctly-more-real un-regressed.

**P1 note — per-branch `accepts_online_bookings` (soft-suspend) + `phone`; slot-finder-gated; hard deactivate
guard unchanged (option (b) from §5.1):** the suspend-vs-deactivate reconciliation resolved by adding the SOFT
control as a **distinct real state**, NOT by weakening the hard guard.
- **Migration** (additive/defaulted): `branches.accepts_online_bookings` (bool, default **true** → existing
  branches unchanged) + `branches.phone` (nullable). `Branch::scopeOnlineBookable()` = `active AND
  accepts_online_bookings`.
- **REAL gate (not cosmetic):** the ONLINE write path `BookingService::createBooking` refuses a NEW booking when
  `$source === SOURCE_ONLINE` and the branch isn't online-bookable (`BookingUnavailableException::onlineBookingsSuspended`);
  the public + portal branch lists use `scopeOnlineBookable`; the public + portal **slots endpoints return `[]`** for
  a suspended branch. **Staff `book()` (SOURCE_STAFF) is unaffected** — the shared `AvailableSlotFinder`/day-board is
  untouched, so staff still operate a soft-suspended branch and its existing appointments stand.
- **THE GUARD DISTINCTION (verified):** soft-suspend (`accepts_online_bookings=false`, `active=true`) is a **separate
  action from** hard deactivate (`active=false`). Soft-suspend is **always allowed** (never strands care) and does
  **not** deactivate; the **hard deactivate guard is UNCHANGED** — still blocked while future appointments exist (a
  regression test proves it). Admin toggle route `POST /admin/branches/{branch}/online-bookings`
  (`BranchService::setOnlineBookings`), validated + admin.manage-gated + tenant-scoped + audited distinctly
  (`branch.online_bookings_enabled` / `_suspended`). `phone` added to the branch update validation.
- **UI:** a minimal control on the existing (functional-plain) page — an online-booking status pill + a
  Turn-on/Suspend button + a phone input; **full master-detail visual parity is P2+**. i18n unique
  `branchesAdmin.onlineBookings.*` + `fields.phone` + two `flash` keys. **No existing behaviour test modified**;
  W8b/W8c + booking/slot-finder suites stay green. Locked by `tests/Feature/Scheduling/BranchOnlineBookingTest.php`
  (6 tests). **Correctly-more-real items (§6) un-regressed.**
