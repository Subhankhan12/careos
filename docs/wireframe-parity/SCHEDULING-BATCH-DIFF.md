# Scheduling — wireframe-parity batch diff (8 screens, AUDIT ONLY)

**Audited 2026-08-29 at `ab9e62c` (COMMS.P2), CI green.** Sixth and final buildable domain batch, after
Dental core, Patients & Clinical core, Portal (11/11), Governance & AI (10/10) and Comms (core
complete). **No app code was written for this audit.** `resources/prototype/` stays gitignored.

**Environment, stated rather than assumed:** Redis is **not installed** on this machine and is not
reachable. Nothing in this audit needed it.

**The headline.** Scheduling is the strongest backend in the product — a locked, overlap-guarded
booking path, a real transition machine, real availability templates with dated exceptions, a real
waitlist with offers and expiry. The wireframes are correspondingly close. **The gap is not
capability, it is judgment:** three screens add a ranking, a triage or an auto-send that the fences
forbid, and one of those (waitlist urgency) is the same clinical-priority pattern the Dental batch
already refused. Two screens also invent a *price* on the service catalog, which lives in Billing.

---

## 0 — The fixture, verified by query (D-189)

The demo seeder gives asymmetric appointments and loads; branches, offers and priorities were
completed by **driving the real services** in a throwaway scratchpad script (never committed, no app
code), so every state below was produced by the code that owns it.

```
appointments   booked 5 · arrived 1 · cancelled 1 · completed 1 · confirmed 1
               in_progress 1 · no_show 1 · rescheduled 1      ← all 8 statuses, asymmetric
resource load  Brunner 6 · Raum1 5 · Keller 4 · Raum2 4 · five others 0
branches       Zürich Oberstrass  active=1 online=1   ← bookable
               Zürich Altstetten  active=1 online=0   ← SOFT-SUSPENDED (BRANCH.P1)
services       3 bookable_online · 2 internal-only (both active)
waitlist       7 entries, priorities 0 / 2 / 5 / 10   ← asymmetric, so a constant cannot impersonate
offers         offered 1 · declined 1 · expired 1
audit          waitlist_offer.offered 3 · .declined 1 · .expired 1
series         0 (the model exists; nothing seeds one)
```

---

## 1 — The eight screens

| # | Screen | Purpose (one line) | Audience | Live route · Vue | Built state |
|---|---|---|---|---|---|
| 1 | **Appointment Detail** | one appointment, its lifecycle and its history | **STAFF** | `scheduling/appointments/{a}` · `Scheduling/AppointmentDetail.vue` | ✅ **PARITY COMPLETE** (APPT.P1–P3, `ca90273`) |
| 2 | **Reception Day-Board** | the day's grid by resource; the densest staff screen | **STAFF** (`appointment.manage`) | `scheduling/day-board` · `Scheduling/DayBoard.vue` | 🔵 **LIVE, NEVER COMPARED** |
| 3 | **Public Booking** | unauthenticated 5-step self-booking on a tenant slug | **PATIENT-FACING** (guest) | `book/{tenant}` · `Public/Book.vue` | 🔵 **LIVE, NEVER COMPARED** |
| 4 | **Waitlist Management** | who is waiting, and who gets a freed slot | **STAFF** | panel inside `Scheduling/DayBoard.vue` only | 📋 **AUDITED, NOT BUILT** |
| 5 | **Provider Availability** | the weekly template every free slot is drawn from | **STAFF** (admin) | — | ⚪ **NO LIVE PAGE** |
| 6 | **Service Catalog** | the services that drive board, quick-book and public booking | **STAFF** (admin) | — | ⚪ **NO LIVE PAGE** |
| 7 | **Service Create** | add a service, safe defaults | **STAFF** (admin) | — | ⚪ **NO LIVE PAGE** |
| 8 | **No-Show Follow-Up** | a missed appointment, re-engaged | **STAFF** (clinical) | `no_show` status exists; no follow-up screen | ⚪ **NO LIVE PAGE** |

**Cross-check with the Dental batch:** Chair Scheduling is filed under Dental, not here, and remains
NO LIVE PAGE blocked on the same **B4** gap (see §3).

---

## 2 — The real scheduling machinery, as found

**The booking path is one path.** `BookingService::book()` (staff) and `bookOnline()` (public) both
funnel into `createBooking()`, which opens a transaction and, per resource, `lockResource()` (a
`SELECT … FOR UPDATE`) then `assertNoOverlap()` — a `for update` query over `appointment_resources`
joined to `appointments`, widened by the service's `buffer_before_minutes` / `buffer_after_minutes`.
It also runs `assertResourceTypesMatch()` and `assertWithinAvailability()`. **There is no second
write path for an appointment.**

**The transition machine is closed.** `AppointmentService::LEGAL_TRANSITIONS`:

