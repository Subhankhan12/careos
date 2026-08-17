# OPERATOR MODE — feature MAP + security model + gated build plan

**Design and investigation only. ZERO app code was changed by this map.** This is map-first discipline (the
same rule every vertical followed) applied to a **net-new, security-sensitive super-admin privilege-escalation
subsystem**. A privilege-escalation feature earns the map most: `Waiting On Approval` is ONE screen of a
**14-state, 2-arm** family, and building screens in isolation would be incoherent and dangerous.

- **Date:** 2026-08-17 · **HEAD:** `df2866b` (the memory reconciliation) · **CI:** `check -> completed / success`.
- **Decoded wireframes:** `resources/prototype/operator-*.wireframe.html` (15 files, **gitignored**).
- **Status:** **NOT APPROVED FOR BUILD.** This document ends in OPEN DECISIONS for the product owner,
  including whether Operator Mode is in scope for the first deployment at all.

---

## 0. Why this document exists before any gate

The feature's entire job is to **give a cross-tenant platform operator access inside a live tenant** — including,
in its top tier, **patient records**. In a system whose first hard rule is fail-closed tenancy, that is the single
most dangerous subsystem anyone could add. Two facts make the map mandatory rather than nice-to-have:

1. **The screens are a state machine, not a set of pages.** The design pack's own hub calls it *"14 states · 2
   arms"*. Nine of the fifteen screens are **outcomes** (granted / downgraded / declined / expired / revoked /
   extended / ended). Building any one without the state machine behind it would render a state the server
   cannot actually enforce.
2. **The current codebase would make a naive build catastrophically unsafe** — see §3.1. `Gate::before` grants a
   super-admin **every permission unconditionally**, and the only thing containing them today is that they have
   **no tenant context**. Operator Mode's core action is *to set a tenant context for a super-admin*. Wire that
   up without first fixing the bypass and you have handed the platform operator unlimited, unscoped, untimed,
   unaudited access to every clinic's patient data. **The security core must land before any screen.**

---

## 1. Screen inventory — who acts, and what state it represents

Decoded from the design pack via the standard pipeline (`__bundler/manifest` → base64 → gunzip → UUID-substitute
→ headless render → strip loader → hosted fonts). All 15 rendered with **zero console errors**.

### 1.1 The platform console (context — where sessions start)

| # | Screen | Actor | State it represents |
|---|---|---|---|
| 1 | **Super-Admin Tenants** `operator-superadmin-tenants` | Platform operator | Cross-tenant list: 48 tenants, health/plan/activity, filters (Active/Trial/Suspended/Provisioning). Caption: *"cross-tenant reads are logged"*. No tenant data. |
| 2 | **Super-Admin Tenant Detail** `operator-superadmin-tenant-detail` | Platform operator | One clinic **from the outside** — subscription, usage vs plan limits, compliance posture, admin contacts, a lifecycle zone (suspend/export/offboard) that *"never destroys the audit history"*, and an **OPERATOR ACCESS LOG** of every past entry. Explicit: *"No patient record is reachable here."* Carries the privileged **Enter operator mode** action. |

### 1.2 The READ-ONLY arm — self-granted, no approval

| # | Screen | Actor | State |
|---|---|---|---|
| 3 | **Enter Operator Mode Confirm** `operator-enter-confirm` | Platform operator | **The governance gate.** Required reason (preset chips: Billing investigation / Onboarding support / Incident response / Config request, or typed — *"recorded verbatim"*); **three tiers** (see §1.5); session length **15/30/60 min**; a mandatory acknowledgement checkbox that the session logs to the tenant's ledger. Primary button restates the grant: *"Enter as read-only · 30 min"*. |
| 4 | **Operator Mode Banner** `operator-mode-banner` | Platform operator | **Live read-only session.** Persistent slate band over the clinic's own UI: who is in, scope, reason, live countdown (`27:11`), `Extend`, `Exit operator mode`. Write actions (adjust/refund, change plan, update card) **visibly disabled**. |
| 5 | **Operator Session Ended** `operator-session-ended` | Platform operator | **Receipt.** How it ended (auto-exit, time-box reached), start/end/duration, **every page viewed**, a `0 changes made` badge, and **one tamper-evident row** written to the tenant's ledger (entry id `OPS-2026-0712-0087`, `sha256:3f9a…c1d2`), *"visible to the tenant's admins, not only to the platform."* |

### 1.3 The ELEVATED arm — patient records, owner must approve

