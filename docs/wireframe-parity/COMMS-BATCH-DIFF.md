# Comms — wireframe-parity batch diff (8 screens, AUDIT ONLY)

**Audited 2026-08-25 at `8f0b2e8` (GOV.P5), CI green.** Fifth domain batch, after Dental core, the
Patients & Clinical core, the Portal batch (11/11) and the Governance & AI batch (10/10). **No app
code was written for this audit.** `resources/prototype/` stays gitignored.

**Environment note, stated rather than assumed:** Redis is **not reachable on this machine** and is
not installed. Nothing in this audit needed it (the work is reading code and one seeded database),
and the suite runs on array drivers regardless.

**The headline.** Three of these eight screens are built on a consent model the product does not
have. CareOS enforces exactly **one** outbound-comms consent — `comms.email`, per patient,
all-or-nothing for non-legal mail. The mocks assume **per-topic, per-channel, per-household** consent
("Health campaigns & marketing … PORTAL · EMAIL · SMS … declined by client"), a **client/household**
grouping, and a **campaign** feature. None of those exist. This is the Comms analogue of the
governance batch's nine invented tool keys: the screens are not a little ahead of the backend, they
describe a different product.

---

## 1 — The eight screens

Decoded to `resources/prototype/comms-*.wireframe.html` (gitignored). None had been decoded before.

| # | Screen | Purpose (one line) | Audience | Live route · Vue | Built state |
|---|---|---|---|---|---|
| 1 | **Unified Inbox** | Three-pane staff inbox; the AI draft never sends itself | **STAFF** (`comms.manage`) | `comms/inbox` · `Comms/Inbox.vue` | 🔵 **LIVE, NEVER COMPARED** |
| 2 | **Consent-Blocked Draft** | The fence firing on contact consent — the agent refuses before drafting | **STAFF** | consent gate is live in the send path; **this state screen is not built** | ⚪ **NO LIVE PAGE** |
| 3 | **Notification Center** | Cross-system per-person feed; "Needs you" apart from FYI | **STAFF** | — (`admin/notifications` is SETTINGS, a different thing) | ⚪ **NO LIVE PAGE** |
| 4 | **Opt-in Confirmed** | The client turned a topic on herself; the campaign can now be drafted | **STAFF** (about a patient action) | — | ⚪ **NO LIVE PAGE** |
| 5 | **Reminder Sent Confirmation** | A dunning reminder is on its way; undo window; the ladder advances | **STAFF** (billing) | dunning is live; **no confirmation screen** | ⚪ **NO LIVE PAGE** |
| 6 | **Request Consent Update** | A one-time, non-promotional ask to opt in | **STAFF** composing → **patient** receives | — | ⚪ **NO LIVE PAGE** |
| 7 | **Telehealth Sessions** | Staff roster of today's video visits; join per row | **STAFF** (`encounter.manage`) | `telehealth` · `Telehealth/Sessions.vue` | 🔵 **LIVE, NEVER COMPARED** |
| 8 | **Telehealth Join** | Provider pre-join with device check; minimal in-session chrome | **STAFF** | part of the staff telehealth surface | 🔵 **LIVE, NEVER COMPARED** |

**Adjacent and already done, so out of scope here:** the *patient* side of messaging (Portal
Messages, PT.P4) and the agent-draft review surface (Approval Queue, APPROVAL.P1–P7 + the Draft
Review Composer). The patient's own telehealth join is `portal/telehealth` (PT batch).

---

## 2 — The real comms machinery, as found

**Threads and membership.** `Thread` (type `patient` | `internal`, status `open` | `closed`),
`Message` (`author_type` staff/patient, `ai_assisted` bool), `ThreadParticipant` (staff **or**
patient, with `removed_at`). Visibility is **membership**, not a foreign key —
`ThreadService::assertPatientAccess()` requires the thread to be the patient's own, an *active*
participant row, an active portal account **and** `portal.access` consent. PT.P4 pinned each layer
separately after finding two of them masked.