| from | to |
|---|---|
| `booked` | confirmed · cancelled · no_show · rescheduled |
| `confirmed` | **arrived** · cancelled · no_show · rescheduled |
| `arrived` | in_progress · cancelled |
| `in_progress` | completed |
| `completed` · `cancelled` · `no_show` · `rescheduled` | **(terminal)** |

`legalTransitionsFrom()` (APPT.P2) is the server-authoritative read — **used by Appointment Detail
only**. Note **`booked → arrived` is NOT legal**: confirmation comes first.

**Availability is richer than expected.** `ResourceAvailability` carries `weekday` + `start_time` +
`end_time` (the weekly template) **and** `date` + `is_available` + `reason` (dated exceptions). Both
halves of the Provider Availability mock are genuinely backed.

**Slot generation:** `AvailableSlotFinder` walks the day in a **hardcoded 30-minute stride**
(`$cursor->addMinutes(30)`); duration is the service's `default_duration_minutes`; buffers are
per-**service**, not per-provider.

**Soft-suspend is online-only.** `createBooking()` refuses when
`$source === SOURCE_ONLINE && ! ($branch->active && $branch->accepts_online_bookings)`. Staff booking
into a soft-suspended branch is deliberately unaffected.

**Waitlist.** `WaitlistEntry` (waiting/offered/booked/expired/cancelled) with `service_id`,
`branch_id`, `desired_starts_at`/`desired_ends_at`, `flexible`, **`priority` (int, default 0)**.
`matchingForSlot()` filters on service + branch + (flexible OR window contains slot) and orders
**`priority` DESC, `created_at` ASC**. `WaitlistOfferService` offers with a TTL, and
`accept()` books **through `BookingService`**. Routes exist for candidates / offer / accept /
decline. **`WaitlistService::create()` has no route and no UI.**

**The agent.** `scheduler.fill_from_waitlist` — operational, permission `appointment.manage`,
**`autonomyCeiling: APPROVE`**. There is no auto-send tier.

### What does NOT exist (verified)

| The mocks assume | Reality |
|---|---|
| A **price** and **tariff code** on a service | `Service` has name, code, category, duration, buffers, `requires_resource_types`, `bookable_online`, `active`. **No price.** Price lives in Billing (`TariffItem`) |
| **Slot granularity** as a setting | hardcoded 30 minutes in the finder |
| **Min notice online** ("24 hours") | no such field anywhere |
| Per-**provider** buffer | buffers are per-**service** |
| Waitlist **preferred days / times / earliest date / "will accept"** | only `desired_starts_at`–`desired_ends_at` + a `flexible` boolean |
| Waitlist **consent channels** (SMS / phone) | one wired channel, `comms.email` (D-188 territory) |
| A **booking-confirmation** email | only `appointment.reminder` exists — a reminder *before* the visit, not a confirmation *on booking* |
| A **slot hold** that reserves the slot | the hold is "one open offer per **entry**"; `assertNoOverlap` never consults `waitlist_offers` |
| `Resource` **capability** | **B4 — open.** Resource has type/name/staff_profile_id/branch_id/active only |

---

## 3 — Per-screen diff

Severity: **High** = fence or safety · **Med** = real gap a user notices · **Low** = chrome.