| # | Screen | Actor | State |
|---|---|---|---|
| 6 | **Owner Approval Request** `operator-owner-approval-request` | **Both** (2-step) | Step 1 **operator**: justification (*"shown to the owner and recorded verbatim"*), **records minimised** — `Specific records only` (PT-4471/4472/4488, tied to ticket #SYNC-4471) vs `All patient records` (*"Rarely justified — expect the owner to decline"*), shorter time-box (15/30 only), named recipient, confirmation checkbox. Step 2 **owner**, in their own CareOS: verified requester, amber patient-data flag, reason, exact records, time-box, guarantees list, and three actions — **Approve full · 15 min / Approve read-only instead / Decline**. |
| 7 | **Owner Notification** `operator-owner-notification` | **Tenant owner** | The request lands **in the clinic's own CareOS** notification centre as the one `Action needed` item, with a live `expires in 23:45`, alongside ordinary items (a patient message, a booking, a paid invoice). Also sent by **Email + Push**. *"on their turf, easy to act on or ignore until it expires."* |
| 8 | **Waiting On Approval** `waiting-on-approval` *(decoded previously)* | Platform operator | **Requester's holding screen.** Pending pill, approver card (*"Notified 14:36 · not yet opened"*), live **request-expiry** countdown, 4-step timeline (sent → delivered → awaiting decision → access opens on approval), request recap, and `Start a read-only session now` / `Nudge owner` / `Cancel request`. Honest line: *"Nothing is accessible yet."* |

### 1.4 The five outcomes + the extension

| # | Screen | Actor | State |
|---|---|---|---|
| 9 | **Elevated Session Banner** `operator-elevated-session-banner` | Platform operator | **APPROVED — live elevated.** Amber caution rail; `Full support · patient records`; `Approved by Dr. Vogt` provenance chip; shorter clock (`13:17` of a 15-min box); `Request more time` (not self-serve `Extend`); *"Every record you open is logged"*, *"the owner can revoke anytime"*. **The grant is proven on screen: only the 3 approved records are open; `+ 1,240 more records — all locked for this session`.** |
| 10 | **Owner Granted Read-Only** `operator-owner-granted-readonly` | Platform operator | **DOWNGRADED.** The owner countered with less. Requested-vs-granted shown side by side, the owner's note verbatim, and explicit `What you can see` (booking history, send logs, service catalog, message **metadata**) vs `What stays closed` (patient records & clinical notes, **message contents**, invoice/document contents, **any write/edit/export**). |
| 11 | **Request Declined** `operator-request-declined` | Platform operator | **DECLINED.** Owner's reason verbatim (constructively: they'll send a redacted export). *"No access granted · nothing was read · patient data stays with the clinic."* Paths: revise & resend, start read-only instead, message the owner. |
| 12 | **Request Expired** `operator-request-expired` | Platform operator | **LAPSED.** Deliberately distinct from a decline: no decision in the window (sent 14:36 → expired 15:06). *"This isn't a refusal."* *"Requests expire on their own so stale approvals can't sit open."* |
| 13 | **Owner Revoked Mid-Session** `operator-owner-revoked-midsession` | **Tenant owner** (felt by the operator) | **REVOKED.** A hard interrupt: stopped clock (`04:12 of 15:00`), who revoked and when, the owner's note, and audit honesty — *"You opened 2 of 3 records … PT-4488 never opened"*. *"Access ended instantly."* No reason required. |
| 14 | **Session Extended** `operator-session-extended` | **Both** | **EXTENDED.** On patient data an extension is **not self-served**: operator requests with a reason, owner re-approves, and only then does the clock grow (15 → 30 min) with the original-expiry marker kept so it reads as an extension, **not a reset**. Same scope, still auto-exits, still revocable, and it **writes its own ledger row**. |
| 15 | **Operator Mode Hub** `operator-hub` | Platform operator | The capstone map — *"14 states · 2 arms · least privilege throughout"*. Authoritative source for the state machine in §2. |

### 1.5 The tenant-side audit view

| # | Screen | Actor | State |
|---|---|---|---|
| 16 | **Patient Access Log** `operator-patient-access-log` | **Tenant staff** | Per-record read-audit: *"who opened it, when, on what basis · care-team reads stay quiet, **operator-mode & agent reads are named and scoped**"*. Header counts `23 people · 3 agent reads · **1 operator-mode read** · 4 patient self-views`. Route caption: `GET · gate patient.audit.view · read_audit rows are append-only & immutable · viewing this log itself writes one`. |

### 1.6 THE THREE TIERS (load-bearing — this corrects an earlier reading)

The `Enter Operator Mode Confirm` screen defines **three** tiers, not two:

