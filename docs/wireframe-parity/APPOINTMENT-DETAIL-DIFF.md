# Appointment Detail — Wireframe-Parity Diff (audit only)

**Scope:** diff the intended live surface `Scheduling/Appointments/{appt}` against the decoded wireframe
`resources/prototype/appointment-detail.wireframe.html` on every axis (layout · hero · resources · patient ·
history timeline · action row · reschedule modal · styling · states · copy) **AND** the two semantics that matter
here — (1) the **state machine** (`AppointmentService::LEGAL_TRANSITIONS`) and (2) the **overlap guard**
(`BookingService::lockResource → assertNoOverlap`). **This is an audit. No app code was changed.**
Eighth page of the wireframe-parity pass (Admin Settings ✓, Approval Queue ✓, Branches ✓, Agent & Tool Config ✓,
Allergy safe-part ✓, Billing & AR ✓, AR Account Detail ✓).

- **Date:** 2026-08-16 · **HEAD:** `3ddc7ab` (ARDETAIL.P6) · **CI:** green. **Env:** `migrate:fresh --seed` +
  `DemoClinicSeeder`, MariaDB dev. **Login:** org_admin `andrea.lindenhof@praxis-lindenhof.test` / `demo-password`
  / 2FA `JBSWY3DPEHPK3PXP`.
- **THE PAGE IS NET-NEW.** `resources/js/pages/Scheduling/` contains only `DayBoard.vue`; there is no
  `appointments/{id}` staff route. This is **new UI over an ALREADY-COMPLETE backend** — the state machine, the
  slot finder and the overlap guard all exist and are proven below.

---

## 1. The mapping — the net-new page onto the real backend

| Wireframe needs | Real source today | Exists / new |
|---|---|---|
| Hero: service · date/time · duration · ref | `Appointment` (`service_id`→`Service.name`, `starts_at`/`ends_at`, `id`) | **exists** |
| Hero: status pill | `Appointment.status` (8 states, below) | **exists** |
| Hero: "booked online" sub-badge | `Appointment.source` (`staff`/`online`/`agent`) | **exists** |
| Resources: practitioner + room | `AppointmentResource` link → `Resource` (`type`, `name`, `staff_profile_id`) | **exists** |
| Resources: **room capability chips "scanner · X-ray"** | *none* — `Resource` carries only `type`, `name`, `staff_profile_id`, `branch_id`, `active` | **NO SOURCE — honest gap** |
| Resources: assistant (2nd practitioner) | supported: `Service.requires_resource_types` drives N resources | **supported, not seeded** |
| Patient: name · MRN · DOB · age | `Patient` (`first_name`/`last_name`, `mrn`, `date_of_birth`); age via the existing `ageFromDateOnly` helper | **exists** |
| Patient: allergy chip | Clinical allergies (ALLERGY.P1 record-display) — app-layer composition | **exists** |
| Patient: Open Patient 360 link | `patients.show` | **exists** |
| Timeline: "Booked online" row | audit `appointment.booked` (+ `Appointment.source`, `booked_by`) | **exists** |
| Timeline: "Reminder sent · **SMS +** email" | `AppointmentReminder` (`type`/`channel`/`status`/`sent_at`) — but the ONLY channel constant is `CHANNEL_EMAIL` and the only driver is `EmailNotificationDriver` | **email real; SMS NOT built (DEFERRED)** |
| Timeline: "Patient confirmed — **replied 'JA'**" | `appointment.confirmed` audit exists, but `confirm()` takes a **staff `User`** actor; there is no inbound-reply→confirm path | **status real; the inbound-SMS provenance is NOT built** |
| Timeline: status changes | audit `appointment.*` rows carry `from_status`/`to_status`; `Appointment.status_changed_by`/`status_changed_at`/`status_reason` | **exists** |
| Action row (legal transitions) | `AppointmentService::LEGAL_TRANSITIONS` + `transition()`/`assertLegal` | **exists** |
| Reschedule modal: reason (required) | `reschedule()` throws `Reschedule requires a reason.` | **exists (verified)** |
| Reschedule modal: conflict-free slots | `AvailableSlotFinder::forServiceBranchDate(Service, branchId, date, limit)` → `{starts_at, ends_at, resource_ids}` | **exists** |
| Reschedule modal: **"Dr. Weber only" toggle** | *none* — the finder takes no preferred/pinned resource; `firstFreeResource()` picks the first free of each type, ordered by name | **NO SOURCE — honest gap** |
| Reschedule modal: slots across **3 different days** | the finder is **per-date** | **needs a date loop / new method** |
| Reschedule: atomic move + re-book | `reschedule()` — one `DB::transaction`, old row `lockForUpdate`, re-book via `BookingService::book` | **exists** |
| "re-sends the confirmation by SMS + email" | reminder dispatch exists (`appointments:dispatch-reminders`); SMS driver does not | **email real; SMS not built** |