| Screen | Deltas (mock → live) | Classify | Sev |
|---|---|---|---|
| **1 · Appointment Detail** | Parity complete; nothing outstanding. | — | — |
| **2 · Day-Board** | Spine is right: branch-scoped `appointment.manage`, resource lanes, blocks carrying `resource_ids`, quick-book through the server finder, series panel, waitlist-offer panel. **Chrome missing:** dark glance band, waiting-room strip, per-lane utilisation bars, status legend, keyboard hints, online-booking markers. **Three refusals:** the **ranked conflict resolver** ("best", suggested target), **"Keep both (override)"**, and **"on schedule"**. Plus **`booked → arrived` is offered** — the action controller accepts `arrive` for any status and the server refuses. | (a) chrome · (b) resolver/override = **MUST-NOT-BUILD** · (c) illegal edge = **backend/UI gap** | **High** |
| **3 · Public Booking** | Remarkably aligned — the mock *names* the real contract (active + `bookable_online` only, server-side finder, `BookingService::bookOnline`, `source=online`, `booked_by=null`, one transaction, server-side duplicate handling, emergency notice on every step, **no symptom or triage free-text**). Two deltas: **"A confirmation is on its way"** (no confirmation notification exists — D-179) and **"Add to calendar"** (no ICS generation). | (a) copy asserting an unsent email · (b) small backend gap | **Med** |
| **4 · Waitlist Management** | The pool, ranking and offer lifecycle are real. **Four refusals:** *"routine offers can go automatically at Level 1"* (**auto-send**, already refused in Dental; ceiling is APPROVE); **Priority: "Urgent · clinical"** + urgent filter + *"routes urgent or clinical cases to a person"* + *"reports pain"* on the card (**clinical judgment + clinical content on a scheduling screen**); **SMS/phone consent channels**; **"Slot held until 18:00 … no one loses the slot to silence"** (the slot is *not* reserved). Genuine gaps: **no create route/UI**, and no preference model (days/times/earliest). Internally inconsistent — the same screen also says *"Nothing is sent, and no chair is booked, without a person in the loop."* | (a) auto-send + urgency = **MUST-NOT-BUILD** · (b) create/preferences = **backend-gap** · (c) hold copy = over-claim | **High** |
| **5 · Provider Availability** | **Better backed than expected:** weekly template *and* dated time-off/exceptions with a reason are real; per-branch service availability is real (`ServiceBranch`); the conflict-warning state (*"Saving won't move or cancel them"*, *"The server never auto-cancels"*) is **exactly** the real posture. Missing backend: **slot granularity** (hardcoded 30), **min notice online**, per-provider buffer. The multi-provider week is fine as a read-only view, but its caption *"Worth holding a little urgent capacity there"* is **advice**, not a recorded fact. | (a) mostly backend-gap (small) · (b) three unbacked settings · (c) advisory caption = trim | **Med** |
| **6 · Service Catalog** | Table + editor over real fields (name, duration, category, active, `bookable_online`, per-branch). The public-exposure rule it states is exactly the real one. **Delta: price + tariff code do not exist on `Service`** — they belong to Billing's tariff catalog. Category filter pills are fine (category is real). | backend-gap **or** a deliberate decline of the price panel | **Med** |
| **7 · Service Create** | Same model, one modal. Safe defaults (**active on, bookable-online off**) match the real column defaults (`bookable_online => false`, `active => true`) — correctly-more-real. Same **price/tariff** problem. | backend-gap (same as #6) | **Med** |
| **8 · No-Show Follow-Up** | Its own subtitle is the finding: *"a missed appointment **triaged by clinical risk** — routine misses go to reception, **this one surfaced here**."* That is a **clinical risk triage of a scheduling event** — the electric fence's exact prohibition. The `no_show` status is real and a follow-up worklist over it is buildable; **the triage that decides which misses matter is not**. | **MUST-NOT-BUILD-AS-DRAWN** (D-188 shape) | **High** |

---

## 4 — THE FENCE VERIFICATION

### 4.1 No optimisation engine

| Finding | Live enforcement | Verdict |
|---|---|---|
| Day-Board **ranked conflict resolver** — three moves, one badged **"best"**, with "Uses the open 10:45 slot… No new conflict" | Nothing ranks moves today. `checkAvailability()` answers free/conflict for *one* proposed slot; it does not propose, score or rank alternatives | **MUST-NOT-BUILD-AS-DRAWN.** A free/conflict indicator for a slot a human picked is fine; a ranked recommendation is the optimiser the fence forbids |
| Waitlist **"routine offers can go automatically at Level 1"** | `scheduler.fill_from_waitlist` is ceilinged at **APPROVE**; `AgentResolver` clamps at call time. Refused once already in the Dental batch | **MUST-NOT-BUILD.** The ceiling is what makes it safe — not the absence of a toggle |
| Waitlist **"the agent offers the next-best match automatically"** after an expiry | `expireDue()` expires offers; **nothing re-offers** | **MUST-NOT-BUILD-AS-DRAWN** |
| **"Import from recent cancellations"** (bulk waitlist add) | no bulk path; `create()` has no route at all | backend-gap, and a bulk one should not be the first thing built |
| Waitlist **acceptance** books the chair | `accept()` → `BookingService::book()` — the locked path, no page-side seating | **correctly-more-real** |

### 4.2 No prediction

| Drawn | Verdict |
|---|---|
| **"9 of 24 seen · on schedule"** | The count is real; **"on schedule"** is a judgment about running late. **Trim to the count** |
| **"2 waiting · longest 12 min"**, waiting-room strip "12 min past start" | Real: `checked_in_at` is recorded, `starts_at` is recorded, the difference is arithmetic over two facts. **Keep** — but it is a *recorded elapsed time*, never a prediction |
| Per-lane **"3 today · 1.75 h"** utilisation | A plain ratio of recorded booked minutes to recorded available minutes. **Acceptable**, per this gate's own carve-out — provided it stays **unranked and untinted** (D-169). No "thin/busy" bands |
| **"1 cancellation freed 30 min"** | Derived from a real cancelled appointment's duration. **Keep** |
| Availability **"Wednesday & Friday afternoons are thinnest — worth holding a little urgent capacity"** | The *observation* is a count of recorded coverage; the *advice* is not. **Keep the figures, drop the recommendation** |
| No-show **risk triage** (screen 8) | Nothing records or computes it | **MUST-NOT-BUILD** |
| Waitlist **"Urgent · clinical" priority** | `priority` exists as an **undocumented integer**. Nothing states it is operational, and nothing stops it being used as clinical urgency | **MUST-NOT-BUILD as clinical.** See the decision note below |

> **A reconciliation worth recording.** `WaitlistEntry.priority` is a bare `int` with no documented
> semantics, and `matchingForSlot()` orders by it first. That is a fence hole waiting for a UI: the
> moment a screen labels it "Urgent · clinical", a clinical judgment enters a scheduling ranking.
> **If a waitlist gate is ever taken, `priority` must first be given a written, operational
> meaning** (e.g. "staff-set ordering within equal wait time") and the UI must never offer a clinical
> label for it.

### 4.3 The concurrency guard is absolute

| Path | Goes through `lockResource` → `assertNoOverlap`? | Verdict |
|---|---|---|
| Staff booking (`book`) · online booking (`bookOnline`) · quick-book · series · **waitlist accept** | **Yes** — all funnel through `createBooking()` | **correctly-more-real** |
| Reschedule | `AppointmentService::reschedule()` re-runs the booking path | **correctly-more-real** |
| Day-Board **drag-and-drop / inline move** | Not built today | **If built, it must post and await the server.** An optimistic render before confirmation is a double-book the user has already been shown as done |
| **"Keep both (override)"** | `assertNoOverlap` throws `BookingConflictException`; **there is no override parameter** | **MUST-NOT-BUILD.** "Availability re-checked server-side" in the mock's own caption contradicts the button beside it |
| Waitlist **"slot held"** | The hold is one open offer **per entry**; `assertNoOverlap` never reads `waitlist_offers`, so reception or a public booker can take the slot while an offer is outstanding — acceptance then fails | **Over-claim.** Either say "we'll hold your place in the queue, not the slot", or build a real reservation. Do not print *"no one loses the slot to silence"* |

### 4.4 Legal transitions only

| Finding | Verdict |
|---|---|
| Appointment Detail derives its actions from `legalTransitionsFrom()` | **correctly-more-real** (APPT.P2) |
| ~~The Day-Board does not.~~ ✅ **FIXED in SCHED.P1** — the board now derives its actions from the same machine. **CORRECTION to this audit's original wording:** it said "the `booked → arrived` case is live: Arrive on a `booked` appointment is offered and refused". **That was wrong.** `DayBoardActionController` COMPOSES confirm → arrive for a booked appointment (D-156), so that particular action always succeeded. The real refusals were the others the board offered unconditionally — `start` or `complete` on a `booked` appointment, `arrive` on an already-arrived one, and **any** action on a completed, cancelled or no-show appointment. The finding stands; the example was mis-chosen |
| Any status outside the eight | None drawn | fine |

### 4.5 Soft-suspend + online gates

| Finding | Verdict |
|---|---|
| Online booking blocked when `! ($branch->active && $branch->accepts_online_bookings)` | **correctly-more-real**, and asserted in the fixture (a soft-suspended branch exists) |
| Staff booking **unaffected** by soft-suspend | **correctly-more-real.** No screen may imply otherwise |
| Service Catalog: *"Patients see a service only when it's active and bookable online"* | Exactly right — and `active` gates internal use too | **correctly-more-real** |
| Public Booking exposes only active + `bookable_online` services | **correctly-more-real** |
| Any screen implying online booking continues while suspended | none | fine |

### 4.6 Waitlist ceiling + the create blocker

| Finding | Verdict |
|---|---|
| Tool ceiling **APPROVE**, no auto-send tier | **correctly-more-real**; the mock's "Level 1 automatic" is refused |
| Acceptance → `WaitlistOfferService::accept()` → `BookingService` | **correctly-more-real** |
| Ranking = service + branch + window/flexible + `priority` + wait time | **operational today** — but see the `priority` note in §4.2 |
| Declining keeps the patient's place, no penalty | Real: decline returns the entry to `waiting`; the fixture shows all entries still `waiting` after a decline and an expiry | **correctly-more-real** |
| **`WaitlistService::create()` has NO route and NO UI** | **The blocker.** Screens 2 and 4 both need "Add to waitlist". Nothing can enter the waitlist today except a seeder — so the whole feature is unreachable in production | **backend-gap, and the first thing any waitlist gate must fix** |
| Audit action names | Real: `waitlist_offer.offered` / `.declined` / `.expired`. The mock's `scheduling.waitlist_booked` and `waitlist.offer_declined` are **invented names** — cosmetic, but do not print them | backend-gap (cosmetic) |

---

## 5 — Shared components and shared backend gaps

### 5.1 Components — reuse before building

| Need | **Reuse** | Notes |
|---|---|---|
| Plain counts (waiting, offers out, seen-of-total) | **`StatCard`** (D-166) | no computed value, not a filter |
| "What this page does not show" | **the GOV.P1/P3 card**, as used in COMMS.P1/P2 | screens 2, 4, 5 all need one |
| Status pills / lifecycle chrome | **`AppointmentDetail.vue`** pieces (APPT.P1–P3) | already carry the real statuses |
| Patient identity row | **COMMS.P1's context-pane pattern** — permission-scoped per element | reuse the discipline, not necessarily the component |
| Slot pills from the server finder | **already in `DayBoard.vue`** (quick-book) | keep |
| Weekly template grid (screen 5) | **genuinely new** | but it maps 1:1 onto `ResourceAvailability` |
| Service editor form (6, 7) | **genuinely new**, small | plain CRUD over real columns |

### 5.2 Backend gaps — one fix unlocking several

| Gap | Unlocks | Size |
|---|---|---|
| **S1 · A waitlist create route + UI** (`WaitlistService::create()` exists, unreachable) | Screens 2 and 4 — without it the waitlist cannot be entered at all | **Low–Med** |
| **S2 · Availability admin surface** over `ResourceAvailability` (template + dated exceptions) | Screen 5 almost entirely; W8c was deferred and flagged in the readiness check | **Med** — the model is already there |
| ~~**S3 · Service catalog CRUD**~~ ✅ **DONE (SCHED.P2)** — `ServiceCatalogController` over the existing `ServiceCatalog` service; list + editor + archive | Screens 6 and 7 | — |
| ~~**S4 · Day-Board reads `legalTransitionsFrom()`**~~ ✅ **DONE (SCHED.P1)** — via `AppointmentService::boardActionsFor()`, which also expresses the D-156 compose as a question to the machine rather than a special case | Fence 4.4 coherence | — |
| **S5 · Booking confirmation notification** (transactional, consent-gated like reminders) | Screen 3's promise; also the waitlist "confirmation sent" line | **Low–Med** |
| **B4 · `Resource` capability field** | Dental Chair Scheduling **and** APPT.P4; not needed by these 8, but named because both wait on it | **Med** |
| **S6 · Waitlist preference model** (days/times/earliest) | Screen 4's pool table as drawn | **Med** — a schema change |
| **S7 · A real slot reservation** for an outstanding offer | Screen 4's "slot held" copy | **Med**, and a product decision |

**Deliberately NOT gaps:** move ranking, auto-send, no-show risk, clinical waitlist priority, the
double-book override, per-channel consent. Each is a decision the build has taken the other way.

---

## 6 — Correctly more real — keep, do not trim

1. **One booking path**, transaction + `FOR UPDATE` lock + overlap assertion widened by service buffers.
2. **A closed transition machine** with terminal states, and `booked → arrived` deliberately illegal.
3. **Soft-suspend is online-only** — staff can still book a suspended branch.
4. **`active` and `bookable_online` are separate gates**, and both are honoured by the finder and the public form.
5. **Availability edits never move or cancel a booked appointment** — exactly what screen 5 promises.
6. **Waitlist acceptance seats the chair through the locked path**, never page-side.
7. **Declining an offer costs the patient nothing** — they return to `waiting` and keep their place.
8. **The waitlist agent is ceilinged at APPROVE** — it drafts; a human sends.
9. **Public booking has no symptom or triage free-text anywhere**, and the emergency notice persists on every step.
10. **Duration is the service's recorded default** — never predicted (APPT.P1).
11. **Online booking creates no account and upsells nothing.**
12. **Dated availability exceptions carry a `reason`** — a recorded fact, not a guess.

---

## 7 — Proposed fix chain

| Gate | Builds | Proves |
|---|---|---|
| ~~**SCHED.P1**~~ ✅ **DONE** | **Day-Board parity** — real counts, waiting elapsed from `checked_in_at`, per-lane utilisation as a plain untinted ratio, and **S4**: the board now derives every tile's actions from the machine, via the new `AppointmentService::boardActionsFor()`. | An offered action can never be refused — every one is DRIVEN in test. Quick-book still goes through `BookingService`; a conflict is refused and **no override parameter exists anywhere**; the omission card names all six refusals. See §17. |
| ~~**SCHED.P2**~~ ✅ **DONE** | **Service Catalog + Create (S3)** — list, editor and archive over the real columns, through the existing `ServiceCatalog` write path. | One source proven: a duration edited on the screen changes the FINDER's slot length. No price field anywhere, with the omission naming Billing. No delete affordance — `ON DELETE RESTRICT` means archiving is the safe verb. See §16. |
| **SCHED.P3** | **Provider Availability (S2)** — weekly template + dated exceptions over `ResourceAvailability`. | An edit **never** moves or cancels a booked appointment; the conflict warning lists affected appointments read-only; **no granularity/min-notice controls** unless the backend gains them. |
| **SCHED.P4** | **Waitlist: create + list (S1)** — make the feature reachable, and give `priority` a written operational meaning. | Ranking is operational and stated; **no urgency, no clinical label, no auto-send**; the "hold" is described honestly as a queue place, not a slot reservation. |
| **SCHED.P5** *(optional)* | **Booking confirmation (S5)** — a transactional, consent-gated notification, with the LEGAL carve-out worded as PT.P5 words it. | "Confirmation sent" becomes true. Until then screen 3's copy must not claim it. |

**Realistic gate count: 4 core + 1 optional.**

**Recommended order:** P2 → P1 → P3 → P4. Service CRUD is the cheapest and unblocks the clearest
user story; the Day-Board is the highest-traffic screen; availability is the largest honest win
because the model is already there; the waitlist needs its `priority` decision made first.

### Declines and deferrals

- **Screen 8 (No-Show Follow-Up) — DECLINE as drawn (D-188).** Its organising idea is *triage by
  clinical risk*: which missed appointments matter. Nothing records that, and computing it is the
  electric fence's central prohibition. A plain **no-show worklist** — who missed, when, what they
  missed, with the real re-book action — is buildable and useful; it is a different screen and
  should be specified as one rather than shipped as a reduction of this one.
- **The Day-Board conflict resolver — DECLINE the ranking.** Detecting and *showing* a conflict is
  right and the data is there. Proposing and scoring the fix is the optimiser. If a gate wants to
  help, it may show the human the free/conflict answer for a slot **they** picked.
- **Waitlist auto-send and clinical priority — DECLINE** (already refused in Dental; ceiling APPROVE).
- **B4 (`Resource` capability)** stays deferred: none of these 8 screens needs it, and it is owned by
  the Dental chair gate and APPT.P4.

---

## 16 — SCHED.P2 outcome (2026-08-29)

### What already existed, and was therefore not rebuilt

`ServiceCatalog` was already a complete service-layer write path — `create`/`update`/`delete` inside
transactions, with real validation (name and code non-empty, duration > 0, buffers >= 0, at least
one resource type), a **per-tenant unique code**, and branch links guarded against a cross-tenant
branch id. The audit's S3 was therefore a UI gap, not a backend one. This gate wrote **no new
domain rule**; the controller validates request SHAPE and delegates.

One thing was missing at the edge: the catalog's `InvalidArgumentException` (duplicate code, bad
duration) would have surfaced as a **500**. The controller now converts it to a validation error and
a cross-tenant branch id to a 404 — **without restating any rule**, so the service stays the single
authority.

