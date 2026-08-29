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
| **The Day-Board does not.** `DayBoardActionController` accepts `arrive,start,complete,cancel,no_show` for any appointment, and the Vue renders actions without consulting the machine — the mock describes this accurately ("all actions render on hover… the server enforces") | **backend/UI gap, not a breach.** The server is authoritative either way, but **the same product answers "what may I do?" two different ways on two screens.** The `booked → arrived` case is live: Arrive on a `booked` appointment is offered and refused |
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
| **S3 · Service catalog CRUD** over real columns | Screens 6 and 7 | **Low** |
| **S4 · Day-Board reads `legalTransitionsFrom()`** | Fence 4.4 coherence; kills the `booked → arrived` refusal | **Low** |
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
| **SCHED.P1** | **Day-Board parity** — glance band (real counts only), waiting-room strip from `checked_in_at`, per-lane utilisation as a plain untinted ratio, status legend, online markers, and **S4** (actions from `legalTransitionsFrom()`). | Quick-book still goes through the finder + `BookingService`; **no ranked resolver, no override, no "on schedule"**; the omission card names them. |
| **SCHED.P2** | **Service Catalog + Create (S3)** — one gate, two screens, plain CRUD over real columns. | `active` / `bookable_online` gates asserted end-to-end (an inactive service leaves board, quick-book and public form at once). **No price field** — stated on the page, with the invoice named as the legal figure. |
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

## 8 — What this audit did not do

Fixed nothing. No app code, no tests, no seeders committed. The fixture states above were produced by
a throwaway scratchpad script driving the real services; nothing about it is in the repository.
`resources/prototype/` remains gitignored.
