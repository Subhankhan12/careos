# Portal batch — wireframe parity diff (11 screens, AUDIT ONLY)

**Audited 2026-08-22 at `4d50cba` (PC.P7), CI green.** Third domain batch, after Dental core (P1–P6) and
Patients & Clinical core (P1–P7). **No app code was changed by this audit.**

**Fixture verified BY QUERY** (Praxis Lindenhof): enrolled patient **Erika Baumgartner** (MRN-000001, active
portal account, `portal.access` + `comms.email` granted) with 1 appointment · 1 shared document · 2 issued
invoices · 2 message threads · 2 consents. **Second patient Viktor Odermatt** (MRN-000002, **3 issued
invoices**, no portal account) — his data must never appear on any portal screen, and is the control for every
disclosure-scope claim below.

---

## 0 — The headline

This is the **strongest-built batch of the three**. The disclosure fence — the thing that actually matters on a
patient-facing surface — is enforced in depth: three middlewares, fail-closed, plus a `WHERE patient_id = …`
on every id-bearing route, with identity taken from the session and never from a request parameter. Self-booking
runs through the real locked booking path. There is no interpretive content anywhere, no pay button, and no
recording affordance on telehealth.

**Four real findings, in descending order of seriousness:**

1. **Portal password reset does not exist** for patients. The inventory says it is live; it is not.
2. **Most portal reads are never audited**, so a patient's own access log (PC.P5) is largely blind to the
   portal — including the exact `portal_home` row the PC.P5 wireframe itself draws.
3. **`/portal/login` is in no smoke test** — the one public page a locked-out patient must reach.
4. **The invoices page sums its own open balance** while Home takes the same number from the server.

---

## 1 — The 11 screens

| # | Screen | Purpose (one line) | Auth? | Live route · Vue |
|---|---|---|---|---|
| 1 | **Portal Home** | Next appointment hero + unread/balance chips; balance is INFO ONLY, no pay button | **AUTH** | `GET /portal` · `Portal/Home.vue` |
| 2 | **Portal Appointments** | Upcoming + past, per-row cancel, and self-booking from live slots | **AUTH** | `GET /portal/appointments` (+ `slots`, `store`, `cancel`) · `Portal/Appointments.vue` |
| 3 | **Portal Documents** | Only explicitly shared documents — never the full chart | **AUTH** | `GET /portal/documents`, `/documents/{document}` · `Portal/Documents.vue` |
| 4 | **Portal Invoices** | Issued invoices + PDF download; no pay button anywhere | **AUTH** | `GET /portal/invoices`, `/invoices/{invoice}/pdf` · `Portal/Invoices.vue` |
| 5 | **Portal Messages** | Secure threads with the practice; reply only while open | **AUTH** | `GET /portal/messages`, `POST /portal/messages` · `Portal/Messages.vue` |
| 6 | **Portal Consents** | What the patient agreed to, and withdrawal with a recorded reason | **AUTH** | `GET /portal/consents`, `POST /consents/withdraw` · `Portal/Consents.vue` |
| 7 | **Portal Telehealth** | Join a video visit; nothing suggests recording | **AUTH** | `GET /portal/telehealth`, `POST /telehealth/{session}/token` · `Portal/Telehealth.vue` |
| 8 | **Portal Login** | The patient entry point; one generic failure line | **GUEST** | `GET /portal/login`, `POST /portal/login` · `Portal/Login.vue` |
| 9 | **Portal Invite** | Activate a practice-issued invite: token + one-time code + new password | **GUEST** | `POST /portal/accept-invite` only — **NO GET PAGE, NO VUE** |
| 10 | **Portal Password Reset** | Three-step patient recovery (request → check email → set new) | **GUEST** | **NO PORTAL IMPLEMENTATION** (see §4.7) |
| 11 | **Portal Sign Out** | A calm confirm before leaving the portal | **GUEST-ish** | `POST /portal/logout` — **no confirm interstitial page** |

**7 authenticated · 4 guest/pre-auth.** Eight authenticated routes exist in total — the wireframes cover seven
of them; `/portal/treatment-plan` and `/portal/check-in` are live with no wireframe (§5).

---

## 2 — Per-screen diff