### The screen states what the engine reads

A service is not a label. Four of its fields are read at booking time, and the page says so in
words, because an admin shortening a duration is editing the booking engine:

| Field | What it does | Where |
|---|---|---|
| `default_duration_minutes` | the length of every generated slot | `AvailableSlotFinder` |
| `buffer_before/after_minutes` | widen the no-double-book window around each appointment | `BookingService::assertNoOverlap` (in the SQL) |
| `requires_resource_types` | which resources must be free and assigned | `BookingService::assertResourceTypesMatch` |
| `active` | gates the day-board quick-book list **and** public exposure | `DayBoardController`, `PublicBookingController` |
| `bookable_online` | gates public exposure only | `PublicBookingController` |

**One source, proven rather than asserted.** A test edits a duration *through the screen* and then
asks the **finder** for slots: the generated slot length moves 30 → 90. The mutation that makes the
finder ignore `default_duration_minutes` turns it red. The slot stride is now
`AvailableSlotFinder::SLOT_STRIDE_MINUTES`, a public constant the page displays — so the number an
admin reads is literally the one the scan uses, instead of a `30` retyped in a template.

### Archive, never delete — and the guard is the database

`appointments.service_id` carries **`ON DELETE RESTRICT`**. A service any appointment references
cannot be removed at all: the database refuses. That is the right guarantee — a booked visit must
keep knowing what it was for — but it surfaces as a raw driver error, which is no answer for an
admin.

