# Comms module memory

> **STATE as of `cc0ed68` (2026-08-30).** The COMMS wireframe-parity batch is **COMPLETE**: two core gates —
> **COMMS.P1 `11cf333`** (unified inbox + the patient context pane) and **COMMS.P2 `ab9e62c`** (telehealth
> sessions + join). **Wireframe screens 2, 4 and 6 were DECLINED under D-188** — they rest on a per-topic /
> per-channel consent model, a household grouping and a campaign feature this product does not have; a reduced
> version would teach staff to promise what the practice cannot keep. **There is no COMMS parity work left —
> do not invent a P3.** One OPEN GAP is recorded and deliberate: **a telehealth join is never recorded from
> any HTTP path** (`recordJoin()`/`recordLeave()` are called only by tests and the demo seeder). **Do NOT wire
> `recordJoin()` to token issuance** — a token is minted before anyone connects, so that writes a join that may
> never have happened into an **append-only** table (**D-190**). See `DEFERRED.md` (d).

## Status

**Phase G COMPLETE** (P0G.C). The staff-facing surface is FROZEN for the design pass;
`docs/SCREENS.md` is the factual re-skin brief (22 Inertia pages + 11 nurse-PWA screens, grouped by
area, with routes/guards/props/actions per page). Delivered: secure threads (G.1), the notification
engine (G.2), the unified inbox (G.3), telehealth (G.4), the patient portal (G.5), and the
draft-only Inbox agent (G.6).

P0G.G4 added telehealth: `TelehealthProvider` adapter (LiveKit default +
FakeTelehealthProvider for tests), metadata-only `telehealth_sessions` + append-only
`telehealth_participants` (leave fills `left_at` once), `TelehealthService` with recording-disabled
room creation, short-lived single-room/identity/role tokens (no record grant), three-way patient
gate, transactional invitations, and full audit/read-logging. P0G.G3 added the unified inbox UI (thread list/filters/unread badges, detail +
composer, close/reopen, light assignment) on `pages/Comms/Inbox.vue` with `thread_reads` markers and
`threads.assigned_to`. P0G.G2 added the notification engine (versioned templates, consent-aware,
append-only deliveries) and migrated the Phase C reminder + Phase F dunning senders onto it.
P0G.G1 registered the module and added secure messaging threads (patient + internal) with
append-only messages.

## Key classes

- `Modules\Comms\Models\Thread`: tenant-owned thread; `type` patient|internal; patient threads carry
  `patient_id` (required), internal threads must NOT reference a patient (model guard). `LogsReads`
  with `auditPatientId()` so disclosure is patient-scoped read-logged.
- `Modules\Comms\Models\ThreadParticipant`: exactly one of `staff_user_id`/`patient_id` (DB CHECK +
  model guard). A patient can NEVER be added to an internal thread, and only the thread's own patient
  may participate in a patient thread (model guards).
- `Modules\Comms\Models\Message`: APPEND-ONLY at model + DB-trigger level. Author is staff|patient|
  system with matching author FK shape; `ai_assisted` flag (set by G.6 later); corrections are new
  messages, never edits.
- `Modules\Comms\Services\ThreadService`: openPatientThread / openInternalThread / addStaffParticipant /
  addPatientParticipant / removeParticipant / postStaffMessage / postPatientMessage / close / reopen /
  messagesForStaff / messagesForPatient.

- `Modules\Comms\Models\NotificationTemplate`: tenant-owned versioned template; key/channel
  (email|sms|portal)/locale/subject/body/category (transactional|legal|marketing)/active/version;
  unique `(tenant, key, channel, locale, version)`.
- `Modules\Comms\Models\NotificationDelivery`: APPEND-ONLY delivery record written ONCE at attempt
  (or skip decision) with final status (queued|sent|failed|skipped), rendered SNAPSHOT
  subject/body, template version, `skipped_reason`, unique `(tenant, dedupe_key)`.
- `Modules\Comms\Services\NotificationService`: send() (sync, for callers already in workers) and
  queue() (Horizon `SendNotificationJob`); template resolution = tenant's newest active version,
  else `BUILT_IN` platform defaults (`appointment.reminder` transactional, `billing.dunning` legal).
- `Modules\Comms\Contracts\NotificationChannelDriver` + `Channels\EmailNotificationDriver` +
  `Notifications\TemplateNotification`: email ships now; sms/portal plug in later (SMS DEFERRED).
- App-layer bridges (D-017): `App\Comms\EngineAppointmentReminderChannel extends` Scheduling's
  email channel and `App\Comms\EngineDunningChannel extends` Billing's, both bound in
  AppServiceProvider so those modules never depend on Comms; original notification classes
  (AppointmentReminderNotification / DunningReminderNotification) are preserved via the driver's
  `$mailable` passthrough — Phase C + F suites pass unchanged.