| Screen | Deltas (mock → live) | Classify | Severity |
|---|---|---|---|
| **Portal Home** | Live has the three contract data points, all **server-derived** (`nextAppointment`, `unreadMessages`, `outstandingBalanceMinor`) and no pay button — the contract is kept. Mock's **v2 additions** are missing: prep reminder inside the hero, latest-message preview on the unread card, 4-tile quick-actions row (book / message / documents / telehealth). All are navigation + already-present data. · Page divides `outstandingBalanceMinor / 100` client-side (§4.6). · **The render is not audited** (§4.1). | (a) chrome · (b) audit gap | a: **Low** · b: **Med** |
| **Portal Appointments** | Live has both cards, per-row cancel, the server-enforced cancel window, slots capped at 12, and booking always for the session's patient. Missing: **leaf date tile hero**, proximity captions ("in 6 days", amber inside the cancel window), **day chips + morning/afternoon slot grouping**, live summary line above Confirm, add-to-calendar + directions. · Mock's "amber inside the cancel window" is a **tint keyed to proximity** — permissible only as the D-169 line allows (it reflects a *policy boundary*, not a clinical judgment), but the safer build states it in words as live already does. · **Not audited** (§4.1). | (a) chrome · (b) audit gap · (c) proximity tint — decide | a: **Low** · b: **Med** |
| **Portal Documents** | Live lists only `shared_with_patient = true` rows and streams downloads from the private disk with `nosniff` + a read-audit. Missing: **category filter pills with counts**, **search**, **month grouping** (This month / Earlier), the presentational **"New"** badge (shared within 7 days), and the reassurance footnote. All client-side over the same list. · LIST render not audited (only the download is). | (a) chrome · (b) audit gap | a: **Low** · b: **Med** |
| **Portal Invoices** | Live shows issued-only invoices with engine amounts + PDF download and **no pay button** — contract kept. Missing: **status filter pills with counts + dots**, the open-balance summary card, the "payment is handled at the practice" footnote. · **DEFECT: the open balance is `.reduce()`-summed in the page** while Home takes it from the server — two sources for one number (§4.6). | (a) chrome · (b) **money: one source** | a: **Low** · b: **Med-High** |
| **Portal Messages** | Live has threads, unread cleared on open, composer only while `status = open`, author labels, and — importantly — **staff-side AI provenance never crosses to the portal**. Missing: **thread-list previews**, timestamps per row, day separators ("Today · Friday 11 July"), the "only your own conversations appear here" line, and the urgent-call footnote. | (a) chrome | **Low** |
| **Portal Consents** | Live orders by granted date, shows status, requires a reason to withdraw (server re-validated), keeps snapshots immutable. Missing: **plain-language scope lines** with the raw key as a quiet chip, and the **two-step serious confirm for `portal.access`** spelling out the lockout consequence. · The `portal.access` withdrawal genuinely locks the portal on the next request (middleware) — the mock's warning is *true*, which is rare and worth keeping. | (a) chrome · (b) copy | **Low-Med** |
| **Portal Telehealth** | Live lists created/active sessions, issues a join token through a three-way fail-closed gate, never persists it, and has **no record affordance** — contract kept, and the "not recorded" line is the product, not marketing. Missing: **readiness checklist** (camera / mic / allow access) and the **"Opens 15 min before"** not-yet-open row. | (a) chrome | **Low** |
| **Portal Login** | Live is a standalone public page with one generic failure line and no self-registration — contract kept. Missing: clinic-name-leads treatment detail, the "access is by invitation" footnote. · **NOT COVERED BY THE GUEST SMOKE** (§4.2). | (a) chrome · (b) **smoke gap** | a: **Low** · b: **Med** |
| **Portal Invite** | `POST /portal/accept-invite` exists and works. **There is no GET page and no Vue component** — a patient clicking the emailed link has nowhere to land. The inventory maps this to `Auth/AcceptInvite.vue`, which is the **STAFF** invite (`/invite/{token}` → `StaffInviteAcceptController`). | **(b) backend-gap / inventory error** | **High** |
| **Portal Password Reset** | **Nothing patient-facing exists.** Fortify's `/forgot-password` + `/reset-password/{token}` use guard `web` and the **`users` password broker**; the `patient` guard uses provider `portal_accounts`, and `config/auth.php` defines **no portal broker**. The Vue pages `Auth/ForgotPassword.vue` / `Auth/ResetPassword.vue` contain no portal reference. A patient who forgets their password has **no self-service route** — they must ask the practice to re-invite. | **(b) backend-gap / inventory error** | **High** |
| **Portal Sign Out** | `POST /portal/logout` clears the session. The mock's **calm confirm interstitial** does not exist — sign-out is immediate. Low risk, but the mock's rationale (a stray tap on a shared device) is reasonable. | (a) missing page | **Low** |

