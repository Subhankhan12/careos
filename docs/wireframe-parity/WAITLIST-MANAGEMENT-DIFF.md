# Waitlist Management — Wireframe-Parity Diff (audit only)

**Scope:** diff the `Waitlist Management` wireframe against the live scheduling/waitlist backend on every axis
(layout · sections · fields/controls · actions · columns · copy · states · design language) **AND** the fence
axis — any computed judgment, any agent action that must ride the real capped/ApprovalQueue path, any booking
that must go through the real no-double-book path. **This is an audit. No app code was changed.**

- **Date:** 2026-08-17 · **Recorded at HEAD:** `c086de5` (OPMODE.G3) · **CI:** green.
- **Decoded wireframe:** `resources/prototype/waitlist-management.wireframe.html` (gitignored).
- **STATUS: AUDITED, FIX CHAIN NOT STARTED.** No WAITLIST.P* gate has been issued or built.

> **Provenance note.** This page was decoded and audited as a standalone triage task (alongside
> `Waiting On Approval`), *after* the nine-page wireframe-parity pass had already closed. The audit was
> delivered as a report at the time but never written to a file; this document records it, re-verified against
> the code at `c086de5`. It does **not** reopen the nine-page pass — this is a tenth page, triaged separately.

---

## 1. What the wireframe shows

**Focal card — "A slot just opened":** `Cancellation · 4 min ago` · `Thu 17 Jul · 09:30–10:00 · Dr. Meier ·
Check-up · Operatory 2` · ✦ *"Agent matched 3 waiting"*, ranked 1/2/3 with the **reasons** it ranked them
(service ✓, day ✓, morning ✓, longest wait) → `Review & send offer` / `Skip` / `Offer instead`.

**Stat strip:** Filled from the waitlist 5 this week · Waiting 14 · Offers out 3 · ✦ Drafts ready 4 ·
Needs review 2.

**Standing table:** columns `PATIENT · WANTS · WILL ACCEPT · WAITING · AGENT STATUS`; filters
All/Check-up/Hygiene/Treatment/Urgent; sort "Longest waiting first"; a `+ Add to waitlist` action.

**Add-to-waitlist slide-over:** patient · service · provider preference · **preferred days (Mon–Sat)** ·
**preferred times (Morning/Midday/Afternoon/Evening)** · **earliest acceptable date** · **Short notice OK** ·
priority (Normal / Urgent·clinical) · **Offer by — consented channels only (SMS / Email / Phone·not consented)**
· optional note.

**Five states:** default · empty ("No one is waiting") · **offer sent** (slot held to 18:00, auto-release,
`waitlist.offer_sent`) · **offer accepted** (booked, removed, day-board updated, `scheduling.waitlist_booked`) ·
**offer declined** (keeps place, no penalty, rolls to rank 2, `waitlist.offer_declined`) · **urgent escalated**
(`waitlist.escalated_to_clinician`).

---

## 2. What the backend already supports — most of it

| Wireframe | Backend (verified at `c086de5`) |
|---|---|
| The waiting pool + statuses | `waitlist_entries` — `waiting/offered/booked/expired/cancelled` |
| Offers out, expiry, auto-release | `waitlist_offers` — `offered/accepted/declined/expired`, `expires_at`, `ExpireWaitlistOffersCommand` (scheduled) |
| Ranked matches | `WaitlistService::matchingForSlot()` — `orderByDesc('priority')->orderBy('created_at')` = **priority, then longest-waiting**: exactly the wireframe's sort |
| Match reasons (service ✓, window ✓) | Real criteria: `service_id`, branch (null-or-match), `flexible` OR the desired window containing the slot |
| Review & send / accept / decline | `WaitlistOfferService::offer/accept/decline/expire` + four POST routes |
| *"only the locked booking path seats the chair"* | **TRUE** — `accept()` runs `DB::transaction` + `lockForUpdate` → `BookingService::book` (the no-double-book path) |
| *"declining never penalises"* | True — `decline()` leaves the entry `waiting` |
| Agent drafts, a person decides | `scheduler.fill_from_waitlist`, `permission: appointment.manage` |
| Consent-gated offers | `waitlist.offer` is TRANSACTIONAL and keeps the reminder-style consent gate |
| Audit events | Lifecycle events → app-layer audit |