**So the screen offers no delete.** `active = false` is the soft state that stops NEW use while every
existing appointment keeps its service intact — the BRANCH.P1 shape. The test proves both halves:
an **unreferenced** service deletes cleanly (the positive control, so the refusal is about the
reference and not about deletion being broken), and the referenced one throws while its appointment
survives. The route list is asserted to contain **no destroy route at all**.

### The omissions, stated on the page

**No price or tariff code.** Money lives in the tenant-authored Billing tariff catalog, and the
issued invoice is the legal figure; a price here would be a second source for the same number. The
test asserts no money-shaped column on `services` and no money-shaped key in the payload — **with a
positive control that pricing genuinely exists in Billing** (a real `TariffItem` at
`unit_price_minor`), so the assertion cannot be satisfied by a product that simply has no pricing.

**No slot-granularity setting, no minimum-notice-online, no per-provider buffers.** None of the
three is persisted or read by anything, so a control for them would record a value the engine
ignores (D-176). **No suggested or typical duration** — duration is what somebody set, not what the
system predicts.

All five are named in the "what this page does not show" card and asserted as **rendered** through
the keys the component iterates (GOV.P3).

### D-191 — the ordering column that isn't

The audit flagged `WaitlistEntry.priority` as an undocumented ordering column. **`Service` has no
such column at all** — verified against the live schema: no `sort`, `order`, `rank`, `position` or
`priority`. `ServiceCatalog::list()` orders by `name`. So the UI writes no ordering field, and this
gate deliberately did not add one; D-191 remains a live constraint for SCHED.P4, not for this screen.

