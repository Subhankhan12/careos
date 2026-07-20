# Comms module memory

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

## Open items

- G.2 notification engine; G.3 unified inbox UI (adds `thread_reads` + `assigned_to`); G.4 telehealth;
  G.6 Inbox agent (`ai_assisted` drafts).