---

## 3 — Shared components and shared backend gaps

### 3.1 Shared components — reuse vs new

**The staff shared components do NOT apply here, and that is correct.** S1 `PatientClinicalHeader`, N1
`ClinicalRailCard`, N3 `AccessLogRow` and N6 `SignOffBar` are **staff-facing by construction**: S1 renders a
patient's MRN, DOB, sex and *recorded allergies* on a dark clinical tile — a patient does not need to be shown
their own MRN and allergy list as an identity band, and the portal's design language is deliberately the
opposite (16px base, no dark tiles, clinic name leads, CareOS mark absent). **A patient-facing header is
genuinely a different component**, not a variant of S1. Do not extend S1 for the portal — that would repeat the
PC.P3 mistake in reverse.

| Ref | Component | Repeats across | Reuse or new |
|---|---|---|---|
| **P1** | `PortalLayout` (nav: Home · Appointments · Messages+badge · Documents · Invoices · Consents · Telehealth · avatar · Sign out) | All 7 auth screens | **EXISTS** — verify the nav set matches the mock (Telehealth appears in some mock headers, not others) |
| **P2** | **Public auth frame** — centred card on the wash, clinic name leads, no portal chrome | Login · Invite · Password Reset · Sign Out | **NEW (shared)** — one frame would serve all four guest screens |
| **P3** | **Filter pill row with counts** (`All 4 / Lab / Referral / Records`; `All / Open / Partially / Paid`) | Documents · Invoices | **NEW (shared)** — client-side over an existing list |
| **P4** | **Month/period grouping** (This month / Earlier) | Documents · (Messages day separators) | **NEW (shared)** |
| **P5** | **Empty state** with a suggestion rather than a dead end | All 7 auth screens | **EXISTS** in places — worth unifying |
| **P6** | **Serious two-step confirm** (reason required, consequence in plain words) | Consents (`portal.access`) · Appointments (cancel) | **NEW (shared)** — the portal's own "clay moment" |
| **P7** | **Date/proximity caption** ("in 6 days", "tomorrow") | Appointments · Home | **REUSE THE SERVER FORMULATION** — PC.P2/PC.P7's `due_in_days` plain-interval pattern, computed server-side |

### 3.2 Shared backend gaps — one fix unlocks several

| Ref | Gap | Unlocks | Effort |
|---|---|---|---|
| **B1** | **Portal reads are not audited.** Only the document download, invoice PDF download, message-thread open and telehealth token issue write an `action='read'` row. Home, the Documents list, the Invoices list, Appointments, Consents and Check-in write **none**. | **All 7 auth screens** + closes the hole in PC.P5's access log. The PC.P5 wireframe *itself* draws a `portal_home · patient` row that the live build never writes. | **Low** — one `auditRead()` per controller, the pattern PC.P5/P6/P7 already established |
| **B2** | **No portal password reset.** No `portal_accounts` password broker, no routes, no pages. | Portal Password Reset (screen 10); removes a support burden (today: re-invite) | **Med** — a second broker + signed single-use token + 3 pages; security-sensitive |
| **B3** | **No portal invite landing page.** `POST /portal/accept-invite` has no GET counterpart. | Portal Invite (screen 9) | **Low-Med** — one GET route + one Vue page over the existing POST |
| **B4** | **`/portal/login` not in the guest smoke.** | Protects screens 8–11 from shipping a public 500 | **Trivial** — one line in `RouteSmokeTest` |
| **B5** | **Invoice open balance is summed in the page.** | Invoices + Home agree by construction | **Low** — emit the figure server-side, delete the `.reduce()` |
| **B6** | **No server-side proximity/interval field** for portal appointments. | Appointments + Home captions | **Low** — the PC.P2 `due_in_days` pattern |