**There is no waitlist PAGE today — only a Day-Board PANEL.** `resources/js/pages/Scheduling/` holds just
`AppointmentDetail.vue` and `DayBoard.vue`. The panel ("Waitlist auto-fill", 18 i18n keys) is **slot-first**:
pick a freed slot → Find candidates → Offer, plus an offers table. The wireframe inverts this into a
**patient-first standing pool with its own nav item**.

---

## 3. The gaps

1. **🔴 BLOCKER — nothing can add anyone to the waitlist.** `WaitlistService::create()` has **exactly one caller
   in the whole repo: `DemoClinicSeeder`** (verified). There is no route, no controller, no UI, and no portal
   self-waitlist path. The wireframe's primary CTA — and its claim that *"a patient asks to be waitlisted from
   the portal"* — has **no backend entry point at all**. Without this the page is a viewer for seeded rows.
2. **The preference model is far thinner than the slide-over.** Backend has one desired window
   (`desired_starts_at`/`desired_ends_at`), `flexible`, `priority` (int) and `branch_id`. It has **no**
   preferred-days multi-select, no time-of-day bands, no earliest-acceptable-date, no short-notice flag, no
   per-entry channel selection and no note. *"WILL ACCEPT: Thursday / Mornings / Wed only (after school)"* is
   not representable.

---

## 4. Fence / governance flags

- **✅ No computed clinical judgment.** Ranking is service-match + stated preference + wait time — operational,
  not clinical. Urgency is patient/staff-**flagged**, never computed.
- **✅ The urgent-escalation state is the fence working.** But note `priority` is a plain integer with no
  "clinical" semantics, and *"routes urgent/clinical to a person"* is **not** in `FillFromWaitlistTool` today.
- **⚠️ THE ONE REAL FENCE CONFLICT — the wireframe claims auto-send.** It says *"routine offers can go
  automatically at Level 1 on consented channels."* The real tool ceiling is **`AutonomyPolicy::APPROVE` (2)**;
  `AUTO` is **3** and is **unreachable** for this tool (verified). Rendering an "automatic" tier would advertise
  autonomy the resolver refuses to grant. **The agent never auto-sends.**
- **⚠️ SMS/Phone do not exist.** `waitlist.offer` is `CHANNEL_EMAIL` only (verified). The slide-over's SMS/Phone
  toggles are the standing SETTINGS.P5 seam.
- **✅ Booking safety is real** and must stay routed through `WaitlistOfferService::accept()` →
  `BookingService::book` → `lockResource`/`assertNoOverlap`. No page-side seating.
- **No money anywhere.**

---

## 5. THE DECIDED SCOPE (for the fix chain, when it is issued)

**BUILD:**
- The **standing waitlist page** (patient-first pool + focal freed-slot card + the five states) over the
  existing entries/offers/ranking/accept/decline backend.
- **The add-to-waitlist WRITE PATH** — the blocker in §3.1. Without it the page has no reason to exist.

**OMIT (do not fabricate):**
- **The auto-send tier.** The ceiling is APPROVE; the agent drafts and a person sends.
- **SMS / phone channels** — surface as an honest seam or omit; never imply an SMS was sent.

**RECORD AS A GAP (not built this chain):**
- The richer preference model (preferred days, time-of-day bands, earliest date, short-notice, per-entry
  channels, note). Until it exists, the table's "WILL ACCEPT" column shows only what the backend really holds.

---

## 6. Verdict

**A genuine parity page over a genuinely rich backend — roughly 70% of it renders existing data through existing
routes — with one hard blocker (no way onto the list) and one fence conflict (the drawn auto-send tier).**

**Priority: BELOW deployment.** It is buildable and well-understood, but it is workflow convenience, not a
security or correctness gap. See `DEFERRED.md`.