**The real state machine** (`Modules/Scheduling/src/Services/AppointmentService.php:22`):

```
booked      → confirmed · cancelled · no_show · rescheduled
confirmed   → arrived · cancelled · no_show · rescheduled
arrived     → in_progress · cancelled
in_progress → completed
completed / cancelled / no_show / rescheduled → (terminal)
```

**Verified live this audit:** `complete()` on a `booked` appointment is **REFUSED** —
`IllegalAppointmentTransitionException: Illegal appointment transition from booked to completed`, status unchanged.

---

## 2. Section-by-section diff

| Section | Wireframe | Real backend | Class | Severity |
|---|---|---|---|---|
| **Layout / shell** | App shell + breadcrumb "Day-board · Fri 11 Jul → Nora Keller · 10:30"; single-column detail | Shell exists; **no detail page or route** | (a) net-new UI | **High** |
| **Hero** | Service · status pill `Booked` · "booked online" sub-badge · "Fri 11 July · 10:30 → 11:00 · 30 min" · ref `APT-2026-3391` | All fields exist (`status`, `source`, `starts_at`/`ends_at`, id). Duration is the **service's** `default_duration_minutes` — a recorded fact | (a) net-new UI | Med |
| **Resources** | Dr. Sofia Weber (Dentist) · room Behandlung 2 + chips *scanner · X-ray* · DA L. Frei (Assistant) | Practitioner + room links exist and render; **capability chips have no backend field**; an assistant is supported via `requires_resource_types` but not seeded | (a) + **honest gap** on chips | Med |
| **Patient** | Name · allergy chip *Penicillin* · MRN/DOB/age · Open Patient 360 | All exist (Patient + Clinical allergies + `patients.show`) | (a) net-new UI | Med |
| **History timeline** | 3 rows w/ provenance + "Confirmed by patient" badge | Audit rows + `AppointmentReminder` provide the shape; **"SMS" and "replied JA" are not backed**; demo seeds **0 reminders** so the timeline is empty on demo data | (a) + **honest gaps** | Med |
| **Action row** | `Mark arrived · Reschedule · Cancel · No-show`, caption *"only legal transitions shown"* | The rule is right; the **hardcoded list is not** — see §4/§5. Live `ScheduleGrid.vue` has the same defect (status-independent buttons) | (b) state-machine + (c) reconciliation | **High** |
| **Reschedule modal** | Reason · constraint chips · conflict-free slots (soonest badge) · effect sentence · "availability re-checked server-side at confirm" | **Matches the real `reschedule()` almost exactly** — reason required, one transaction, old row locked, re-book through the overlap guard. Except: the practitioner toggle and multi-day list have no finder support | (d) correctly-more-real + (b) | **High** |
| **Styling** | Eucalyptus Glow — `#5C7D55`/`#3E6238`/`#A7C4A0`/`#C6DABF`/`#F4EFE6`/`#2A332A`/`#b00020`, Inter | Same system in the app | (a) visual | Low |
| **States** | No real `<input>`s (static mocks); no empty/loading/error/locked variants drawn | The build must add them (esp. "no conflict-free slots", terminal-status appointment) | (a) net-new UI | Med |
| **Copy** | Operational + provenance-rich; "only legal transitions shown"; the effect sentence | Truthful once wired to real transitions/finder | (a) copy | Low |

---

## 3. Visual deltas (the net-new UI to build, in Eucalyptus Glow)

Everything on this page is new; there is no existing surface to re-skin. Reuse the AR-Account-Detail idiom
(ARDETAIL.P3): `euca-tile-dark` hero, `glass-card` sections, status pills, timeline with dot+rail, modal over a
scrim. Specifically: (1) hero with status + source badges and the time/duration line; (2) a resources card with
avatar chips per resource and its type label; (3) a patient card with the allergy chip and the 360 link;
(4) a provenance timeline (title · sub-line · timestamp); (5) an action row rendered from the **real** legal set;
(6) the reschedule modal (reason, constraint chips, slot rows, effect sentence, confirm/cancel).

---

## 4. STATE-MACHINE + GUARD VERIFICATION (the crux)