---

## 4 — THE FENCE VERIFICATION (the most important section)

### 4.1 Disclosure scope — **enforced, with one real gap (audit, not access)**

**Access scoping is enforced, not implicit, on every screen.** Three middlewares wrap every authenticated
portal route (`routes/web.php`):

- `portal-tenant` (`IdentifyTenantFromPortalSession`) — resolves the tenant from the **session**, 403s a
  missing or **suspended** tenant.
- `portal-auth` (`EnsurePatientPortalAuthenticated`) — `Auth::guard('patient')` or redirect/401.
- `portal-consent` (`EnsurePortalConsent`) — the account must be a `PortalAccount`, its `tenant_id` must match
  the resolved tenant, the patient must exist, **and `portal.access` consent must be held** — otherwise **403**.

*Note:* if `portal_tenant_id` is absent from the session, `portal-tenant` passes through without setting a
context — but `portal-consent` then compares the account's tenant to `null` and 403s. **Fail-closed overall**,
though the tenant middleware alone does not abort. Worth stating; not a defect.

**Per-screen enforcement — every id-bearing route scopes in a WHERE clause:**

| Screen | How scoping is enforced | Could a forged id reach another patient? |
|---|---|---|
| Home | No id; aggregates from `$account->patient_id` | **No** |
| Appointments | Patient from session; booking passes `$account->patient_id`, never a request param | **No** |
| Documents | `DocumentService::portalDocument()` → `where('patient_id', $account->patient_id)->where('shared_with_patient', true)` | **No** — 404 |
| Invoices | `where('patient_id', $account->patient_id)->whereNotNull('number')->whereNotNull('pdf_path')` | **No** — 404 |
| Messages | `ThreadService::assertPatientAccess()` — patient thread **and** `patient_id` match **and** an active `ThreadParticipant` row | **No** — 403 |
| Telehealth | `joinTokenForPatient()` — same tenant, account **active**, `account->patient_id === session->patient_id`, **and** `portal.access` re-checked | **No** — 403 |
| Consents | Patient from session | **No** |

**Identity always comes from the session.** This is the single most important property on the batch and the
live build holds it everywhere.

**THE GAP — most portal reads write no audit row.** Audited today: document download
(`portal_document_download`), invoice PDF download (`portal_invoice_download`), message thread open
(`comms_thread_portal`), telehealth token (`telehealth_token`), treatment plan (`portal_treatment_plan`).
**Not audited:** Portal Home, the **Documents list** (titles, categories, dates of everything shared), the
**Invoices list** (numbers, totals, open balance), **Appointments**, **Consents**, **Check-in**.

Consequence: a patient's own access log — the screen PC.P5 built to answer *"who accessed my data"* — is
largely blind to the portal. And the PC.P5 wireframe explicitly draws the row that is missing:
*"Nora Keller viewed her own summary · portal_home · patient"*. **Classification: backend-gap (B1).** Not a
disclosure breach — a transparency hole, and per D-178 a transparency surface that silently omits a category of
read is the failure mode that matters.

### 4.2 Guest / pre-auth surfaces — **one uncovered**

Guest routes this batch implies, against `AUTH-SEC.2`'s guest smoke (`RouteSmokeTest`):

| Guest route | In the guest smoke? |
|---|---|
| `/login` (staff) | ✅ |
| `/forgot-password` | ✅ |
| `/reset-password/{token}` | ✅ |
| `/invite/{token}` (staff invite) | ✅ |
| `/book/{tenant}` (public booking) | ✅ (asserted separately, anonymous) |
| **`/portal/login`** | ❌ **NOT COVERED** |
| `POST /portal/accept-invite` | ❌ (POST-only; no page to smoke — see B3) |

**`/portal/login` is the patient's entry point and is in no smoke test.** AUTH-SEC.2 exists precisely because
`/forgot-password` sat returning 500 unnoticed; the same class of regression could ship on the portal login
today. **Classification: backend-gap (B4), trivial to close.**

### 4.3 Advice to a patient — **clean; nothing to refuse**