| Tier | Approval | What it opens | Patient records |
|---|---|---|---|
| **Read-only** *(pre-selected, "Recommended")* | **Self-granted** | Configuration, settings, usage | **NO** |
| **Configuration** | **Self-granted** | Change settings + agent configuration | **NO** |
| **Full support** | **Owner approval required** | Adds patient-record access, minimised to named record ids | **YES** |

**This materially changes the "read-only before approval" question** raised when `Waiting On Approval` was first
decoded. The design does **not** propose unapproved access to PHI. Its self-granted tiers are explicitly
`No patient records`; the owner gate sits precisely and only on patient data. That is a defensible line — but it
is still a **decision to ratify, not a given** (§4.3), and note that **`Configuration` is a self-granted WRITE
tier**, which is its own escalation worth scrutiny.

---

## 2. The state machine

```
                                  ┌───────────────────────────────┐
                                  │  PLATFORM CONSOLE (no tenant  │
                                  │  data reachable) — Tenants /  │
                                  │  Tenant Detail                │
                                  └───────────────┬───────────────┘
                                    operator picks a tenant + tier
                                                  │
                    ┌─────────────────────────────┴─────────────────────────────┐
                    │                                                           │
        ══ READ-ONLY ARM ══ (self-granted, NO patient data)      ══ ELEVATED ARM ══ (patient data)
                    │                                                           │
      [1] REQUESTED_SELF                                          [5] REQUESTED_PENDING
      operator states reason + tier(read-only|config)             operator: justification + record ids
      + time-box + accepts the log                                + tier(full) + short time-box
                    │  operator                                              │  operator
                    ▼                                                        ▼
      [2] ACTIVE ──────────── operator: Extend (self, ≤cap) ──┐    [6] OWNER_NOTIFIED  (in-app+email+push)
      live, scoped, ticking                                   │              │
            │                                                 └──────────────┤ owner opens it
            ├── operator: Exit now ────────────┐                             ▼
            ├── SERVER: time-box reached ──────┤                  [7] AWAITING_DECISION  ⏳ request TTL
            └── owner: Revoke ────────────────┐│                             │
                                              ││        ┌──────────┬─────────┼──────────┬────────────┐
                                              ││   owner│Approve   │Approve  │Decline   │  (nobody)  │
                                              ││        │full      │read-only│          │  SERVER    │
                                              ││        ▼          ▼         ▼          ▼            │
                                              ││  [8] APPROVED  [9] DOWN-  [11] DECLINED [12] EXPIRED│
                                              ││      _FULL         GRADED       ⛔ terminal  ⛔ terminal
                                              ││        │            │
                                              ││        │            └──► joins ACTIVE (read-only tier)
                                              ││        ▼
                                              ││  [10] ELEVATED_ACTIVE  (scope = named record ids)
                                              ││        │
                                              ││        ├─ operator: Request more time ─► [13] EXTENSION_PENDING
                                              ││        │        owner re-approves ──► clock grows, SAME scope,
                                              ││        │        owner declines  ──► stays on original clock
                                              ││        ├─ operator: Exit now ──────────────┐
                                              ││        ├─ SERVER: time-box reached ────────┤
                                              ││        └─ owner: Revoke (instant) ─────────┤
                                              ▼▼                                            ▼▼
                                       ┌────────────────────────────────────────────────────────┐
                                       │  [14] ENDED  ⛔ terminal — the single receipt state     │
                                       │  reason ∈ {exited, expired, REVOKED}                   │
                                       │  writes ONE tamper-evident row to the TENANT's ledger  │
                                       └────────────────────────────────────────────────────────┘
```

### 2.1 States (14) and who triggers each transition

| # | State | Entered by | Leaves via | Terminal |
|---|---|---|---|---|
| 1 | `REQUESTED_SELF` | Operator (confirm modal) | auto → `ACTIVE` | |
| 2 | `ACTIVE` (read-only / configuration) | Server, on self-grant | operator Exit · **server** time-box · **owner** Revoke | |
| 3 | `EXTENDED_SELF` (read-only arm only) | Operator, within a cap | back to `ACTIVE` with a longer clock | |
| 4 | *(console)* | — | — | |
| 5 | `REQUESTED_PENDING` | Operator (full-support request) | → `OWNER_NOTIFIED` | |
| 6 | `OWNER_NOTIFIED` | Server (notify owners) | owner opens → `AWAITING_DECISION` | |
| 7 | `AWAITING_DECISION` | Owner opening it | owner decides · **server** request-TTL · **operator** Cancel | |
| 8 | `APPROVED_FULL` | **Owner** | → `ELEVATED_ACTIVE` | |
| 9 | `DOWNGRADED_READONLY` | **Owner** | → `ACTIVE` (read-only) | |
| 10 | `ELEVATED_ACTIVE` | Server, on approval | operator Exit · **server** time-box · **owner** Revoke | |
| 11 | `DECLINED` | **Owner** | — | ⛔ |
| 12 | `EXPIRED` | **Server** (request TTL) | — | ⛔ |
| 13 | `EXTENSION_PENDING` | Operator asks; **owner** decides | approve → longer clock · decline → unchanged | |
| 14 | `ENDED` | Operator / **server** / **owner-revoke** | — | ⛔ |