### Mutation-checked twelve ways — and three found defects in the tests

Nine caught first time: a price field added to the payload · the permission dropped to
`appointment.manage` · the update path un-scoped from its tenant · the controller writing the model
directly instead of through the service · the stride replaced by a duplicated literal · the usage
count faked · the omission loop emptied · the price omission no longer naming Billing · the finder
ignoring the service duration.

**Three escaped, and each was a defect in the test rather than the code.**

1. **Hardcoded counts stayed green** — the fixture's counts were `3 / 2 / 1`, exactly what the
   hardcoded triple said. **D-189 for the fourth time.** The test now adds a service and asserts the
   counts *move* to `4 / 3 / 2`.
2. **A granularity field added to the form stayed green** — the scan looked for the template binding
   `form.granularity`, which a field added to the reactive object never produces. Each declined
   concept is now **counted**: it may appear exactly where the omission list names it and nowhere
   else.
3. **Deleting `ServiceCatalog`'s duration rule stayed green** — the controller's own `min:1` request
   rule answered first, so the service's guard was never the deciding factor. The same
   guard-behind-a-guard shape as GOV.P5's free-text opt-in and PT.P7's tenant binding. The service
   rule is now pinned by **calling the service directly** (D-183), with a positive control.

### Stated, not silently accepted