**The one send path.** `NotificationService::send()` gates in this order: template resolution (the
category comes from the **template**, never the caller — a caller trying to relabel is rejected) →
tenant **email preference** (non-legal only) → **consent** (non-legal only) → driver → delivery
record. Every outcome is a `NotificationDelivery` row: `queued` · `sent` · `failed` · `skipped`,
with a `skipped_reason` (`no_consent`, `pref_off`, `channel_unavailable`, `no_recipient_address`).

**Consent, precisely.** `CONSENT_SCOPES = [email => 'comms.email']`. One scope. Per patient. The
**LEGAL category is never consent-gated** (D-F7/D-184) — dunning and statutory notices send
regardless, which PT.P5's copy had to admit rather than over-claim.

**Channels: email only.** `NotificationTemplate` declares three channel *constants* (`email`, `sms`,
`portal`) but **only `EmailNotificationDriver` is wired**. An SMS template would fail closed twice —
`CONSENT_SCOPES` has no `sms` key so the consent gate skips it as `no_consent`, and the driver
lookup would skip it as `channel_unavailable`. `NotificationPreferenceService` says so in its own
docblock: *"there is no SMS preference because no SMS channel is wired."*

**Preferences are tenant-level, not patient-level.** `MANAGEABLE = [appointment.reminder,
waitlist.offer, telehealth.invite]` — an **admin** toggles these for the whole tenant. There is no
per-patient topic switch anywhere.

**The agent.** `DraftReplyTool` (`comms.draft_reply`, operational, ceiling **suggest**, permission
`comms.manage`) drafts a reply to a **patient thread** and nothing else. Its `execute()` re-grounds
and posts through `ThreadService` with `ai_assisted: true`, **and requires a human actor** — the
route is `POST /comms/inbox/send-draft`, which calls `ApprovalQueue::approve()`, re-authorising the
reviewer and re-executing from live state. **There is no send tool, no campaign tool, no outreach
tool.** `InboxAgent::refuseClinical()` sets `clinician_attention_at` + a reason and produces **no
draft**; GOV.P2 found nothing anywhere clears that flag.

**Telehealth.** `TelehealthParticipant` is an append-only join/leave proof (`joined_at`, `left_at`
settable once, staff|patient). Tokens are issued on demand and never stored. Staff surface is
`encounter.manage`-gated.

**Dunning.** `DunningEvent` is an append-only record that a level fired, guarded at model and DB
trigger. The dunning mail is the **LEGAL** template — the one that is never consent-gated.

### What does NOT exist (verified by query and grep)

| The mocks assume | Reality |
|---|---|
| Per-**topic** consent ("Health campaigns", "Recalls & check-ups", "Billing & invoices") | Two consent templates exist: `portal` → `portal.access`, `comms` → `comms.email`. Nothing else is enforced anywhere |
| Per-**channel** consent ("portal · email · SMS", "SMS stays off") | One channel is wired. There is no per-channel consent because there is nothing to consent to |
| A **client/household** ("Keller household · CL-2024-0192 · 3 patients") | No household, client or family table exists |
| **Campaigns / marketing outreach** | No campaign model, no outreach tool, no marketing send path |
| A per-user **notification feed** with read state | No `notifications` table. `admin/notifications` is the tenant *settings* screen |
| Patient-managed consent **topics in the portal** | The portal consent screen (PT.P5) shows the two real consents and can **withdraw**; there is no topic list and no self-service opt-in |

### Fixture verified by query (`DemoClinicSeeder`)

```
threads              patient 5 (1 flagged clinician_attention) · internal 1 · all open
participants         staff 7 · patient 5
messages             patient 5 · staff 3 · staff ai_assisted 1   ← agent-drafted, human-sent
comms.email consent  7 patients granted · 8 without (15 total)
deliveries           sent 1 · skipped 1 (reason: no_consent)     ← "was it sent" has a real answer
```

---

## 3 — Per-screen diff