**Terminal states: `DECLINED`, `EXPIRED`, `ENDED`** (with `ENDED.reason ∈ {exited, expired, revoked}` so a revoke
is distinguishable in the ledger). Note the asymmetry the design is careful about: **decline** (an active "no"),
**expire** (nobody answered), and **revoke** (access cut mid-flight) are three different things and must remain
three different recorded outcomes.

### 2.2 Two clocks, not one

A recurring source of security bugs would be conflating them:

- **REQUEST TTL** — how long the *ask* stays open awaiting a decision (`expires in 23:45`, "expired 15:06 · 30 min").
  Expiry here grants **nothing**.
- **SESSION TTL** — how long an *approved/self-granted* session lives (15/30/60 min; 15/30 for patient data).
  Expiry here **ends access**.

---

## 3. Exists vs net-new

### 3.1 ⚠️ THE CRITICAL FINDING — the super-admin bypass makes a naive build unsafe

`Modules/Platform/src/Providers/PlatformServiceProvider.php`:

```php
Gate::before(function ($user, string $ability, array $arguments = []) {
    if ($user->isSuperAdmin()) {
        return true;          // ← EVERY permission, unconditionally, forever
    }
    ...
});
```

`Modules/Platform/src/Concerns/TenantScope.php`:

```php
if ($context->has())          { constrain to that tenant; return; }
if ($context->inSystemMode()) { return; }                 // unconstrained
throw TenantContextMissingException::forQuery($model);    // fail-closed
```

`IdentifyTenantFromUser` sets a context **only for tenant staff**; a super-admin *"is left empty (they operate via
system mode / platform scope)"*.

**Therefore, today:** a super-admin already passes every permission check, and the **only** thing preventing them
from reading any clinic's patient data is that they have **no tenant context**, so every `BelongsToTenant` query
**throws**. Containment is an emergent side effect, not an access-control decision.

**Operator Mode's core action is to set a tenant context for a super-admin.** Doing that on today's code yields:
no grant required, no scope, no tier, no expiry, no revoke, no audit — **unlimited access to every record in that
clinic**. This is not a theoretical risk; it is the direct consequence of the two snippets above.

> **Non-negotiable consequence for the build order:** the **grant-gated access invariant must land in G1, before
> any tenant context is ever set for an operator, and before any screen exists.** `Gate::before` must stop being
> an unconditional bypass and become *"super-admin ⇒ only what an active, approved, unexpired, in-scope,
> in-tier grant permits."*

### 3.2 What exists and can be reused

| Capability | Status | Where |
|---|---|---|
| Super-admin actor (`tenant_id = null`) | **Exists** | `User::isSuperAdmin()`, `EnsureSuperAdmin` middleware |
| Platform shell route | **Stub only** | `routes/web.php` → `/admin` → `Inertia::render('Admin/Landing')` |
| Fail-closed tenancy | **Exists, strong** | `TenantScope` throws; `TenantContext::set/forget/system()` |
| Append-only hash-chained audit | **Exists, strong** | `AuditService::record()` + `verifyChain()`; DB triggers block UPDATE/DELETE — gives the *"tamper-evident row · sha256"* for free |
| Per-record access log UI | **Exists (partial)** | `PatientShowController::accessLog()` + `Patients/Show.vue` access tab |
| Notification to a staff user | **Exists** | `NotificationService::send(templateKey, Patient|User $recipient, …)` — **email only** |
| Time-boxed grant + expiry-at-access-time | **Exists (wrong model)** | `BreakGlassGrant` — see §3.4 |
| Reason-required + audited privileged action | **Exists (pattern)** | `BreakGlassService`; also the ARDETAIL.P6 Betreibung operator-gate pattern |

### 3.3 What is net-new