## Invariants

- Mutable moment columns are DATETIME, never TIMESTAMP (D-081/P0P.G15 — extends the existing
  MariaDB-vs-MySQL8 DATETIME rule): `thread_reads.read_at` was the remaining first-TIMESTAMP trap
  and is now DATETIME. Locked by `MutableMomentParityTest`; full brief in `docs/DB-PARITY.md`.
- Comms rows are tenant-owned and fail closed via `BelongsToTenant`. Arch rule: Comms may use care
  modules but not Audit models or AiCore; no other module may use Comms (ModuleBoundariesTest).
- Staff actions require `comms.manage` (RBAC catalog; granted to org_admin + reception starter roles).
- Patient access is fail-closed on THREE checks: the thread's own patient AND an active participant
  AND an active `PortalAccount` with the `portal.access` consent (`ConsentService::has`).
- Messages are append-only communications evidence (raw UPDATE/DELETE `SIGNAL SQLSTATE '45000'`):
  what was communicated must never be silently rewritten; corrections are new messages — same posture
  as audit_events and the financial ledgers.
- Reading a patient thread writes a patient-scoped `read` audit row (`resource_type=threads`);
  internal threads write none. Open/close/participant changes and every posted message are audited
  (`thread.opened/closed/reopened`, `thread.participant_added/removed`, `message.posted`).
- Closed threads accept no new messages; reopen is explicit and audited.
- The notification CATEGORY comes from the TEMPLATE, never the caller (D-G4): a caller-claimed
  category that mismatches is REJECTED, so marketing can never be relabeled legal to dodge consent.
- Consent matrix: marketing->patient and transactional->patient are consent-gated fail-closed
  (email scope `comms.email`; skip + `no_consent`); legal->patient is never consent-gated (D-F7);
  staff recipients are never consent-gated.
- Deliveries snapshot the rendered subject/body at the resolved template version; later template
  edits or new versions never alter history (append-only at model + DB-trigger level).
- Idempotency: sha256 dedupe key over (template key, channel, recipient, sorted context) with a
  unique DB index as the race backstop — a retry or double-dispatch never double-sends.
- Unread counts are DERIVED per staff user from the append-only message stream vs the
  `thread_reads` marker (`Message.id > last_read_message_id`, ULID time-ordering) — never stored.
  Opening a thread in the inbox marks it read and (for patient threads) read-logs.
- Inbox routes: GET `/comms/inbox` (InboxController, Gate `comms.manage`, filters
  type/status/scope + `thread_id` detail) and POST reply/status/assign (InboxActionController —
  all rules in ThreadService; controllers validate shape only, P0D.GU). Realtime is polling;
  Reverb deferred.

- Telehealth (D-G1/G2/G3, D-061..D-064): media never on CareOS servers — schema stores room
  reference/participants/timestamps only and a test asserts no media/recording columns exist.
  Rooms are created with `recording_disabled => true` (adapters refuse otherwise); token grants pin
  `roomRecord/roomAdmin/recorder = false`; TTL <= 600s; tokens never stored/logged; provider keys
  proven absent from logs and audit rows. Patient tokens are fail-closed on active portal account +
  portal.access consent + being the session's patient. Invitations go through the notification
  engine as TRANSACTIONAL (reminder-style consent posture, deliberately not legal). Session
  created/started/ended + every token issue audited; token issue patient-scoped read-logged.
  MariaDB wart fixed across Comms: UPDATE-able moment columns are DATETIME, not TIMESTAMP, because
  MariaDB 10.4 gives the first TIMESTAMP column implicit ON UPDATE CURRENT_TIMESTAMP.

- G.5 portal surfaces: `PortalMessageController` (own threads via ThreadService's fail-closed patient
  path + patient-side read markers `thread_participants.last_read_message_id`, derived unread) and
  `PortalTelehealthController` (session list + on-demand token via the three-way gate; token only in
  the response). Patient unread analog: `threadsForPatient` / `patientUnreadCount` / `markPatientRead`.

- G.6: `threads.clinician_attention_at/reason` is the staff-facing electric-fence flag; the inbox
  shows pending AI drafts via `Contracts\InboxDraftProvider` (implemented in app/ per D-017 so Comms
  never depends on AiCore) with explicit send through `comms.inbox.send-draft` -> ApprovalQueue.
  Messages posted from AI drafts carry `ai_assisted=true`, staff-visible only.