- **The action row must be server-derived, not hardcoded.** The wireframe hardcodes four buttons; the live
  `resources/js/Components/ScheduleGrid.vue:103–117` hardcodes five on **every** tile regardless of status. The new
  page must render `LEGAL_TRANSITIONS[currentStatus]` supplied by the controller, so a `completed` appointment
  offers nothing and an `arrived` one offers only *start · cancel*. ✅ The server is already authoritative
  regardless: an illegal transition throws `IllegalAppointmentTransitionException` (verified above), so this is a
  **UX-honesty** requirement, not a security hole.
- **The reschedule is correctly-more-real and must be reused verbatim.** `reschedule()` already: requires a reason
  (verified: blank → *"Reschedule requires a reason."*), `assertLegal(→ rescheduled)`, runs one `DB::transaction`
  with the old row `lockForUpdate`, then re-books via `BookingService::book`, which applies
  **`lockResource` → `assertNoOverlap`** (`BookingService.php:176–177`). So the wireframe's *"frees the 10:30 slot"*,
  *"in one transaction"* and *"availability re-checked server-side at confirm"* are **literally true today**, and a
  reschedule **cannot double-book**. ⚠️ The slot list must come from `AvailableSlotFinder` — **never** a page-side
  list. Verified real output for 2026-08-17: 5 conflict-free slots, each with its `resource_ids`
  (e.g. `08:00→08:30 · Anja Wyss + Sprechzimmer 1`).
- **NO COMPUTED JUDGMENT — confirmed clean.** A scan of the wireframe for
  `risk|score|predict|likelihood|probab|acuity|priority|urgen|recommend|suggest|AI|auto-|smart|confidence|estimat|forecast|propensity`
  returns **zero hits**. No no-show risk, no computed priority, no predicted duration. "soonest" is an ordering
  fact, "no conflict" is a finder output, "30 min" is the service's configured duration. **The build must introduce
  none.**
- **NO MONEY — confirmed absent.** Zero hits for `CHF|invoice|charge|price|fee|payment|bill`. If a "bill this visit"
  control is ever added it must go through `ChargeCaptureService`/the billing engine (reconcile-to-the-unit), never
  page-computed.

---

## 5. THE `booked → arrived` RECONCILIATION — surfaced, and **already resolved in the live app**

**The wireframe shows `Mark arrived` on an appointment whose pill reads `Booked`, and `LEGAL_TRANSITIONS` has no
`booked → arrived` edge.** But the live app already solves this **without weakening the machine**, and that is the
decisive finding of this audit:

`Modules/Scheduling/src/Http/Controllers/DayBoardActionController.php:34–37`

```php
if ($action === 'arrive') {
    $appointment->status === Appointment::STATUS_BOOKED
        ? $appointments->arrive($appointments->confirm($appointment, $actor), $actor)  // two LEGAL steps
        : $appointments->arrive($appointment, $actor);
}
```

**Verified live this audit** (day-board, Jonas Gerber 09:00, `booked` → clicked *Arrive*): status became `arrived`,
and the audit trail records **both** legal steps separately —

```
appointment.booked
appointment.confirmed   from=booked    -> to=confirmed
appointment.arrived     from=confirmed -> to=arrived
```

So the transition was **composed, not bypassed**: each edge is legal, each is audited, `LEGAL_TRANSITIONS` is
untouched. What is inaccurate is only the wireframe's *caption* ("the transitions the server will allow from
Booked (arrive · …)") — `arrive` is reachable from Booked as a **two-step compose**, not as a single legal edge.

**The options (a decision to make, none of which weakens the machine):**
- **(c) Keep the existing compose — RECOMMENDED, it is established precedent.** Offer `Mark arrived` from Booked and
  let the controller walk `confirm → arrive`, exactly as the day-board does today. Consistent across surfaces,
  already audited, zero machine change. The label may honestly read "Mark arrived" while the tooltip/caption says it
  also confirms.
- **(a) Render the pill as `Confirmed`.** The depicted appointment *is* substantively confirmed (timeline: "Patient
  confirmed — replied 'JA'"), which makes `Mark arrived` a single legal edge. Truthful, but only for this fixture —
  it does not answer what to do for a genuinely un-confirmed booking.
- **(b) Show `Confirm` from Booked**, with `Mark arrived` appearing after. The most literal reading of the state
  machine, but it diverges from the day-board's one-click behaviour and adds a click for reception.

**Explicitly NOT an option:** adding a `booked → arrived` edge to `LEGAL_TRANSITIONS`. That would weaken the machine.

---

## 6. Correctly-more-real (keep — do NOT regress to the wireframe)

- **The reschedule flow already matches the wireframe's promises** (reason-required, atomic, old row locked,
  re-booked through the overlap guard). Keep it exactly; the page is a thin caller.