| Net-new | Notes |
|---|---|
| **The grant-gated access invariant** | §3.1 — the whole security core. Must replace the unconditional `Gate::before` bypass. |
| **Pending-approval model** | No `pending` state exists anywhere in access control. |
| **The approver ("owner")** | Only `org_admin` exists in `ROLE_TEMPLATES`; there is **no `owner` role**, and the wireframe implies **multiple owners** (*"+ 1 other owner can approve"*). Mapping owner → org_admin, or adding a distinct owner concept, is an OPEN DECISION (§7). |
| **Cross-tenant actor with a scoped context** | Today a super-admin has *no* context; Operator Mode needs a **bounded, grant-derived** one. |
| **TIER** (read-only / configuration / full) | No tier concept exists on any grant. |
| **SCOPE down to record ids** | `BreakGlassGrant.scope` is a single string (`'patient:<id>'`); the design needs a **set** of record ids plus category-level scoping (see the granted-read-only screen's see/closed lists). |
| **REQUEST TTL** (distinct from session TTL) | Only a grant expiry exists. |
| **Owner notification + decision** | Templates, an in-app action-needed item, and the approve/downgrade/decline actions. |
| **Mid-session REVOKE** | Nothing revokes an active grant today. |
| **Extension with re-approval** | Including "never a silent reset". |
| **`actor_type = 'operator'`** | Current values are `user · agent · patient · system · service`. An operator read must be distinguishable in the tenant's ledger (the Patient Access Log names it). |
| **Session receipt** (pages viewed, 0-changes badge) | Requires per-session read accumulation. |
| **The platform console** | Tenants list + tenant detail + operator access log. |
| **Push + in-app notification channels** | Only **email** exists (the standing SETTINGS.P5 seam). |

### 3.4 ⚠️ The BreakGlass divergence — a DIFFERENT model, not an extension

```php
// app/Services/BreakGlassService.php
public function request(User $user, string $scope, string $reason, int $ttlSeconds): BreakGlassGrant
{
    if ($reason === '') { throw new InvalidArgumentException('Break-glass requires a reason.'); }
    $grant = BreakGlassGrant::create([... 'activated' => true ...]);   // ← SELF-GRANTS. No approver.
    $this->audit->record([...'action' => 'break_glass.request'...]);
    return $grant;
}
```

| Dimension | BreakGlassGrant (exists) | Operator Mode (needed) |
|---|---|---|
| Approval | **NONE — `activated => true` at creation** | **Owner approval required** for the patient-data tier |
| Actor | Tenant staff (`user_id` FK, tenant-owned row) | **Cross-tenant platform operator** |
| Tenant | The actor's own | **Another party's** |
| Scope | One string | A **set** of record ids + a category tier |
| Tier | None | read-only / configuration / full |
| Revoke | None | **Owner, instant, mid-session** |
| Request TTL | None (only grant expiry) | Distinct request clock |
| Visibility | Audit row only | **Notification + a decision + a tenant-visible receipt** |

**Do not extend the self-grant path.** Its defining property — that requesting *is* granting — is exactly the
property Operator Mode must not have. Reusing it would smuggle self-grant semantics into a subsystem whose entire
purpose is that the tenant holds the gate. Build a **separate model**; reuse the *patterns* (reason-required,
time-box checked at access time, audit-on-every-transition), not the class.

*(Whether BreakGlass itself should keep self-granting is a separate question this map does not answer.)*

---

## 4. THE SECURITY MODEL

Designed here once, explicitly — **not improvised per screen**.

### 4.1 The core invariant

> **A platform operator can NEVER read or write a tenant's data without an ACTIVE grant that is
> owner-APPROVED (where the tier requires it), UNEXPIRED, IN-SCOPE and IN-TIER — enforced server-side,
> fail-closed, on every access.**

Modelled on the ARDETAIL.P6 Betreibung precedent: **access without a valid grant must be impossible by
construction — no code path exists** — rather than merely "not offered in the UI". Concretely:

1. A super-admin's tenant context is **never** set by session state, a header, or a URL. It is **derived from an
   active grant** and from nothing else.
2. `Gate::before`'s unconditional `return true` is **replaced**: a super-admin resolves to *only* the abilities
   the active grant's **tier** permits, and **only** for the tenant the grant names.
3. Every resource read under a grant is checked against the grant's **scope** at access time (not just at
   session start).
4. Expiry and revocation are checked **at access time**, so a live request already in flight cannot outlive them.
5. **Fail-closed:** no grant, ambiguous grant, unknown tier, or unparseable scope ⇒ **deny and throw**, never
   "allow because super-admin".

### 4.2 The five enforced dimensions