**Service-catalog writes are not audited.** Nothing in `Modules\Scheduling` writes an audit row
directly — the module is architecturally barred from `Modules\Audit\Models`, and appointment
transitions reach the trail by dispatching `AppointmentTransitioned` for an app-layer listener to
record. Adding an event and a listener for service changes is the in-pattern fix, but it is a design
decision that deserves its own gate rather than being smuggled into a UI parity gate. **Flagged, not
built:** a tenant-configuration change that alters what patients can book currently leaves no trail.
---

## 17 — SCHED.P1 outcome (2026-08-29)

### One answer, not two

`AppointmentService::boardActionsFor($status)` is new, and it is the only thing this gate added to the
domain. It maps the board's five verbs onto the statuses they move to, and asks
`legalTransitionsFrom()` — the accessor Appointment Detail already used (APPT.P2) — whether each is
reachable.

**The D-156 compose is preserved without being special-cased.** `arrive` on a `booked` appointment is
legitimate: the board runs confirm → arrive, two legal edges, each asserted inside its own row lock
and each audited. `boardActionsFor()` offers it because it asks whether **both** edges are legal, not
because the verb is hardcoded. If either edge were ever removed from `LEGAL_TRANSITIONS`, the compose
would stop being offered on its own.

What the board now shows, over the fixture's awkward spread:

| status | offered | why |
|---|---|---|
| `booked` | arrive · cancel · no_show | arrive via the D-156 compose |
| `confirmed` | arrive · cancel · no_show | arrive is directly legal |
| `arrived` | start · cancel | **no arrive** — it is not a legal edge from `arrived` |
| `in_progress` | complete | the only legal edge |
| `completed` · `cancelled` · `no_show` | **nothing** | terminal |

**No offered action can be refused, and that is proven by driving them.** The test walks every
(status, action) pair the board actually offers, posts it through the real transition route, and
asserts the appointment MOVED. A separate test forges an action the board does *not* offer and
confirms the service still refuses it — the action list grants nothing; the machine remains the
authority.

**A correction to this document's own audit.** §4.4 originally gave "Arrive on a booked appointment"
as the live refusal. It is not: the compose has always handled it. The real refusals were `start` or
`complete` on a booked appointment, `arrive` on an already-arrived one, and every action on a
terminal appointment. The finding was right; the example was wrong, and it is corrected above.

### No override, and the guard is untouched

