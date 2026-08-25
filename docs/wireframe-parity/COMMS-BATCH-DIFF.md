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
| ~~**C1 · Inbox patient-context reader**~~ ✅ **DONE (COMMS.P1)** — `InboxPatientContextReader`: identity, recorded allergies, next appointment, open balance (engine reader), email-contact status. Five elements, five separate gates | Screen 1's third pane | — |
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
| ~~**COMMS.P1**~~ ✅ **DONE** | **Unified Inbox parity + the patient context pane** (C1) — five recorded facts, each permission-scoped fail-closed; the "still needs a human" filter over GOV.P2's conjunction; real counts; recorded agent provenance naming the human sender; the declined affordances stated on the page. | The draft still routes through `send-draft` → `approve()` at a **suggest** ceiling; the flagged state still offers **no** draft; unread stays derived; **the read-audit row is still exactly one per render** despite four new reads. See §14. |
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

## 14 — COMMS.P1 outcome (2026-08-25)

### What was already right, and therefore left alone

The inbox's spine did not need rebuilding. The dark thread header, the filter pills, the derived
unread marker, the internal-thread chip, the clinician-attention banner, the dashed agent-draft card
with its source chips, and the absence of a "request draft" button on a flagged thread were all
already there and already correct. **This gate added a context pane, a filter, two counts, a
provenance line and an omission card — and changed no rule.**

### The context pane — five elements, five separate gates

The pane is the risk on this screen, so each element is gated on the permission that owns the data,
enforced in the reader (not the controller, not the page), and refused with **no value at all**.

| Element | Gate | Why that gate |
|---|---|---|
| Identity (name, DOB, record no.) | `patient.view` | the least a correspondent needs to know who they are writing to |
| Recorded allergies | **`encounter.manage`** | clinical content. The chart shows allergies at `patient.view`, but the chart is a clinical surface opened deliberately; the inbox is operational and reception works in it. Where the right gate is arguable, GOV.P5's rule applies — **take the stricter one**, because a disclosure cannot be recalled |
| Next appointment | `appointment.manage` | scheduling fact |
| Open balance | `billing.view` | via `PatientBalanceReader::present()` — the same figure AR shows; **no page arithmetic** |
| Email-contact status | (the inbox's own `comms.manage`) | a fact about the correspondence already being conducted |

**Refused means EMPTY, not a plausible default.** A `0.00` balance or an empty allergy list would
tell the viewer something false about the record — "none recorded" and "you may not look" are
different claims, and the pane renders them differently. A mutation returning `'0.00'` for a refused
balance turns the suite red.

**What the pane deliberately does NOT carry:** no diagnosis, acuity, risk, triage or priority; no
computed summary of the patient or the conversation; no SLA or overdue marker. The recorded severity
of an allergy travels as **the clinician's own word**, and allergies are ordered **by substance** —
ordering by severity would be the system asserting a priority nobody recorded (D-169/D-173).

### One definition of "still needs a human", not two

GOV.P2 established that `clinician_attention_at` is set by the fence and **never cleared**, so a
filter on the raw flag would offer a worklist no reply could ever shorten. The conjunction —
flagged **and** still open **and** no staff message since the flag — has now moved onto the model as
`Thread::scopeWaitingForClinician()`, and `NeedsHumanReader` delegates to it. The inbox filter, the
inbox count and the governance dashboard therefore cannot drift into describing different sets; a
test asserts the two surfaces agree. The conjunction itself is unchanged, and GOV.P2's suite is
green without modification.

### Provenance: both facts, together

An agent-drafted message shows that a draft was involved **and names the person whose Send posted
it**. That is not an inference — `DraftReplyTool::execute()` passes the HUMAN as the actor, so
`author_staff_user_id` genuinely is the sender. PT.P4 left the *portal-side* wording an open
decision; this is the staff side, where naming the colleague who pressed Send is simply the
accountability record.

### The declined affordances, stated on the page

Four things the wireframes draw that this build cannot honestly back, now named in a "what this
screen does not show" card rather than silently omitted: **SMS/phone/WhatsApp** (one driver is
wired; a greyed-out second column would assert a channel exists), **"delivered"/"read"** (the record
says a message was handed to the mailer; nothing reports back), **undo/recall** (there is no
unsend — PT.P6 refused the identical affordance), and **per-topic/per-channel consent switches**
(D-188). The card is asserted as **rendered** via the keys the component iterates — GOV.P3's lesson,
where copy-only assertions left the render loop free to be emptied.

### A reconciliation the gate's own wording assumed otherwise

The gate asked for "a reply … refused at the SERVICE with a recorded reason row". **A thread reply
does not email anyone.** `ThreadService` never calls `NotificationService`; a staff reply is an
in-portal message the patient reads behind the three-layer portal check. Building an email-on-reply
would have been exactly the new send path the gate's own rules forbid. So the consent refusal is
pinned where it actually lives — `NotificationService::send()` returning
`skipped`/`no_consent` for a real template, called directly (D-183), with the positive control that
granting consent makes the identical call send. The three patient-side visibility layers are pinned
separately in the same file, each with the other two left satisfied.

### Mutation-checked twelve ways — and two of them found defects in the tests

Ten mutations were caught first time: the allergy gate deleted · the balance gate deleted · a
refused element returning `0.00` · a computed risk score added · the raw flag replacing the
conjunction · the "no staff reply" conjunct inverted · provenance dropping the sender · an SMS
symbol on the page · the omission loop emptied · the consent copy over-claiming "we will never
email you".

**Two escaped, and both were the same defect in my fixture rather than in the code.**

1. **Ordering by severity stayed green.** The fixture recorded Aspirin as *mild* and Penicillin as
   *severe* — so ordering by substance and ordering by severity produced the *same list*, and the
   assertion could not tell them apart. Aspirin is now recorded *unknown*, which sorts after
   `severe` alphabetically and before it by any clinical reading, so the two orderings genuinely
   disagree.
2. **Counting the capped page instead of the record stayed green.** With one thread in the fixture,
   the visible list and the record-wide count were both 1. A second, unflagged thread now makes the
   list longer than the count, so the two can disagree.

Both are D-174 in its exact form: *the fixture has to be the case that would tempt the breach*. A
test whose fixture makes two different implementations agree is not testing the difference.
---

## 8 — What this audit did not do

Fixed nothing. No app code, no tests, no seeders. `resources/prototype/` remains gitignored; the
eight decoded artefacts live there and are not committed.