| Dimension | Rule | Enforcement point |
|---|---|---|
| **SCOPE** | The named record ids (`PT-4471/4472/4488`) — *"+1,240 more records, all locked"*. Also the category lists on the granted-read-only screen (message **metadata** yes, **contents** no). | Checked per resource on **every** access, server-side. A scope of "all patient records" must be a separate, loudly-flagged grant kind. |
| **TIER** | read-only ⇒ **no writes at all** (*"look, never touch"*, no export); configuration ⇒ settings/agent config, **no patient data**; full ⇒ patient records within scope. | Ability resolution — the UI's disabled buttons are a *reflection*, never the control. |
| **EXPIRY** | Two clocks (§2.2). **The countdown is a display of a server-enforced TTL, never a timer that grants anything.** A tampered client clock must change nothing. | Checked at access time. Expiry is not a background job's responsibility — a sweeper is housekeeping, not the gate. |
| **REVOKE** | The owner can kill an active session **instantly**, no reason required, effective on the **very next request**. | Checked at access time (same check as expiry). Must not depend on cache/session invalidation. |
| **AUDIT** | Every request, notification, decision (approve/downgrade/decline), expiry, session start, **every record opened**, extension, revoke and end — append-only, hash-chained, who/when/why/what-scope. Written to **the tenant's own ledger**, visible to the tenant's admins, with `actor_type = 'operator'` so it is distinguishable. | `AuditService::record()` — already append-only + trigger-protected + chain-verified. |

**Tenancy still holds.** Fail-closed tenancy is not suspended for operators. The grant is the **only** thing that
opens a **specific** tenant's data to a **specific** operator for a **bounded** time, in a **bounded** tier, over a
**bounded** set of records. `TenantContext::system()` must **never** be used to service an operator request —
system mode is for platform jobs, and reusing it here would silently unbound the query.

### 4.3 🔶 SECURITY DECISION — "read-only before approval"

**Restating the question accurately.** The `Waiting On Approval` screen offers *"Start a read-only session now …
A read-only session doesn't need approval."* On first decode this looked like unapproved access to PHI. **It is
not:** the tier table (§1.5) defines read-only as *"See configuration, settings and usage. **No changes. No
patient records.**"*, and the granted-read-only screen enumerates patient records, clinical notes, message
contents and document contents as **closed**.

**Assessment.** Self-granted read-only over **non-PHI operational configuration** is a defensible line, and it is
the same line the design draws everywhere: *"the owner holds the patient-data gate."* It is materially safer than
what exists today (§3.1), where a super-admin needs no gate at all.

**But three things must be decided, not assumed:**

- **(a) Is non-PHI operational data genuinely non-sensitive?** Billing figures, plan, seat counts, staff names,
  message **metadata** (who messaged whom, when) and booking history are *not* PHI but are commercially and
  personally revealing. *"Message metadata — timestamps & status, not contents"* still discloses that a named
  patient contacted the clinic. **Recommendation: allow, but make it owner-VISIBLE (notified, not just logged),
  and treat any widening of it as requiring approval.**
- **(b) `Configuration` is a self-granted WRITE tier.** It permits changing settings **and agent configuration**
  with no approval. Agent configuration is governance surface — it can only NARROW autonomy (the resolver caps
  it), so the blast radius is bounded, but an operator silently changing a clinic's settings is a real
  escalation. **Recommendation: require owner approval for `configuration`, or at minimum notify the owner in
  real time and make every config write individually audited and reversible.** This is the weakest point in the
  design as drawn.
- **(c) Must patient-record scope be pre-named?** The design's strongest safety property is that scope is a list
  of specific ids agreed *in advance*. `All patient records` should be a distinct, rarer, more loudly-audited
  grant kind — not merely a radio option beside the safe one.

**None of these are for me to settle — they are §7 decisions.**

### 4.4 Threat model — what must be IMPOSSIBLE

| # | Must be impossible | Proven by |
|---|---|---|
| T1 | Access **without** an approved grant (the §3.1 bypass) | G1 |
| T2 | Access **past expiry** — including a request already in flight when the clock runs out | G1/G5 |
| T3 | Access **past revoke** — the next request after a revoke must fail | G5 |
| T4 | Access **outside scope** — record #4 when 3 were approved | G1/G4 |
| T5 | Access **outside tier** — any write under read-only; any PHI under read-only/configuration | G1/G4 |
| T6 | An operator **self-approving** — approving their own request, or any operator approving at all | G3 |
| T7 | A grant **surviving logout / session end** — or being resumable by re-login | G1/G5 |
| T8 | **Forging** tier/scope/expiry client-side (a tampered POST, a frozen countdown) | G1–G5 |
| T9 | An **agent** ever holding or using an operator grant | G1 |
| T10 | A grant for a **suspended** tenant, or a grant outliving tenant offboarding | G1 |
| T11 | Audit rows being **altered or deleted** to hide an access | Existing triggers + `verifyChain()` |
| T12 | The tenant being **unable to see** what an operator did | G4/G6 |

