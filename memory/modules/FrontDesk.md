# Module: FrontDesk (`Modules\FrontDesk`)

Patient self check-in for a booked appointment, plus a constrained self-update of the patient's own
contact details — via a shared reception **kiosk** (no login, identity-verified) OR from the
**authenticated portal**. One `CheckInService`, two entry paths (P0P.G7).

## How it works

- **Storage (D-074):** check-in lives ON the appointment (chosen over a separate table): new columns
  `checked_in_at`, `check_in_source` (kiosk/portal/reception), `check_in_code` (a short per-appointment
  kiosk-match code generated at booking by `BookingService`, uppercase, ambiguity-safe alphabet). Index
  `appointments_kiosk_lookup (tenant, branch, starts_at, check_in_code)`.
- **`CheckInService::checkIn(appointment, patient, source, ?expectedBranchId)`** — ownership fail-closed,
  today + (kiosk) branch + check-in-able state (booked/confirmed/arrived), idempotent (a second call is a
  no-op returning the existing state). Arrival goes through the EXISTING
  `AppointmentService::arriveForPatient` (booked→confirmed→arrived, patient actor, NO staff gate — identity
  verified upstream), never a direct status write. Patient-scoped audit `appointment.checked_in`.
- **`CheckInService::updateContact(patient, data, source)`** — updates ONLY phone/email/address contacts
  through `PatientService::update` (patient=[] so no demographic field is writable); preserves non-editable
  (emergency) contacts; patient-scoped audit `patient.contact_updated`. NO other field editable at check-in.

## Kiosk safety (HARD rules, all tested)

- **No clinical data, no browsing.** The kiosk exposes ONLY resolve + check-in + own-contact-update. The
  resolve payload keys are exactly `{found, verification, appointment, contact}`; contact keys are exactly
  `{phone, email, address}`. No chart/problems/meds/allergies/notes, no patient search endpoint.
- **Identity = name + DOB + check-in code** at the kiosk's branch, today, booked/confirmed.
  `KioskCheckInService::resolve` returns EXACTLY ONE match or a generic `{found:false}` — never a candidate
  list, never any PHI on failure (asserted: no name/id in the not-found body). Ambiguous (>1) → not found.
- **Ephemeral, capability-bound.** A successful resolve returns a short-lived (300s) `Crypt`-encrypted
  verification handle binding {appointment_id, patient_id, branch_id, exp}; check-in/contact require it, so
  the kiosk token can never act on an arbitrary patient. The Vue page holds state in memory only — NEVER
  localStorage/sessionStorage — and auto-resets on completion / 60s idle. Large (≥44px) touch targets.
- **Branch-scoped device token.** `KioskDevice` (tenant-owned, one branch, sha256 token hash, revocable).
  `IdentifyKioskDevice` middleware (alias `kiosk-device`) resolves the device by the `{kioskToken}` route
  param WITHOUT a tenant context (the only cross-scope read, via `withoutGlobalScopes`), then sets the
  tenant context from the device. Unknown/revoked → flat 403. Provisioned by an admin (`admin.manage`) via
  `KioskDeviceController`; the plaintext token/URL is shown ONCE. Code entry is `throttle:10,1` rate-limited.

## Portal path

`PortalCheckInController` runs behind `portal-tenant` + `portal-auth` + `portal-consent` (withdrawn
`portal.access` → 403 on the next request). Identity is the authenticated portal account only; a patient can
check into their OWN appointment only (ownership scoped → 404 otherwise). Contact self-update reuses the same
`CheckInService::updateContact`. The portal Appointments page shows a Check-in button on today's appointment
and a contact-details card.

## Boundaries

FrontDesk may use Patients + Scheduling (models + services), Platform, and `Audit\Services` (not
`Audit\Models`). It must NOT depend on AiCore/Clinical/Nursing/Billing/Comms/Import
(`tests/Architecture/ModuleBoundariesTest.php`).

## Key classes / routes

- Models: `KioskDevice`. Services: `CheckInService`, `KioskCheckInService`, `KioskDeviceService`.
  Middleware: `IdentifyKioskDevice`. Controllers: `KioskCheckInController`, `PortalCheckInController`,
  `KioskDeviceController`.
- Routes: kiosk `kiosk.*` under `/kiosk/{kioskToken}` (guest + `kiosk-device`, resolve throttled); portal
  `portal.check-in` / `portal.check-in.contact`; admin `admin.kiosks.*`. Pages `Kiosk/CheckIn.vue`,
  `Admin/Kiosks.vue`, additions to `Portal/Appointments.vue`.
- Scheduling additions: `Appointment` check-in columns + `AppointmentService::arriveForPatient` +
  `BookingService` code generation. All additive; no existing page contract changed.

## Open items / deferred

- SMS/last-4-of-phone as an alternative to the code — the resolver already narrows on name+DOB+today+branch;
  add if a customer needs it.
- Reception-initiated check-in (source `reception`) — the day-board `arrive` action already covers staff
  check-in; the `reception` source value exists for completeness.