Every book still runs `lockResource` → `assertNoOverlap`. The test books into an occupied slot
through the board's own quick-book route and asserts the refusal, with a positive control that the
identical booking succeeds at a free hour — so the refusal is the guard, not a broken fixture. It
then repeats the conflicting request carrying `override`, `keep_both` and `force`, asserts it is
*still* refused, and asserts that `BookingService` reads none of those parameters. Removing
`assertNoOverlap` turns it red.

### The carve-outs, kept plain

**Utilisation** is booked minutes over the branch's own scan window — two recorded quantities and a
division. The idle lane is present at `0`, not hidden. No tint, band, rank or "best lane": the test
scans the payload for any such key and asserts the lane order is the resource order. Adding a
`band` field turns it red; hiding the idle lane turns it red.

**Waiting** is elapsed minutes since the recorded `checked_in_at`, and an appointment nobody checked
in carries `null` rather than a zero. There is no threshold anywhere — the test asserts the component
never *compares* the elapsed value, and that the line carries no class bound to it. Tinting it turns
it red.

### Honest waitlist copy

The panel's behaviour is unchanged — `scheduler.fill_from_waitlist` still sits at the **APPROVE**
ceiling and acceptance still routes through `BookingService`. What changed is the copy: the audit
found the design claiming the slot is held and *"no one loses the slot to silence"*. It is not held.
The board now says an outstanding offer holds the patient's **place in the queue, not the slot**, and
a test asserts both that the new wording is there and that `assertNoOverlap` never consults
`waitlist_offers`.

### Mutation-checked twelve ways

Caught: the board offering every verb · the compose dropped · terminal statuses offering cancel · the
overlap guard removed · a tint band on utilisation · the idle lane hidden · `checked_in_at` faked ·
the waiting line tinted · the grid ignoring the server's list · the omission loop emptied · the
waitlist over-claim restored.

**One was a NO-OP, recorded rather than counted (D-187).** Removing the
`whereNotIn([cancelled, rescheduled])` from the utilisation query changes nothing: `transition()`
deletes an appointment's resource links when it moves to either status, and the sum is per *link*, so
such an appointment already contributes zero. The filter is kept deliberately — it states the intent
independently of that implementation detail — and the comment now says so.

**A twelfth mutation found a real test gap.** Making the grid's `offers()` return `true`
unconditionally left every assertion green — they all checked the PAYLOAD, and none checked that the
COMPONENT consults it. Pest cannot execute a template, so the test now reads the component and
asserts that `offers()` answers from the list and that all five action buttons — exactly five — are
gated by it. **A payload can be right while the screen ignores it.**

### Two defects only the browser could find

Both were invisible to a green suite, and both were real.

**1. The omission card rendered nowhere.** It had landed *inside the quick-book slide-over*
(`v-if="quickBookOpen"`), so it existed only while that dialog was open. The test asserted the
iterated keys and the `t()` call were present in the component — GOV.P3's rendered-output rule — and
they were. **Source presence is not reachability.** Moved into the main column; the test now also
asserts the card appears before the modal's own block, so it cannot drift back inside.

**2. The waiting time was wrong twice, for two different reasons.** It first showed `0 min`, then
`152 min`, for a check-in 34 minutes old. A naive `Y-m-d H:i:s` is ambiguous:

- the **viewer's** machine was UTC−7, and a naive string parses as browser-local, so a check-in half
  an hour ago read as hours in the *future* and clamped to zero; and
- `ApplyTenantLocaleTimezone` sets PHP's default zone to the practice's (Europe/Zurich here) while
  `config('app.timezone')` stays UTC, so **Eloquent re-labels the UTC-stored string as Zurich** and
  shifts it two hours. That middleware's own docblock already notes that per-widget
  datetime→timezone display conversion is an open follow-up.

The fix reads the **raw** column, parses it as UTC — the documented storage contract — and computes
the elapsed **on the server**, so neither ambiguity can reach the screen. This is D-091's family: the
bug is always someone interpreting a naive timestamp in a zone other than the one it was written in.

**Worth carrying forward:** any *other* widget that renders an absolute time from a naive timestamp
is subject to the same two-hour relabel. This gate fixed the one it introduced; the general
follow-up remains open, as the middleware says.

**Three further test defects were found before the code was cleared**, all the same shape as previous gates:
a scan that forbade `amber` and `noShowRisk`, which appear **only** in the comment and omission list
that decline them (third occurrence of this trap — COMMS.P2 and SCHED.P2 hit it too); a `text-danger`
scan that matched the pre-existing Cancel button; and an expectation of 240 booked minutes where the
honest figure is 210, because a cancelled appointment releases its resource links.
---

## 8 — What this audit did not do

Fixed nothing. No app code, no tests, no seeders committed. The fixture states above were produced by
a throwaway scratchpad script driving the real services; nothing about it is in the repository.
`resources/prototype/` remains gitignored.
