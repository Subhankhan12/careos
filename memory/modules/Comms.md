# Comms module memory

## Status

Phase G active. P0G.G1 registered the module and added secure messaging threads (patient + internal)
with append-only messages.

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

## Open items

- G.2 notification engine; G.3 unified inbox UI (adds `thread_reads` + `assigned_to`); G.4 telehealth;
  G.6 Inbox agent (`ai_assisted` drafts).