This is the axis I expected to produce refusals, and it produced none. Across all 11 mocks there is **no**
result interpretation, **no** normal/abnormal label, **no** reference-range flag, **no** triage or
"should you come in" suggestion, **no** symptom checker, and **no** computed risk. Specifically:

- **Documents** shows lab reports as **files with a title and date** — never a value, never a range, never a
  flag. A lab PDF is disclosed as the practice shared it; the portal does not read it. Correct by construction.
- **Messages** — the mock states outright that staff-side **AI provenance (✦) never crosses to the portal**,
  and that the 09:12 reply renders as a plain practice message. There is no agent-drafted patient reply anywhere
  in the batch. The live `DraftRecallMessageTool` (SUGGEST ceiling, human sends — the PC.P7 finding that **the
  ceiling is what makes it safe**) is the only patient-message drafting path, and it is staff-side.
- **Telehealth** — "This call is not recorded" with **no record affordance anywhere**, on both patient and
  staff frames.
- **Home** — the balance is "INFO ONLY, no pay button"; at zero it reads "No payment due".

**Classification: correctly-more-real / nothing to build.** The one thing to hold: if a future gate surfaces
lab *values* (not PDFs) in the portal, they must be displayed exactly as recorded, with **no** range flag —
that is where this line would first be tested.

### 4.4 Self-booking — **fully guarded by the live build**

- Service must be `active` **and** `bookable_online` (both `slots` and `store`).
- Branch must pass `Branch::onlineBookable()`; a soft-suspended branch returns **an empty slot list**, not an
  error — BRANCH.P1 honoured.
- Slots come from the real `AvailableSlotFinder::forServiceBranchDate(..., 12)` — capped, server-side. **No
  page-side slot list.**
- Booking calls `BookingService::bookOnline()` → `createBooking(..., SOURCE_ONLINE)`, which **re-checks**
  `branch->active && accepts_online_bookings` (line 125) and then, per resource, `lockResource()` +
  `assertNoOverlap()` (lines 176–177). **A patient cannot double-book.**
- The patient id is `$account->patient_id` — from the session, never the request.

**Classification: correctly-more-real. Nothing to fix.** Any future portal booking UI must keep going through
`bookOnline`; a page-side write would bypass the lock.

### 4.5 Consent — **enforced at the middleware, which is the strongest place for it**

- `portal.access` is checked by `EnsurePortalConsent` on **every** authenticated portal request. Withdrawing it
  **locks the portal on the next request** — so the mock's stern warning ("you won't be able to sign back in")
  is *literally true*, which is unusual and worth preserving verbatim.
- Withdrawal **requires a reason** (client blocks empty, server re-validates, max 500) and **snapshots stay
  immutable** — the withdrawn row keeps the record of what was agreed.
- `comms.email` consent gates **outbound** messaging: `DraftRecallMessageTool` returns
  `blocked_no_comms_consent` rather than producing a message.
- **Nuance, not a defect:** `postPatientMessage()` does not check comms consent. That is correct — an *inbound*
  message from patient to practice is not an outbound communication, and the portal itself is already gated by
  `portal.access`.

**Classification: correctly-more-real.** No consent UI in this batch records nothing.

### 4.6 Money — **no payment path (correct); one real one-source defect**

- **No pay button and no payment write anywhere in the portal** — PSP deferred, stated in the controller
  docblock and in the mock. Nothing routes around `PaymentService`. Correct.
- Invoices are **issued-only** (`whereNotNull('number')`), amounts come from the engine
  (`total_minor`, and `open_balance_minor` from the `invoice_balances` projection). The frozen legal row is
  never mutated.
- **DEFECT:** `Portal/Invoices.vue:44` computes the open balance with
  `.reduce((sum, i) => sum + i.open_balance_minor, 0)` — **page-side arithmetic** — while `Portal/Home.vue`
  receives `outstandingBalanceMinor` **from the server**. Two independent sources for the same number, on two
  screens the patient sees minutes apart. This is exactly the defect DENTAL-B.P4 removed from the treatment
  plan ("the page now does no money arithmetic at all") and PC.P2 removed from the chart counts.