**T6 and T9 deserve emphasis.** T6 is the Betreibung lesson restated: the approver must be structurally incapable
of being the requester — enforced by a NOT-NULL approver FK to a **tenant** user plus an explicit
`approver.tenant_id === grant.tenant_id && approver.id !== requester.id` check. T9 is the fence: **no `AiTool` may
request, approve, hold or use an operator grant**, asserted structurally (no AiCore reference; the only files
touching the service are the service, model and operator-gated controller — the exact-list assertion pattern
proven in ARDETAIL.P6).

---

## 5. The gated build plan — SECURITY CORE FIRST

One gate = one commit = one report = STOP. **Every screen sits on an already-proven-safe backend.**
G1 is a prerequisite for literally everything else, including the very first screen.

### G1 — The grant model + the fail-closed access invariant **(the security core)**
- **Builds:** `operator_access_grants` (append-only; requester FK, tenant FK, tier, scope set, status, both TTLs,
  approver FK nullable, decided_at, revoked_at/by, ended_at + reason) + the state machine + `OperatorGrantService`
  + **the replacement of the unconditional `Gate::before` super-admin bypass** with grant-derived ability
  resolution + grant-derived tenant context. **No routes, no UI, no request flow yet.**
- **Invariant proven:** *no access to any tenant data without an ACTIVE, approved-where-required, unexpired,
  in-scope, in-tier grant* — **T1, T2, T4, T5, T7, T9, T10**.
- **The test that proves it:** a super-admin with **no** grant hits `TenantContextMissingException`/403 on every
  tenant-scoped surface (drive the FIX.5 route smoke as a grantless super-admin); with an **expired** grant, an
  **out-of-scope** id, and a **wrong-tier** action, each denied; a `configuration`-tier write refused under
  `read-only`; an agent holding a grant is structurally impossible (exact-file-list + no-AiTool assertions).
  **Plus a regression guard that the old blanket bypass cannot return** (assert `Gate::before` no longer returns
  an unconditional true for a super-admin).

### G2 — The request flow (operator side, no access granted)
- **Builds:** `request()` — reason/justification **required**, tier, scope (record-id set), session TTL, **request
  TTL**; self-grant path for read-only *(pending the §4.3(b) decision on `configuration`)*; cancel.
- **Invariant:** a request **grants nothing**; a `full` request is created `pending` and is **never** self-activatable.
- **Test:** creating a full request opens no access (all G1 denials still hold); a forged `status=approved` /
  `activated=true` POST body is dropped; reason and scope are mandatory; request TTL is recorded.

### G3 — Owner notification + the decision (approve / downgrade / decline)
- **Builds:** notification to the tenant's owner(s) (email + in-app action-needed item), the decision endpoints,
  the **downgrade** path (approve-read-only-instead), decline-with-reason, and **request expiry**.
- **Invariant:** **only a tenant owner of THAT tenant may decide, and never the requester** — **T6**.
- **Test:** the requester cannot approve their own request; an operator/super-admin cannot approve at all; an
  owner of a *different* tenant cannot approve; an expired request cannot be approved afterwards; a decline
  grants nothing; a downgrade yields a read-only grant, never a full one; every decision is audited with reason.

### G4 — The elevated session (tier + scope enforced) + per-access audit
- **Builds:** session activation from an approved grant, grant-derived tenant context, per-record scope checks at
  access time, `actor_type = 'operator'` read-audit rows, the session banner props (server-supplied countdown).
- **Invariant:** **only** the named records are reachable — *"+1,240 more records, all locked"* is literally true;
  every opened record writes a tenant-visible row — **T4, T5, T8, T12**.
- **Test:** in-scope record 200; out-of-scope record 403/404 **through the real middleware stack**; a write under
  read-only refused; each open writes exactly one `operator` read-audit row into the **tenant's** chain, and
  `verifyChain()` still passes.

### G5 — Revoke (instant) + expiry + the session receipt
- **Builds:** owner revoke (no reason required), server-enforced expiry at access time, the extension
  request/re-approval (**never a silent reset**), the single `ENDED` receipt with `reason ∈ {exited, expired,
  revoked}`, pages-viewed and a real `0 changes` determination.
- **Invariant:** the **next request** after a revoke or expiry fails, regardless of client state — **T2, T3, T7, T8**.
- **Test:** revoke mid-session → the immediately following request is denied; a frozen/tampered client clock
  grants no extra second; an extension requires **owner re-approval** and preserves scope; logout/re-login does
  not resurrect a grant; the receipt's viewed-set equals the audited reads (no fabricated summary).

