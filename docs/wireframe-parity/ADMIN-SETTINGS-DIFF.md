# Admin Settings — Wireframe-Parity Diff (audit only)

**Scope:** diff the LIVE Admin Settings surface against the decoded wireframe
`resources/prototype/admin-settings.wireframe.html` on every axis (layout, sections, fields,
controls, styling, states, interactions, copy, backend/fence semantics).
**This is an audit. No app code was changed.** The fix comes next, from this report.

- **Date:** 2026-08-06 · **Branch:** `main` · **HEAD at audit:** `25685e3` · **CI:** green (check-run `success`).
- **Environment:** `migrate:fresh --seed` + `DemoClinicSeeder`; served `127.0.0.1:8000`; driven in Playwright as
  `andrea.lindenhof@praxis-lindenhof.test` / `demo-password` (Org Admin, 2FA `JBSWY3DPEHPK3PXP`), tenant **Praxis Lindenhof**.
- **Wireframe brief:** "Brief 16 · sectioned cards · label/value rows · save per section" — Eucalyptus Glow design pack.

---

## 1. The live page found & how it maps to the 6 wireframe cards

The wireframe is **one screen, 6 cards, a sticky left sub-nav**. The live app is **multi-page**, reached from a
top **Admin** dropdown (grouped in POLISH.2), not a single Settings surface:

| Live route | Controller | Vue page | Covers |
|---|---|---|---|
| `GET /settings` | `Modules\Platform\...\SettingsController@index` | `resources/js/pages/Admin/Settings.vue` | Practice profile, Identity & plan (read-only), Billing identity & currency, Branches summary, cross-links, "Not yet configurable" gaps |
| `POST /settings` | `SettingsController@update` | — | Save **billing** (currency, seller name, VAT) via `SettingsService` |
| `POST /settings/profile` | `SettingsController@updateProfile` | — | Save **profile** → tenant columns + locale/timezone via `SettingsService` |
| `GET /admin/roles` | `UserRoleController@index` | `resources/js/pages/Admin/Roles.vue` | Team (assign role) + read-only permission catalog |
| `POST /admin/roles/assign` | `UserRoleController@assign` | — | Assign a role template (audited path; last-admin guard) |
| `GET /admin/branches` | `BranchController@index` | — | Branch CRUD (own page) |
| `GET /admin/kiosks` | `KioskDeviceController@index` | — | Check-in kiosk provisioning (own page) |

**Mapping the 6 wireframe cards to live reality:**

| # | Wireframe card | Live home | Status |
|---|---|---|---|
| 1 | Practice profile | `/settings` → Practice profile + Identity cards | **Present** (and richer) |
| 2 | Scheduling | — (no card) | **Missing UI** — backends exist |
| 3 | Agents & automation | — (no card) | **Missing UI** — backend exists (`AutonomyPolicy`) |
| 4 | Team & roles + RBAC matrix | `/admin/roles` (separate page) | **Present, different shape** (more real) |
| 5 | Notifications | — (no card) | **Missing** — engine exists, no prefs |
| 6 | Security | — (no card) | **Missing UI** — 2FA gate is real & enforced |

Live subtitle: *"Configure your practice. The server enforces every value — this page only reflects it."* —
semantically identical to the wireframe's *"the server enforces every value the UI only reflects."* The
governance thesis is already shared; the gap is surface coverage + visual language.

---

## 2. Section-by-section diff table

Classification key: **(a)** visual/layout (frontend-only) · **(b)** feature/backend gap · **(c)** governance/fence
(preserve server enforcement; match the locked visual, never weaken the gate) · **(d)** correctly-more-real (keep).
Severity = distance from parity, weighted by governance.

### Top nav
| Axis | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| Shape | Glass **pill** nav | Full horizontal app bar | Different chrome | (a) | Low |
| Items | Dashboard · Approvals · Knowledge base · Settings | Dashboard · Patients · Orders · Scheduling · Nursing · Inbox · Telehealth · Dental · Billing · Reporting · **Admin ▾** | Live is the real app IA; Settings lives under Admin ▾ | (d) | Low |
| Tenant chip | "Praxis Lindenhof · admin" pill | none (avatar "AL" + Sign out only) | Missing tenant/context chip | (a) | Low |
| Right cluster | search · avatar | search · notifications bell · avatar · sign-out | More real | (d) | — |