Severity: **High** = a fence or contract issue · **Med** = a real gap a user notices · **Low** = chrome.

| Screen | Deltas (mock → live) | Classify | Severity |
|---|---|---|---|
| **1 · Unified Inbox** | The spine is **already right**: three panes, filter pills, derived unread, reply on open threads, assign/close, the AI draft as a pending `AgentAction` with an explicit human Send, `ai_assisted` provenance, source chips, and the flagged-thread state with **no draft offered**. Missing chrome: the dark thread-header band, avatars/gradient bubbles, the live "Today" divider, the internal-thread chip pinned on scroll, the right-hand **patient context pane** (next appointment + Open chart). The mock shows **MRN and DOB** in that pane — legitimate on a staff screen, and the live Inbox does not show them today. | (a) chrome · (b) context pane = backend-gap (small) | **Low–Med** |
| **2 · Consent-Blocked Draft** | **Built on four things that do not exist**: topic consent, per-channel consent, a household, and a campaign the agent would draft. The *shape* of the finding is real and worth keeping — the fence refuses **before** drafting, staff **cannot override**, and the honest next step is to ask the patient — but as drawn it cannot be built. The real analogue is: a patient **without `comms.email`** cannot be emailed, and `NotificationService` returns `skipped/no_consent` (a real row, in the demo). | **MUST-NOT-BUILD-AS-DRAWN** (D-170) | **High** |
| **3 · Notification Center** | No backend at all: no feed table, no per-user read state, no "Needs you" queue. **GOV.P2 already built the honest subset** — `NeedsHumanReader` answers "what is waiting on a person" for agent governance, permission-scoped, with the excluded worklists named. A general cross-system feed is a new subsystem. | backend-gap (substantial) | **Med** |
| **4 · Opt-in Confirmed** | Depends entirely on #2's model. The one real element: a patient **can** change consent themselves in the portal — but only **withdraw** (PT.P5), and only the two real consents. There is no self-service *opt-in*, no topic, and no channel matrix. | **MUST-NOT-BUILD-AS-DRAWN** | **Med** |
| **5 · Reminder Sent Confirmation** | Dunning is real and the ladder is real (`DunningEvent`, levels, append-only). Three problems: **"email + portal"** implies a portal delivery channel that is not wired; the **17-second Undo** implies an unsend that does not exist (PT.P6 refused the same affordance); and **"delivered"** is a state `NotificationDelivery` cannot produce — it records `sent`, never a delivery receipt. | (a) **channel** · (b) **unbacked undo** · (c) **fabricated state** | **High** |
| **6 · Request Consent Update** | The *principle* is sound and matches the product's posture (ask, never override). But it needs topic consent, an SMS/portal channel choice, and an outreach send path — none of which exist. The agent is also shown **drafting an outreach message**, which no registered tool does. | **MUST-NOT-BUILD-AS-DRAWN** | **Med–High** |
| **7 · Telehealth Sessions** | Live and close. Mock adds the day-roster framing and per-row join. **Correctly-more-real already:** *"None of these calls are recorded"* is true and enforced — no recording affordance exists anywhere, and the token is issued on demand and never stored. | chrome | **Low** |
| **8 · Telehealth Join** | Live surface exists; the pre-join **device check** (camera/mic/connection) is browser-side and genuinely buildable, and **"Patient waiting · 2 min"** is *sourceable* — `TelehealthParticipant.joined_at` is a real append-only row. Mock shows **MRN + age** on a staff pre-join screen: legitimate. Missing: the pre-join panel itself, the elapsed timer, End-session chrome. | (a) chrome · (b) device check = new (client-side) · (c) waiting time = backend-gap (small) | **Low–Med** |

---

## 4 — THE FENCE VERIFICATION

### 4.1 Consent gates every send