- Staff telehealth join UI (CLINIC.W10): `Http\Controllers\StaffTelehealthController` (`/telehealth`,
  `encounter.manage`) is the CLINICIAN side of the SAME sessions the portal patient joins (W3 `PortalTelehealth
  Controller`). It lists the clinician's OWN created/active sessions (filtered by their `StaffProfile`
  `practitioner_id`; patient names resolved via a typed `Patient` query, not the untyped belongsTo) and issues the
  EXISTING staff token via `TelehealthService::joinTokenForStaff` (POST `/telehealth/{session}/token`, returned
  transiently, mirroring the portal's in-memory fetch). NO new telehealth logic: recording stays disabled at the
  provider (grants pin recorder/roomRecord/roomAdmin=false), the token is short-lived + never stored/logged, media
  never touches the server, and the "not recorded" discipline is displayed. Issue is audited (`telehealth.token_issued`)
  + patient-scoped read-logged by the existing service. Locked by `tests/Feature/Telehealth/StaffTelehealthTest.php`.
  See [[D-098]].

## Notification email preferences (SETTINGS.P5)

Per-event EMAIL on/off preferences: `notification_preferences` (BelongsToTenant: `event_key` + `email_enabled`,
`unique(tenant,event_key)`) + `NotificationPreference` model + `NotificationPreferenceService` (const `MANAGEABLE`
= the non-legal built-in email events `appointment.reminder`/`waitlist.offer`/`telehealth.invite`; `emailEnabled`
default-ON when no row; `setEmail` refuses a non-manageable key). **`NotificationService::send()` consults it**
(after dedupe, before consent): a NON-LEGAL email event whose pref is OFF is recorded `SKIPPED` `pref_off`; LEGAL
(dunning/statutory) is excluded and never suppressible. Default-ON, so pre-existing sends are unchanged. The admin
surface is app-layer `NotificationSettingsController` (`/admin/notifications`, `admin.manage`, audited
`notification.preferences_changed`). **SMS is an inert seam** (no driver/pref). The **clinician-attention flag**
(`threads.clinician_attention_at`, set by the Inbox agent on a clinical refusal — the D-G5 fence) is NOT a
preference: locked-on, no disable path. Locked by `tests/Feature/Admin/NotificationSettingsTest.php` (8). Deferred:
a real SMS provider/channel. See the DIFF doc + [[Platform]].

## Open items

- G.2 notification engine; G.3 unified inbox UI (adds `thread_reads` + `assigned_to`); G.4 telehealth;
  G.6 Inbox agent (`ai_assisted` drafts).
- SMS channel/provider is unwired (SETTINGS.P5 renders an inert SMS seam only).

### COMMS.P1 — the inbox context pane + the shared "needs a human" scope (2026-08-25)

`Modules\Comms\Services\InboxPatientContextReader` is the patient context beside a thread.
**Five elements, five SEPARATE permission gates, enforced in the reader:** identity
(`patient.view`) · recorded allergies (**`encounter.manage`** — stricter than the chart's
`patient.view` on purpose) · next appointment (`appointment.manage`) · open balance
(`billing.view`, via `PatientBalanceReader`) · email-contact status.

**If you add an element, gate it and pin it with a single-permission user.** The seeded roles
bundle permissions, so a catalogue role can never prove an individual gate — build the user the
catalogue does not.

**A refused element returns NO VALUE** — never a zero, never an empty list. "None recorded" and
"you may not look" are different claims.

**NEVER add to this payload:** a diagnosis, acuity, risk, triage, priority, SLA/overdue marker
or computed summary. Allergies are ordered BY SUBSTANCE — ordering by severity would assert a
priority nobody recorded.

**`Thread::scopeWaitingForClinician()` is THE definition of "still needs a human"** — flagged
AND open AND no staff reply since the flag. `NeedsHumanReader` delegates to it. Change it here
or nowhere; the inbox and the governance dashboard must not drift.

**A thread reply does NOT email anyone.** `ThreadService` never calls `NotificationService`. Do
not add one — that is a new send path. The `comms.email` consent gate lives in
`NotificationService::send()`, and the LEGAL category is never gated by it.

### COMMS.P2 — telehealth surfaces (2026-08-25) · COMMS BATCH CORE COMPLETE

`Modules\Comms\Services\TelehealthSessionReader` is what the telehealth surfaces may say.

**WHAT THE BACKEND CAN REPORT:** a session was created; a token was ISSUED; a participant
JOINED (`joined_at`); one LEFT (`left_at`); the session started and ended.

**WHAT IT CANNOT:** whether anyone is connected RIGHT NOW. `left_at` is only written when
`recordLeave()` is called, so a dropped connection never records one. **Never render a live
presence, a "waiting" timer or a wait-time threshold** — and a token is NOT a join (D-179).

**KNOWN GAP (reported, not fixed): no HTTP path calls `recordJoin()`/`recordLeave()`.** Only
tests and the demo seeder do. Do NOT wire `recordJoin()` into the token endpoint — a token is
issued before anyone connects, so that would record a join that may never happen. Doing it
honestly needs a client-side connected callback.

**NEVER add to these surfaces:** recording in any form (not even disabled), a transcript, an AI
summary, a connection-quality score, or a participant-row mutation (append-only at model AND
trigger). The token is never persisted — no column, no audit context.

**The device pre-check is browser-local.** It reports availability only, is never posted, and
gates nothing server-side. If you make the server read a client claim, you have broken it.

**An unconfigured provider is stated up front** — `livekit.invalid` with no secret is the deploy
default, so the surface withholds Join and says why rather than failing at the moment of use.


## QA-FIX.9a (2026-09-09) — `P10-C3`: the second caller of `ApprovalQueue::approve()` is gated and scoped

**THE ENUMERATION, which is the part worth keeping.** Comment-stripped, `approve()`/`reject()`/
`autoExecute()` have FIVE call sites: `AiApprovalQueueController` ×3 (single approve, reject, bulk loop —
all `Gate::authorize('ai.manage')`), `InboxAgentController::sendDraft` (**was ungated**), and
`AgentRuntime::autoExecute` (gates the tool permission AND requires `reversible`; unreachable while the
ceiling clamps `auto`). Inverting the search over the **29 controllers with zero authorisation call**
showed every other one is a non-staff guard (portal ×11, nurse PWA ×5, kiosk, public booking, guest
tokens) or delegates to a service that gates on the action's own permission — `ThreadService`
(`comms.manage`), `VisitAssignmentService` (`dispatch.manage`, branch-scoped), `OrderService`
(`order.manage` + `patient.view`), `EncounterService` (`encounter.manage`), `ClinicalNoteService`
(`note.write`/`note.sign`), `DocumentService` (`note.write`). **No other ungated door**, and `P1-M2`'s
structural note about `OpenEncounterFromAppointmentController` is confirmed benign.

**THE DIAGNOSIS.** `ApprovalQueue::approve` was never ungated — it gates the **TOOL's** permission, which
answers *may this person do the underlying work*. It does not answer *may this person approve an agent's
proposal*; `ai.manage` answers that and **no service asks it**. A service-level gate answers the service's
question, not the surface's.

**THE FIX, in two halves.** (1) `Gate::authorize('comms.manage')` on both `InboxAgentController` methods —
the gate the sibling `InboxController:31` already has. **`ai.manage` deliberately NOT used:** reception
holds `comms.manage` without it, so the governance permission would have made an AI-drafted reply
org_admin-only. (2) **The scope, which is what closed the hole:** `whereKey() + tool_key =
comms.draft_reply + status = pending`, so a clinical or financial id is NOT FOUND. That is the single-item
counterpart of the bulk exclusion and deliberately not a copy — a surface-scoped route needs a SCOPE,
stronger than a category blacklist and needing no list kept in step with the tool registry. The governance
queue keeps no category check on its single approve, because a reviewer with `ai.manage` looking at one
item IS the informed review.

**WHICH LAYER BITES, established by mutation rather than assumed — the two are complementary.** The ROUTE
GATE catches someone with no business on this surface at all: the `P10-C3` attacker is a doctor holding
`note.write` and not `comms.manage`, refused **403** before the lookup runs (driven in the browser after the
fix). The SCOPE catches a LEGITIMATE surface user reaching outside their surface — reception holds
`comms.manage`, passes the gate, and is still refused a clinical id — which the gate cannot see, and which
is why the scope is what closes the finding. In the refusal TEST the two overlap, so removing the route gate
leaves that one test green; that is written into the test file rather than hidden (D-182). The non-vacuous
proof of the gate is the structural test; of the scope, the three category/state tests.

**Also fixed, because the finding names it:** no try/catch meant a fence refusal or a second Send was a
**500**; `AiCoreException` is now answered as a redirect with an error.

**Guarded by** 7 tests in `tests/Feature/AiCore/InboxAgentApproveGateTest.php`, mutation-checked three ways
(route gate removed → structural test red; scope removed → all three category/state tests red, the `P10-C3`
reproduction; a gate removed from `AiApprovalQueueController` → structural test red, proving it scans both
files), each confirmed applied by a **comment-stripped** count. `P10-M7` (approve executes `input_payload`,
never the `proposed_output` the reviewer read) is untouched and stays open. See D-218, [[AiCore]],
[[Comms]], [[LOG]].