- Minor formatting divides (`/100`, `.toFixed(2)`) appear on `Home.vue:50`, `Invoices.vue:49`,
  `TreatmentPlan.vue:27`. Lower severity than the sum, but the DENTAL-B.P4 precedent is server-formatted
  strings.

**Classification: backend-gap (B5) for the sum; chrome for the divides.**

### 4.7 The two inventory errors (both High)

| Claim in `WIREFRAME-INVENTORY.md` | Reality |
|---|---|
| *Portal Password Reset — LIVE · `forgot-password, reset-password/{token}` · `Auth/*`* | Those routes are **Fortify's**, bound to guard `web` and the **`users`** password broker. The `patient` guard uses provider `portal_accounts`; **`config/auth.php` defines no portal broker**. `Auth/ForgotPassword.vue` and `Auth/ResetPassword.vue` contain **zero** portal references. **A portal patient has no password reset at all.** |
| *Portal Invite — LIVE · `invite/{token}` · `Auth/AcceptInvite.vue`* | `/invite/{token}` is the **STAFF** invite (`StaffInviteAcceptController`). The portal's accept is **`POST /portal/accept-invite` only** — no GET route, no Vue page. |

Both rows should be corrected to **NO LIVE PAGE** when the inventory is next touched. Recorded here rather than
edited, because this task is audit-only.

---

## 4a — PT.P1 outcome (2026-08-22) — B1 + B4 CLOSED

**The `portal_home · patient` row the PC.P5 wireframe draws is now real.** Six portal surfaces write one
read row per render through the EXISTING `auditRead()` path — `portal_home`, `portal_appointments`,
`portal_documents`, `portal_invoices`, `portal_consents`, `portal_checkin`. The subject is the **patient**
(`Patient::auditPatientId()` returns its own id), which is what makes the rows selectable by PC.P5's query
(`action='read' AND patient_id = ?`) — the same shape `patient_360`, `referrals` and `recall_worklist`
already use. **No disclosure, scoping, query or behaviour changed**; the per-document and per-invoice
DOWNLOAD rows, the message-thread open and the telehealth token issue already audited and were left
untouched, so nothing double-audits.

**Proven end to end in a browser**, which is also how the actor-type question was settled: signed in as the
real portal patient, visited the five page surfaces, then opened the patient's access log as staff. All five
appear **on screen and in the CSV export** (one query, so they cannot disagree), and the actor-type chip
reads **"Patient (self) · 6"**.

**A test artifact worth recording.** The suite first reported `actor_type = user` for portal reads.
`actingAs($account, 'patient')` also calls `shouldUse('patient')`, so `Auth::user()` resolves the
PortalAccount — and `PlatformAuditContext::actor()` checks `Auth::user()` FIRST. In production the default
guard stays `web`, `Auth::user()` is null, and the context falls through to the patient branch. The browser
settled it (`actor_type=patient`, six rows) and the test now drives the guard the way a real request does,
via `Auth::guard('patient')->setUser()`. **The assertion was right; the way the test drove the app was
wrong** — worth remembering before bending an assertion to make a suite pass.

**`/portal/login` is now in the AUTH-SEC.2 guest smoke, and it was proven to bite:** breaking the route's
resolution turned the guest smoke red with `guest.portal.login [/portal/login] -> 500`, then it was
restored. The guest-route list was **EXTENDED, never relaxed** — one more route to satisfy.

**And a CI failure of my own, recorded rather than smoothed over.** The first PT.P1 commit went RED on CI
while local was green: `ptaRows()` matched the audit JSON as raw bytes
(`LIKE '%"surface":"portal_home"%'`). **MySQL 8 re-serialises a JSON column on insert** — it stores
`{"surface": "portal_home"}`, with a space — while local MariaDB keeps the bytes it was given, so the
pattern matched everything here and nothing there. The rows were always being written; the browser proof
above was taken against a real server and stands. All three lookups now DECODE the JSON, and the failure
became a permanent guard: a test writes the same fact under both spellings and requires both to be counted,
so a byte-matching helper now fails on ANY engine rather than waiting for CI.