| Send path a screen implies | Where consent is checked | Pinned? | Verdict |
|---|---|---|---|
| Appointment reminder, waitlist offer, telehealth invite (real templates) | `NotificationService::send()` — non-legal → `comms.email` required, else `skipped/no_consent` | **Yes**, and PT.P5 pins it with a **direct service call** so no middleware answers first (D-183) | **correctly-more-real** |
| Dunning reminder (screen 5) | **Deliberately NOT consent-gated** — the LEGAL category bypasses both the preference and the consent gate | Yes — PT.P5 asserts the dunning mail **still sends after withdrawal**, which is what keeps the portal copy honest (D-184) | **correctly-more-real**, and the carve-out must stay stated in patient copy |
| Staff reply in a thread (screen 1) | `ThreadService::postStaffMessage()` → `assertPatientAccess()` → membership + active portal account + **`portal.access`** | **Yes** — PT.P4 pinned the service-level re-check with a direct call after finding the middleware masked it | **correctly-more-real** |
| Agent draft → human send (screen 1) | `ApprovalQueue::approve()` re-authorises `comms.manage` and re-executes; the tool re-grounds before posting | Yes | **correctly-more-real** |
| **Campaign / outreach (screens 2, 4, 6)** | **Nowhere — the path does not exist** | n/a | **MUST-NOT-BUILD-AS-DRAWN.** If outreach is ever built, the consent check belongs in the send service, not the composer |

**The copy risk (D-184):** any patient-facing text must not say "we will never email you". The LEGAL
carve-out is real, and PT.P5's consent screen already words it correctly. Screens 2/4/6 use
"do not contact" and "applies to every channel" — **absolute claims the product cannot keep.**

### 4.2 The agent drafts, a human sends

| Finding | Live enforcement | Verdict |
|---|---|---|
| Screen 1's **Send / Edit as reply / Discard** on an agent draft | Exactly the real contract: `send-draft` → `ApprovalQueue::approve()` (re-authorise + re-ground + `ai_assisted`), edit moves text to the plain composer, discard rejects. Ceiling is **suggest** — clamped by `AutonomyPolicy` and re-clamped by `AgentResolver` at call time | **correctly-more-real** |
| Screens 2/6: **the agent drafts an outreach campaign / an opt-in request** | No such tool. The registry's ten tools draft *replies*, classify, suggest, propose, replan, preflight — GOV.P1 found every invented key was an *acting* one | **MUST-NOT-BUILD-AS-DRAWN** (D-170) |
| Screen 5: the reminder is "sent by a person" with an AI-assisted marker | Dunning mail is template-driven and legal; there is no agent draft in that path today. Attributing it to an agent would assert an action never taken (D-179) | **backend-gap / reconcile** |
| Anything implying **auto-send or scheduled agent send** | None of the eight draws one — the mocks are consistently "a person sends". Worth recording as a **pass** | **correctly-more-real** |

**The ceiling is what makes it safe (PC.P7):** nothing on these screens depends on the UI withholding
a button. `comms.draft_reply` cannot exceed suggest, and a forged higher level is clamped twice.

### 4.3 Channel honesty

