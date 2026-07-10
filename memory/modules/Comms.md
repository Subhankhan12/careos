# Comms module memory

## Status

Phase G active. P0G.G2 added the notification engine (versioned templates, consent-aware,
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

## Open items

- G.2 notification engine; G.3 unified inbox UI (adds `thread_reads` + `assigned_to`); G.4 telehealth;
  G.6 Inbox agent (`ai_assisted` drafts).