### Left sub-nav
| Axis | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| Sticky 220px sub-nav (Practice/Scheduling/Online booking/Agents/Notifications/Team & roles/Security) | present, sticky | **absent** — vertical card stack, sections split across pages | No in-page section nav | (a) | Medium |

### Card 1 — Practice profile
| Axis | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| Card present | yes | yes (`/settings`) | — | — | — |
| Practice name | input `Praxis Lindenhof` | input `Praxis Lindenhof` | match | — | — |
| Booking slug | shown as field `care.os/book/lindenhof` | **read-only** `praxis-lindenhof` (Identity card) | Live correctly read-only (slug is the public booking key) | (d) | Low |
| Contact phone | input `+41 44 350 60 60` | input (empty on seed) | match (control) | — | — |
| Default language | select `German` | select `Interface language` = DE | match | — | — |
| Extra live fields | — | contact email, address 1/2, city, postal, country, **timezone** | Live is richer | (d) | — |
| Save | `[Save practice]` (pill, toast) | `[Save profile]` (rounded, inline flash) | per-section save ✓; visual differs (see §3) | (a) | Low |
| Caption | "Shown to patients on the portal and public booking." | "The details shown on invoices, the portal and public booking." | equivalent | — | — |

### Card 2 — Scheduling
| Axis | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| Card present | yes | **no card** | Entire section missing from Settings | (b) | **High** |
| Portal cancellation window (`24 hours`) | field | **backend exists**, no UI: `SettingsService` key `scheduling.portal.cancel_min_hours` (default 24) — read by `PortalAppointmentController::cancelMinHours()` | needs a card + write path | (b) | High |
| Default appointment buffer (`10 min`) | field | **partial**: buffers are **per-Service** (`Service.buffer_before/after_minutes`), no global default setting | needs a global-default column/setting + UI, or re-scope to per-service | (b) | Medium |
| Nurse travel speed (`28 km/h`) | field | **backend exists**, no UI: `SettingsService` key `nursing.dispatch.average_speed_kmh` (default 40) — used by `AssignmentValidator` + dispatch proposal engine | needs a card + write path | (b) | Medium |
| Save `[Save scheduling]` | per-section | — | add on the new card | (b) | — |

### Card 3 — Agents & automation
| Axis | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| Card present | yes | **no card** | Section missing from Settings | (b)+(c) | **High** |
| 3 agent rows (Front-desk / Dispatch / Chart summary) with Off/Suggest toggle | toggles | **backend exists**: `AutonomyPolicy` (`OFF/SUGGEST/APPROVE/AUTO`), per-tool `ai.autonomy.<key>` setting (default `SUGGEST`), `AutonomyPolicy::set()`; `AgentRuntime` enforces at call time | needs an autonomy-config card wired to `AutonomyPolicy` | (b)+(c) | High |
| Banner: "Every agent is capped at **suggest** — the ceiling can be lowered but never raised past human approval." | copy + real cap | **REAL cap**: `AutonomyPolicy::cap()` clamps to per-tool `autonomyCeiling`, and clinical/financial tools hard-cap at `APPROVE` | the fence is already server-enforced; the card must reflect it read-only-above-ceiling | (c) | High |

### Card 4 — Team & roles + RBAC matrix
| Axis | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| Card present | yes (on-screen) | yes, **separate page** `/admin/roles` | split off Settings | (a) | Medium |
| Member list + per-row role select + status pill + ⋮ | yes | **Team table**: Member · Current role · Assign (select + button) — 9 real members | control differs (select+button vs inline select+⋮); more real data | (a)/(d) | Low |
| `[+ Invite member]` + "Pending" invite row | yes | **absent** — no staff-invite flow (accept-invite is portal patients only) | staff invite/onboarding not built | (b) | Medium |
| RBAC matrix: 7 perms × 4 roles, clickable ✓/– cells | editable grid + "pending role-config sync" toast | **read-only permission catalog** (chips per role) — **26 role templates, 40-perm Org Admin**, real tested descriptions | live is reflect-only chips, not an editable grid; far more real; matches the fence (see §5) | (c)+(d) | Low |
| Caption: "…the server enforces every gate; changing a role here only reflects it." | copy | Live: "Roles are fixed templates — the server enforces exactly what each grants." | equivalent governance copy | — | — |