**SMS appears on four screens (2, 4, 6, and by implication 5's "email + portal") and does not
exist.** One driver is wired; the constants for `sms` and `portal` are declared but unbacked, and
both fail closed. Drawing an SMS toggle — even switched *off*, as screens 4 and 6 do — tells a
reader the channel exists and is merely disabled. That is D-176 in its purest form: an unbacked
presence the user cannot distinguish from a real one.

**Verdict: MUST-NOT-BUILD-AS-DRAWN.** If a channel picker is ever built it must offer only wired
channels; a channel with no driver should not be listed at all, not listed-and-greyed.

### 4.4 "Sent" must mean sent

| State the mocks show | Can the backend produce it? |
|---|---|
| `sent` | ✅ real — `NotificationDelivery::STATUS_SENT` with `sent_at` |
| `skipped` + reason | ✅ real — and the demo has one (`no_consent`) |
| `failed` | ✅ real — driver throw is recorded with the error |
| `queued` | ✅ real |
| **`delivered`** (screen 5) | ❌ **no** — nothing records a delivery receipt. `sent` means *handed to the mailer* |
| **"you can still undo" / recall** (screen 5) | ❌ **no** — there is no unsend. PT.P6 refused the identical 18-second undo, and the reasoning is the same: the send already happened |

**Verdict: MUST-NOT-BUILD-AS-DRAWN** for `delivered` and the undo window. The honest confirmation is
*"sent"*, with the delivery row as its evidence — the PC.P6 "Mark as sent" precedent, where the verb
was pinned to what the system actually did.

### 4.5 No clinical interpretation

| Finding | Verdict |
|---|---|
| Screen 1's flagged state: *"Flagged for a clinician — contains a clinical question"*, quoting the patient's own words, with **no draft offered** | **correctly-more-real.** This is a routing fact plus the patient's own message on a `comms.manage` screen where staff already read it. The agent produced nothing — exactly what `refuseClinical()` does |
| The flag's reason text | Real and recorded. Note GOV.P2's finding: **nothing clears `clinician_attention_at`**, so a screen must not imply the flag can be dismissed |
| Any AI-suggested clinical reply | None drawn. `DraftReplyTool` grounds only in thread history, active KB and admin facts, and refuses clinical topics | **correctly-more-real** |
| Screen 5's dunning body (payment-plan offer, QR-bill) | Financial, not clinical, and template-driven | fine |

### 4.6 Disclosure and scope

| Surface | Scoping | Verdict |
|---|---|---|
| Staff inbox (1) | `Gate::authorize('comms.manage')` + tenant scope; threads are `BelongsToTenant`; opening writes a read-audit row | **correctly-more-real** |
| Thread visibility | `ThreadParticipant` membership, with `removed_at` respected — PT.P4 pinned it with the patient's **own** thread and their participation removed, because a foreign thread was refused earlier on `patient_id` | **correctly-more-real** |
| Telehealth (7, 8) | `encounter.manage` + tenant scope; token issued on demand, never stored | **correctly-more-real** |
| Notification centre (3) | Mock says "role-scoped server-side" — nothing to scope yet | backend-gap; **if built, scope per category the way `NeedsHumanReader` does, fail-closed** |
| Screens 2/4/6 | Household-level consent implies showing one patient's consent state on another's screen. **No household exists**, and inventing one would create a cross-patient disclosure surface | **MUST-NOT-BUILD-AS-DRAWN** |

---

## 5 — Shared components and shared backend gaps

### 5.1 Components — reuse before building

| Need | **Reuse** | Notes |
|---|---|---|
| Agent draft card + provenance | **the PC.P2 agent panel** and the Approval Queue's draft treatment | Same dashed-pending + source-chip + `ai_assisted` shape already built twice |
| "What this page does not show" | **the GOV.P1/P3 card** | Screens 2/4/6 will need it if a reduced version is built |
| Closed count tiles | **`StatCard`** (D-166) | Inbox counters are plain row counts |
| Patient context pane (screen 1) | **S1 clinical header** from the PC batch | Staff-side, so MRN/DOB are appropriate — unlike the portal (D-181) |
| Consent state display | **PT.P5's consent card** | Already renders purpose + real consequence per consent |
| Telehealth roster row | **`Telehealth/Sessions.vue`** exists | Chrome only |
| Device pre-check (screen 8) | **genuinely new** | Browser-side `getUserMedia` probe; no backend |
| Notification feed (screen 3) | **genuinely new** | Needs a table, read state and role scoping |

### 5.2 Backend gaps — one fix unlocking several

| Gap | Unlocks | Size |
|---|---|---|
| **C1 · Inbox patient-context reader** (next appointment + chart link for the open thread) | Screen 1's third pane | **Low** — both facts exist |
| **C2 · Telehealth waiting/elapsed from `TelehealthParticipant`** | Screen 8's "patient waiting 2 min" and the in-session timer | **Low** — append-only rows already carry `joined_at` |
| **C3 · A per-user notification feed** (table + read state + role scoping) | Screen 3 entirely | **High** — a new subsystem |
| **C4 · Per-patient, per-topic, per-channel consent** | Screens 2, 4, 6 | **High** — a consent-model change touching the send path, the portal and the fence. **Not a parity gap** |
| **C5 · A campaign/outreach send path + tool** | Screens 2, 4, 6 | **High**, and it would need a new autonomy ceiling decision |

**Deliberately NOT gaps:** SMS/WhatsApp channels, delivery receipts, message recall, and
agent-authored outreach. Each is a product decision the build has taken the other way; listing them
as gaps would smuggle them into a parity chain.

---

## 6 — Correctly more real — keep, do not trim

1. **One wired channel, failing closed twice** — an unwired channel is skipped by both the consent
   map and the driver lookup.
2. **The template owns the category** — a caller cannot relabel a marketing message as legal to slip
   the consent gate (asserted).
3. **The LEGAL carve-out is explicit and tested** — dunning still sends after withdrawal, and the
   patient-facing copy says so instead of over-claiming.
4. **`skipped` + `skipped_reason`** — a non-send is a *recorded row*, not silence.
5. **Thread visibility is membership**, with each layer pinned separately (PT.P4).
6. **The agent drafts; a human sends** — through `ApprovalQueue::approve()`, re-authorised and
   re-grounded, with `ai_assisted` provenance that cannot be removed.
7. **A clinical question produces NO draft** — the fence flags and hands off.
8. **No recording affordance anywhere in telehealth**, and the join token is never stored or logged.
9. **`DunningEvent` and `TelehealthParticipant` are append-only** at model and DB-trigger level.
10. **Opening a thread writes a read-audit row** — the disclosure appears in the patient's access log
    (PC.P5).

---

## 7 — Proposed fix chain

| Gate | Builds | Proves |
|---|---|---|
| **COMMS.P1** | **Unified Inbox parity** — the header band, avatared bubbles, Today divider, pinned internal chip, and the **patient context pane** (C1). | The draft still routes through `send-draft` → `approve()`; the flagged state still offers **no** draft; unread stays derived; the read-audit row stays one per render. |
| **COMMS.P2** | **Telehealth Sessions + Join parity** — the roster framing, the pre-join device check (client-side), the elapsed timer and waiting time from `TelehealthParticipant` (C2). | Still `encounter.manage`-gated; the token is still on-demand and unstored; **no recording affordance appears**, asserted as rendered. |
| **COMMS.P3** *(optional)* | **A "reminder sent" confirmation** for dunning — the real one: *sent*, the delivery row as evidence, the ladder position. | **No `delivered`, no undo, no portal channel.** States the LEGAL carve-out. Honest verbs only. |
| **COMMS.P4** *(optional, larger)* | **C3 — a notification feed.** | Role-scoped fail-closed; "Needs you" reuses `NeedsHumanReader` rather than a second definition. |

**Realistic gate count: 2 core + 2 optional.**

**Recommended order:** COMMS.P1, then COMMS.P2. Both are chrome-plus-a-small-reader over machinery
that is already correct, which is the cheapest real value in this batch.

**Recommended deferrals — and one recommendation to decline.** C3 (notification feed) is a genuine
feature, worth doing when someone asks for it. **C4/C5 — topic consent, channel consent, households
and campaigns — should not enter a parity chain at all.** They are not a gap between the wireframe
and the build; they are a different consent architecture and a marketing capability the product has
deliberately not built. Screens 2, 4 and 6 should be marked **not-for-build** against the current
model, and revisited only as a product decision with its own design, its own fence review and its own
autonomy ceiling. Building a reduced version would be worse than leaving them out: a topic list that
only ever holds one topic, or a channel matrix with one live column, teaches the user something
false about what the practice can promise a patient.

---

## 8 — What this audit did not do

Fixed nothing. No app code, no tests, no seeders. `resources/prototype/` remains gitignored; the
eight decoded artefacts live there and are not committed.