**A mutation of mine that proved nothing, caught and fixed.** "Audit the invoices row against another
patient" was written as `Patient::orderBy('id')->first()` — with ULIDs that resolves back to the viewer, so
the mutation was a **no-op** and its passing meant nothing. Rewritten as
`where('id', '!=', $account->patient_id)`, it turns the suite red. The guard was also strengthened while
investigating: the control patient is now asserted empty **per surface**, not just once at the end.

---

## 5 — Correctly-more-real — keep, do not trim

1. **Three-layer middleware gating** on every portal page, with `portal.access` consent enforced server-side —
   stronger than any mock states.
2. **`shared_with_patient = true`** on documents: the portal cannot show an unshared document even by id.
3. **Issued-only invoices** (`whereNotNull('number')`) — a draft invoice cannot leak to a patient.
4. **The telehealth three-way gate** (tenant · active account · patient-of-session · consent re-checked) and a
   token that exists only in the response — never persisted, echoed or logged.
5. **`ThreadParticipant`-based message access** — membership, not just a foreign key.
6. **`nosniff` + private-disk streaming** on both document and invoice downloads.
7. **Two live portal pages the wireframes never drew:** `/portal/treatment-plan` (DENTAL.G5, read-only, no
   lifecycle actions, no payment) and `/portal/check-in` (P0P.G7 self check-in). Keep; they are real features.
8. **No self-registration** — invite-only, by contract and in code.

---

## 6 — Proposed fix chain

| Gate | Builds | Proves |
|---|---|---|
| ~~**PT.P1**~~ ✅ **DONE** | **B1 + B4 — the audit + smoke gap.** One `auditRead()` per unaudited portal surface (Home, Documents list, Invoices list, Appointments, Consents, Check-in), and `/portal/login` added to the guest smoke. | Every portal read appears in the patient's own access log (PC.P5), one row per render, no second audit path. A public 500 on the portal entry point can no longer ship green. **Do this first — it is the cheapest and closes the transparency hole.** |
| **PT.P2** | **Shared portal chrome (P2/P3/P4/P5/P6) + B5/B6.** The public auth frame, filter pills with counts, period grouping, unified empty states, the serious two-step confirm; the invoice balance moved server-side and the appointment proximity interval computed server-side. | One patient-facing frame, not four; Home and Invoices agree by construction; no page-side money arithmetic. |
| **PT.P3** | **Portal Home + Appointments parity** — hero, prep reminder, quick-actions row, leaf date tile, proximity captions, day chips + morning/afternoon grouping, confirm summary line. | Server-derived data only; the cancel window stays server-enforced; **decide explicitly** whether the amber in-window tint is built (a policy boundary, not a clinical judgment) or stated in words. |
| **PT.P4** | **Portal Documents + Invoices + Messages parity** — filters, search, grouping, "New" badge, thread previews/day separators, the footnotes. | Only shared documents; issued-only invoices; **no pay button**; AI provenance still never crosses to the portal. |
| **PT.P5** | **Portal Consents parity** — plain-language scope lines with the raw key as a chip, and the two-step `portal.access` confirm with the lockout consequence. | Reason still required and recorded; snapshots immutable; the warning stays literally true. |
| **PT.P6** | **B3 — the portal invite landing page.** GET route + Vue over the existing POST. | Invite-only enrolment actually completes from an emailed link; generic failure for invalid/expired/used. |
| **PT.P7** | **B2 — portal password reset.** A `portal_accounts` broker, signed single-use time-boxed token, the three steps, session invalidation on success, no account enumeration. | Security-sensitive: same generic response either way; guest routes smoked. **Consider pairing with a security review.** |
| **PT.P8** *(optional)* | **Portal Telehealth readiness + Sign-out interstitial.** | No record affordance appears; the checklist derives from the same session list. |

**Realistic gate count: 7 core + 1 optional.**

**Recommended order note:** PT.P1 before any visual work — it is small, it closes a transparency gap the PC.P5
wireframe itself expects to be closed, and it removes a real smoke blind spot. PT.P7 last and separately: a
password-reset broker is the one piece of this batch where a mistake is a security incident rather than a
cosmetic miss.

**Nothing in this batch is recommended for deferral** — unlike Dental (4 no-page screens) and Patients &
Clinical (4 deferred), every Portal screen is either already live or a small, well-understood gap. That is a
consequence of the portal having been built as a coherent vertical rather than screen by screen.