### Card 5 — Notifications
| Axis | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| Card present | yes | **no card** | Section missing | (b) | Medium |
| EMAIL/SMS toggle pairs (Appointment reminders, Booking confirmations, New patient message) | toggles | **no per-event prefs**: `NotificationService` has channels (email ships; sms/… future) + templates, but no on/off preference storage | needs a preferences table/settings + UI; SMS channel not implemented | (b) | Medium |
| Clinician-attention flag — **locked on**, SMS `–` | locked toggle | **no notification-settings surface**; the *semantic* (agent must hand a clinical question to a human) is enforced by the autonomy cap + `ApprovalQueue`, not by a notification toggle | if built, render locked-on; the safety hand-off lives in the AI fence, not comms | (c) | Medium |
| Caption: "…can't be switched off — it's the agent's safety hand-off to a human." | copy | — | tie the copy to the real AI-fence behavior when built | (c) | — |

### Card 6 — Security
| Axis | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| Card present | yes | **no card** | Section missing from Settings | (b)+(c) | Medium |
| Two-factor = **Mandatory · locked** (platform-enforced) | locked row | **REAL & enforced**: `EnsureTwoFactorEnabled` middleware forces TOTP enrollment for every authenticated user | if surfaced, render locked; the gate already exists (do not add a disable path) | (c) | Medium |
| Staff session timeout (`30 min`) | field | no dedicated setting found (session lifetime is framework config, not a tenant setting) | needs a setting + UI if it should be tenant-configurable | (b) | Low |
| Nurse PWA idle wipe (`15 min`) | field | not a tenant setting (client-side PWA behavior) | needs a setting + UI if tenant-configurable | (b) | Low |
| Caption: "Some controls are enforced platform-wide and can't be lowered." | copy | live already states this globally in the page subtitle | governance already honored | (c)/(d) | — |

---

## 3. Exact visual deltas to reach parity

Computed from the live page (Playwright `getComputedStyle`) vs the wireframe styles.