- **The server is authoritative on every transition** — `assertLegal` refuses an illegal move regardless of what any
  UI offers (proven). Keep; the action row is reflect-only.
- **The wireframe's rule "only legal transitions shown" is BETTER than the live day-board**, which renders a
  status-independent button set (`ScheduleGrid.vue`). Adopt the wireframe's *rule* on the new page — and treat the
  day-board's hardcoded list as a separate, pre-existing UX-honesty defect (noted, not in scope here).
- **Status vocabulary is richer live than in the wireframe**: the machine has `confirmed`, `in_progress` and
  `rescheduled` states the wireframe never draws. The page must handle all eight, not just the four depicted.

---

## 7. Honest gaps (surface-don't-fabricate — do NOT invent a backend for these)

1. **Room capability chips "scanner · X-ray"** — `Resource` has no capability/equipment field anywhere in Scheduling.
   Either omit the chips or add a real tenant-authored capability field in its own gate. **Do not hard-code them.**
2. **"Dr. Weber only" toggle** — `AvailableSlotFinder` has no preferred-resource parameter; it picks the first free
   resource per type by name (verified: it offered *Anja Wyss*, not a requested practitioner). Either omit the toggle
   or extend the finder with an optional preferred-resource filter (its own gate).
3. **"SMS + email"** — only `AppointmentReminder::CHANNEL_EMAIL` and `EmailNotificationDriver` exist; SMS/WhatsApp
   drivers are explicitly **DEFERRED**. Label the real channel; do not claim SMS.
4. **"Patient confirmed — replied 'JA'"** — there is no inbound-reply→confirm path; `confirm()` takes a staff `User`.
   Show the real confirmation provenance (who/when), not a fabricated SMS reply.
5. **Multi-day slot list** — the finder is per-date; offering "Today / Mon 14 / Tue 15" needs a date loop or a new
   finder method. A page-side merge of engine results is fine; a page-side *slot computation* is not.
6. **Demo data shows an empty timeline** — `AppointmentReminder` count is 0 after `DemoClinicSeeder`, so the history
   card needs a real empty state (and, for a demo, seeded reminders).

---

## 8. Prioritized parity punch-list

**Build (net-new UI over the complete backend):**
1. *(High)* **The page itself** — route `GET /scheduling/appointments/{appointment}` (string-id resolved in-controller
   per FIX.1), `appointment.manage` gate, `Scheduling/AppointmentDetail.vue`: hero · resources · patient · timeline ·
   action row · reschedule modal, Eucalyptus Glow, i18n keys, plus the drill link from the day-board tile.
2. *(High, state-machine)* **Action row from the REAL legal set** — the controller supplies
   `LEGAL_TRANSITIONS[status]`; the Vue renders only those. A forged illegal POST stays refused by `assertLegal`
   (already true). Honour the wireframe's own rule rather than its hardcoded list.
3. *(High, guard)* **Reschedule via the real services** — slots from `AvailableSlotFinder`, the move through
   `reschedule()` (reason required, atomic, overlap-guarded). No page-side slot list, no page-side re-book.
4. *(Med)* **Timeline from real records** — audit `appointment.*` rows + `AppointmentReminder`, with honest channel
   labels and an empty state.
5. *(Med)* **States** — no conflict-free slots; terminal-status appointment (no actions); loading/error on the modal.

**Decide before building:**
6. *(High)* **The `booked → arrived` reconciliation** — pick (c) keep the existing confirm→arrive compose
   (recommended), (a) pill-as-Confirmed, or (b) explicit Confirm action. **Never** add the illegal edge.

**Do not fabricate (own gates if wanted):**
7. *(Med)* Resource capability chips · *(Med)* preferred-practitioner slot filtering · *(Low)* SMS reminder channel ·
   *(Low)* inbound-reply confirmation provenance.

**Fence call-outs that must hold in every gate above:** the action row reflects the REAL `LEGAL_TRANSITIONS` and the
server stays authoritative; the reschedule uses the REAL slot finder and the `lockResource → assertNoOverlap` guard
so a move can never double-book; **no computed clinical or operational judgment** (no no-show risk, no computed
priority or duration); **no money on this page** unless it goes through the billing engine.