### G6+ — The screens, one per gate, over the proven backend
Only now, and each purely a display of real server state:
`G6` platform console (Tenants + Tenant Detail + operator access log) · `G7` Enter-operator-mode confirm +
active read-only banner + session-ended receipt · `G8` full-support request + Waiting-On-Approval + owner
notification · `G9` owner decision screen + granted-read-only (downgrade) + declined + expired · `G10` elevated
session banner + extension + revoked-mid-session · `G11` the tenant-side Patient Access Log operator rows.

**Rule for every screen gate:** the countdown is a **display** of a server TTL; a disabled button is a
**reflection** of a server-enforced tier; the scope list is the **grant's** scope. If deleting every `.vue` file
would lose a guard, the guard is in the wrong place (P0D.GU).

---

## 6. Fence / governance flags

- **No computed clinical judgment anywhere** — nothing for the electric fence to catch. The fence relevance is
  **T9**: an agent must never request, approve, hold or use an operator grant. Assert structurally.
- **No money.** The read-only banner *displays* billing figures; write actions (adjust/refund, change plan,
  update card) are disabled — and must be **server**-refused under read-only, not merely greyed out.
- **The agent-exclusion precedent (ARDETAIL.P6) is the model to copy**: a NOT-NULL human approver FK, an
  exact-file-list assertion, no `AiTool`, no AiCore reference, nothing scheduled.
- **Real-or-honestly-absent:** the receipt's "5 pages viewed · 0 changes" must be computed from real audit rows.
  **Push notifications do not exist** (email only) — surface as an honest seam or omit; never imply a push was sent.

---

## 7. OPEN DECISIONS — for the product owner

None of these are engineering calls, and **no gate should be issued until 1 and 2 are answered.**

1. **🔴 Is Operator Mode in scope for the first deployment at all — or a later feature?** The single remaining
   track is DEPLOYMENT. This is a large (11+ gate), security-critical subsystem. **Recommendation: NOT in the
   first deployment.** But note the corollary, which is the uncomfortable part: §3.1 means the platform *already*
   has an unconditional super-admin bypass. **If the answer is "later", consider a small hardening gate now** that
   narrows or instruments the existing `Gate::before` bypass, so the gap is closed on its own timetable rather
   than left open until Operator Mode arrives.
2. **🔴 §4.3(b) — does the self-granted `configuration` WRITE tier stand?** As drawn, an operator can change a
   live clinic's settings and agent configuration with **no owner approval**. This is the weakest point in the
   design. Options: require approval · notify-in-real-time + per-write audit + reversibility · drop the tier.
3. **🟠 §4.3(a) — self-granted read-only over non-PHI operational data: allow (owner-notified + audited), or
   require approval for everything?** Recommendation: allow, owner-**notified** rather than merely logged.
4. **🟠 Who is an "owner"?** No `owner` role exists — only `org_admin`. Map owner → org_admin, or introduce a
   distinct owner concept? The design implies **multiple** owners may approve. Also: what happens when a tenant
   has **no reachable owner** (fail closed — no approval, no access).
5. **🟠 Who may be an operator?** Today "super-admin" is any user with `tenant_id = null`. Should operator
   capability be a separate, individually-granted, auditable role (the console shows an `Operators` nav item),
   rather than implied by being a super-admin?
6. **🟡 The windows.** Request TTL (30 min drawn) and session TTLs (15/30/60; 15/30 for patient data) — and the
   extension cap. Are these the right numbers, and is there a maximum total session length?
7. **🟡 Scope granularity.** Is `All patient records` permitted at all? If yes, should it require a second
   approver, or a shorter box?
8. **🟡 Emergency path.** Is there a break-glass-style path for a **production incident with no owner reachable**?
   If yes it needs its own design (and it is exactly where self-grant semantics creep back in). If no, say so
   explicitly so nobody improvises one later.
9. **🟡 Does BreakGlassGrant's own self-grant model stay as-is?** Out of scope for this map, but adjacent.

---

## 8. Verdict

**Operator Mode is a coherent, well-designed, genuinely net-new subsystem — 14 states across 2 arms, with least
privilege, two clocks, a tenant-held gate on patient data, and two-sided audit.** The design is notably careful:
scope minimised to named records, decline/expire/revoke kept distinct, extensions re-approved rather than silently
reset, and the receipt written to the *tenant's* ledger rather than only the platform's.

It is **not** parity work and **not** a page. It is a security-critical feature whose **prerequisite is fixing an
existing unconditional super-admin bypass** (§3.1) — which is, independently of this feature, the most important
finding in this map.

**This document is a MAP, not a build. STOP here and await the decisions in §7.**