| Token | Wireframe | Live (measured) | Delta / action |
|---|---|---|---|
| Canvas bg | cream `#F4EFE6 / #EFF2E9 / #E7EFE1` + layered radial glows | `rgb(247,250,245)` (#F7FAF5), `background-image: none` | **Cooler off-white; NO radial glows.** Add the eucalyptus radial-glow canvas for parity. |
| Card fill | translucent white gradient + **`backdrop-filter: blur(24px)`** | solid white, **`backdrop-filter: none`** | **No glassmorphism blur.** Add blur + translucent gradient for the glass look. |
| Card radius | 20–26px | **20px** | Match (bump hero card to ~26px if matching exactly). |
| Card border | translucent white `rgba(255,255,255,0.8)` | `0.8px solid rgba(255,255,255,0.8)` | **Match.** |
| Card shadow | soft `rgba(53,70,47,·)` | `0 1px 2px rgba(53,70,47,.05), 0 16px 44px rgba(53,70,47,.11)` | **Match** (same token). |
| Primary green | `#5C7D55` / deep `#35462F` / hover `#475F42` / accent `#648659` | euca-700/800 family (green solid button, links `text-euca-700`) | **Palette matches.** |
| Buttons | **pill** (`border-radius:999px`), gradient `#648659→#55754F` | radius **16px**, solid green | **Radius + gradient delta** — pill + gradient for parity. |
| Inputs | rounded, light fill | bg `#FCFAF5`, border `#DCE8D7`, radius 16px | Close; matches spirit. |
| Type | Inter | Inter (`Inter, ui-sans-serif,…`) | **Match.** Ink `#2A332A` matches. |
| Title | large screen title "Settings" + eyebrow, sticky | H1 22px/600 + "Administration" eyebrow | Smaller title; no sticky section rail. |
| Entrance anim | `eucardIn` (fade/translate/scale, cubic-bezier) | none | Add if matching motion. |
| Reduced motion | `@media (prefers-reduced-motion)` guard | (AppLayout dependent) | Preserve guard if adding animation. |
| Focus-visible | `2px solid #5C7D55, offset 2px` | AppLayout default ring | Align focus ring to `#5C7D55` for parity. |

---

## 4. Feature / backend gaps (wireframe elements with no/partial live persistence)

Ordered by how much backend the fix needs.

1. **Scheduling card (b)** — three fields:
   - Portal cancellation window → **storage exists** (`scheduling.portal.cancel_min_hours`, default 24). Fix = card + validated write path only.
   - Nurse travel speed → **storage exists** (`nursing.dispatch.average_speed_kmh`, default 40; wireframe shows 28). Fix = card + write path.
   - Default appointment buffer → **partial**: only per-`Service` buffers exist; no global default. Fix = new setting/column + UI, or re-scope the field to per-service.
2. **Agents & automation card (b)+(c)** — `AutonomyPolicy` + `ai.autonomy.<tool>` settings + `AutonomyPolicy::set()` already exist and are enforced in `AgentRuntime`. Fix = a card that lists governed tools and toggles Off/Suggest, **reading and re-persisting through `AutonomyPolicy`** so the ceiling cap is honored. No new domain logic — presentation over an existing gate.
3. **Notifications card (b)** — notification **engine** exists (`NotificationService`, email channel, templates) but there is **no per-event on/off preference store** and **no SMS channel**. Fix = a preferences table (event × channel) + UI; SMS is a separate build.
4. **Staff invite / onboarding (b)** — `/admin/roles` assigns roles to **existing** users only; there is no staff-invite flow (`accept-invite` is portal patients). Wireframe's `[+ Invite member]` + "Pending" row need an invite model/endpoint/email.
5. **Security card (b)** — session timeout and Nurse-PWA idle-wipe are not tenant settings today (framework/client config). Fix = settings + UI only if they should be tenant-configurable; 2FA itself needs **no** new backend (already enforced).

---

## 5. Governance / fence items (confirm server enforcement — match the locked visual, keep the gate)

All four wireframe fences are **already server-enforced**. When these cards are built, they must render the
**locked/capped visual** and must **not** introduce a control that weakens the gate.

| Fence | Wireframe visual | Live server enforcement (verified) | Rule for the fix |
|---|---|---|---|
| **Agent suggest-cap** | Off/Suggest toggle; "ceiling lowerable, never raised past human approval" | `AutonomyPolicy::cap()` clamps every level to the per-tool `autonomyCeiling`; clinical/financial tools hard-cap at `APPROVE`; `AgentRuntime` enforces at call time (`Modules/AiCore/src/Services/AutonomyPolicy.php`) | Card may **lower** autonomy; must not offer a level above the tool's ceiling. Persist through `AutonomyPolicy::set()`, never a raw setting write. |
| **2FA mandatory · locked** | locked row, "enforced by the platform" | `EnsureTwoFactorEnabled` middleware forces TOTP enrollment for every authenticated user (`Modules/Platform/src/Http/Middleware/EnsureTwoFactorEnabled.php`) | Render read-only/locked. **Do not** add a per-tenant disable toggle. |
| **Clinician-attention flag locked-on** | locked toggle, "the agent's safety hand-off to a human" | The hand-off is the **autonomy cap + `ApprovalQueue`** (agent proposes, human approves), not a comms toggle | If shown, render locked-on and tie the copy to the AI fence; do not gate the hand-off behind a switchable notification pref. |
| **RBAC reflect-only** | clickable ✓/– grid + "server enforces every gate; changing a role here only reflects it" | Roles are fixed **tested templates**; permissions enforced server-side via Gates; assignment goes through the audited `UserRoleController@assign`; **last-admin guard** blocks removing the final Org Admin | Keep reflect-only. An editable ✓/– grid would imply per-cell permission editing the server does not honor — **do not** build a matrix that suggests writable permissions; keep the read-only catalog (or a read-only grid). |

---

## 6. Correctly-more-real items (keep — do NOT "fix" toward the wireframe)

- **RBAC depth:** 26 real role templates with a 40-permission Org Admin and real, tested permission descriptions
  (vs the wireframe's 4 roles × 7 perms). The read-only **catalog of what each role grants** is more honest than
  an editable grid.
- **Profile depth:** contact email, full address (line 1/2, city, postal, country), and **timezone** — beyond the
  wireframe's 4 fields.
- **Billing identity card:** settlement currency + invoice issuer name + VAT/tax ID (real invoice identity) — not
  in the wireframe at all.
- **Read-only Identity & plan** (slug, region, status, plan = "EU Pro") — correct immutability of the booking key
  and region; slug is **read-only** live where the wireframe rendered it as an editable field.
- **Honest "Not yet configurable" card** — explicitly names roadmap gaps (rooms/chairs, feature toggles, plan &
  subscription) instead of faking controls.
- **Branches + Kiosks** as real managed surfaces (own pages) with real seeded data (Zürich Oberstrass / ZH-OBS).
- **Real tenant data** (Praxis Lindenhof, EU Pro) and **last-admin guard** — a governance behavior the wireframe
  only implies.

---

## 7. Prioritized parity punch-list (what the fix gate must do)

**Critical parity gaps first.** F = frontend, B = backend.

1. **[High · b/c] Agents & automation card** — new Settings card listing governed tools with Off/Suggest,
   read/write **through `AutonomyPolicy`** (honor the per-tool ceiling + clinical/financial APPROVE hard-cap).
   Backend already exists; this is presentation over a real gate. `F` + thin `B` (controller wiring).
2. **[High · b] Scheduling card** — surface `scheduling.portal.cancel_min_hours` (24) and
   `nursing.dispatch.average_speed_kmh` (40) with validated write paths; decide global-vs-per-service for the
   appointment buffer (per-service exists today). `F` + `B` (validation/persist; new setting only for buffer).
3. **[Medium · a] Left sub-nav + card grouping** — add the sticky section rail (Practice/Scheduling/Agents/
   Notifications/Team & roles/Security) and bring the split pages (Roles, Branches) into a coherent Settings IA,
   or anchor-link them. `F`.
4. **[Medium · a] Glass visual language** — radial-glow canvas, `backdrop-filter: blur(24px)` translucent cards,
   pill + gradient buttons, `eucardIn` entrance (with reduced-motion guard), `#5C7D55` focus ring. `F` only.
5. **[Medium · b] Security card** — render 2FA as **Mandatory · locked** (read-only over the existing middleware
   gate); add session-timeout / PWA-idle-wipe **only if** they should be tenant-configurable (needs settings). `F` + optional `B`.
6. **[Medium · b] Notifications card** — needs an event×channel preference store (+ SMS channel) before the
   toggles are real; the Clinician-attention flag must render locked-on and map to the AI hand-off, not a comms
   pref. `B`-heavy.
7. **[Medium · b] Staff invite flow** — `[+ Invite member]` + Pending row need an invite model/endpoint/email;
   today only role-assignment of existing users exists. `B` + `F`.
8. **[Low · a] Top-nav polish** — optional tenant-context chip; Settings already lives under Admin ▾ (keep the
   real IA).
9. **[Keep · d] Do not regress** the deeper RBAC catalog, richer profile/billing fields, read-only identity, the
   honest gaps card, or the last-admin guard.

**Fence guard for the whole gate:** every card above must match the wireframe's *locked/capped visual* while
**preserving server enforcement** — the suggest-cap, mandatory 2FA, reflect-only RBAC, and the human hand-off are
already real; the fix reflects them, it must never add a control that weakens them.

---

## 8. Parity progress (RESOLVED status per punch-list)

Updated as the SETTINGS.P1–P6 fix parts land. One commit per part (AGENTS.md "one gate = one commit").

| Punch-list item | Status | Commit |
|---|---|---|
| 4 · Glass visual language (canvas glow, blur cards, pill+gradient buttons, entrance anim, focus ring) | **RESOLVED (P1)** | `SETTINGS.P1` |
| 1 · Agents & automation card over `AutonomyPolicy` | **RESOLVED (P2)** | `SETTINGS.P2` |
| 2 · Scheduling card | **RESOLVED (P3)** | `SETTINGS.P3` |
| 5 · Security card (2FA read-only-locked) | pending (P4) | — |
| 6 · Notifications card + email prefs store (SMS deferred) | pending (P5) | — |
| 7 · Staff invite flow | pending (P6) | — |
| 3 · Left sub-nav / IA | pending (P6) | — |

**P1 note — audit correction:** the "no canvas glow / no glass blur" visual deltas in §3 were a *headless
measurement artifact*. Repo reality: `AppLayout.vue` already wraps the surface in `.euca-wash` (the layered
radial-glow cream/sage canvas) and `Card.vue` already uses `.glass-card` (`backdrop-filter: blur(24px)`,
translucent gradient, hairline, soft `rgba(53,70,47,·)` shadow, `radius-2xl`), and `Button.vue` primary is already
`.btn-glow` (euca gradient). The genuine P1 gaps were therefore narrower — implemented additively, tokens/i18n
only, no logic change:
- **`eucardIn` entrance** — new `@keyframes eucardIn` + `.euca-card-in` utility in `app.css` (with a
  `prefers-reduced-motion: reduce` guard), opted-in per card via a new `Card` `animate` prop (default off → no
  app-wide regression); applied + lightly staggered on the Settings + Roles cards.
- **Pill + gradient Save buttons** — new `Button` `pill` prop (default off → `rounded-xl` unchanged elsewhere);
  the primary Save actions render `rounded-full` over the existing `.btn-glow` gradient.
- **Focus ring** — an *unlayered* `.settings-surface … :focus-visible { outline: 2px solid var(--color-euca-700) }`
  rule (unlayered so it wins over the browser's UA `:focus-visible`, which beats Tailwind's layered base).
  Browser-verified euca-700 `solid` on inputs/selects (at 80%-zoom the 2px renders as 1.6px).

Correctly-more-real items (§6) confirmed **un-regressed** by P1 (purely presentational; the deeper RBAC catalog,
richer profile/billing, read-only identity, honesty card, last-admin guard, Branches/Kiosks untouched).

**P2 note — Agents & automation card over `AutonomyPolicy` (the fence gate):** a new `/admin/agents` page
(app-layer `AgentAutonomyController`, gated `ai.manage`, cross-linked from `/settings`) lists the **real
registered governed tools** (`ToolRegistry::all()`, the reserved `demo.*` echo excluded — 10 tools) with an
Off/Suggest/Approve/Auto control. It is **presentation over the existing gate**, not new policy:
- **Reads through `AutonomyPolicy`** — current level via `levelFor()`, the locked limit via a new
  `effectiveCeiling()` accessor (= the same `cap(AUTO)` the runtime applies). Levels above the ceiling render
  **disabled + lock-icon** (clinical → locked above Suggest; clinical/financial → locked at Approve; Auto locked
  for all but the operational approve-ceiling tools). The banner carries the thesis: *"Every agent is capped at
  suggest — the ceiling can be lowered but never raised past human approval."*
- **Writes only through `AutonomyPolicy::set()`** (never a raw `ai.autonomy.*` write), which **clamps** any level
  above the ceiling. THE FENCE test proves a forged `auto` on a clinical tool persists as `suggest` and on a
  financial tool as `approve` — the card is structurally incapable of raising autonomy past the cap. `AutonomyPolicy`
  is **un-weakened** (the two new methods are read accessors reusing `cap()`).
- Per-section Save + "Agent autonomy saved." flash; `ai.manage`-gated (reception 403); tenant-scoped; the change
  audited as `ai.autonomy_changed`. Uses the P1 visual language (glass card, pill Save, eucardIn). 8 new tests;
  no existing behavior test modified. Correctly-more-real items un-regressed.

**P3 note — Scheduling card over existing settings (with an honest buffer):** a new `/admin/scheduling` page
(app-layer `SchedulingSettingsController`, gated `admin.manage`, cross-linked from `/settings`) surfaces the
scheduling settings that **already exist and are already read by the schedulers**:
- **Portal cancellation window** → `scheduling.portal.cancel_min_hours` (default 24), read by
  `PortalAppointmentController::cancelMinHours()`. **Nurse travel speed** → `nursing.dispatch.average_speed_kmh`
  (default 40), read by `Nursing\AssignmentValidator` + the dispatch proposal engine. The card writes ONLY these
  exact keys through `SettingsService::set()`, so a saved value is **honored, not ignored** (a test asserts the
  reader's own expression returns the new value). Validated (cancel 0–168h, speed 1–200 km/h), audited
  (`settings.scheduling_changed`), per-section Save + "Scheduling settings saved." flash.
- **Default appointment buffer — decision (a), the honest option:** there is **no global-default buffer setting**
  the scheduler reads (buffers exist only per `Service.buffer_before/after_minutes`). So the buffer is rendered
  **read-only as a per-service pointer** ("Set per service"), **not** an editable global that would persist a value
  nothing consumes. A test proves no global buffer setting is written even if the request includes one.
- App-layer because the write is audited and `Modules\Platform` may not depend on `Modules\Audit` (arch rule).
  `admin.manage`-gated (reception 403); tenant-scoped. P1 visual language. 7 new tests; no existing behavior test
  modified; correctly-more-real items un-regressed.
