# Feature: OPERATOR MODE (cross-module: Platform + app/ + Comms)

> **READ THIS FIRST — THE STATE IS DELIBERATE.**
>
> **The security core and the full approval backend are DONE and LIVE-SAFE (G1–G3).**
> **G4–G11 are DELIBERATELY DEFERRED by an explicit product decision — they are NOT unfinished by accident,
> and they do NOT need finishing before deployment.**
>
> There is currently **NO HTTP route and NO UI** to Operator Mode. It is **backend-only and inert**: nothing can
> reach it over the wire until G4+ wires a surface. That is a safe resting state, not a half-built one.

Not a Laravel module — a cross-module feature. Design, security model and the full gated plan live in
**`docs/features/OPERATOR-MODE-MAP.md`** (14 states, 2 arms, G1–G11).

---

## Why it was built at all (given DEPLOY is the priority)

The MAP found a **live containment gap that existed whether or not this feature ever shipped**: `Gate::before`
returned `true` unconditionally for any super-admin, and `PermissionService::has()` did the same for
`hasPermission()`. The only thing keeping a super-admin out of a clinic's PHI was that
`IdentifyTenantFromUser` never gave them a tenant context, so `TenantScope` threw — **containment by accident,
not by decision**. Operator Mode's core action is precisely to give an operator a tenant context, so building
any of it naively would have converted that accident into unlimited, unscoped, untimed, unaudited access.

G1 closed it. G2/G3 then pinned the two properties that are easiest to get wrong later —
*requesting is not granting* and *the owner is the gate* — while the design was fresh. **That is the whole
reason the chain was started ahead of deployment, and it is finished.**

---

## DONE + LIVE-SAFE

### G1 — the security core (`41a8dea`, D-161)
**A super-admin can no longer read a tenant's PHI without an ACTIVE, UNEXPIRED, IN-SCOPE, IN-TIER,
owner-approved `OperatorGrant`** — enforced server-side, fail-closed, at **BOTH** former bypass points.
- **Context-sensitive, not a removal:** no tenant context → platform level, unchanged (`/admin` console, tenant
  list, cron, system jobs); tenant context set → only a grant permits anything.
- Tiers are an **ALLOW-LIST** — an ability no tier names is denied, so a permission added tomorrow is outside
  every operator tier until deliberately placed.
- **A regression guard asserts the blanket bypass cannot return.**
- `IdentifyTenantFromUser` now explicitly `forget()`s the context for a non-tenant-staff user (`TenantContext`
  is a request/job **singleton**, so an inherited context must never decide a super-admin's abilities; a stale
  one can now only ever DENY).

### G2 — the request flow (`0afaa67`, D-162)
**Requesting is NOT granting** — the property that made BreakGlass the wrong model to extend (there,
`request()` sets `activated => true`).
- `configuration` + `full_support` → **PENDING**, with **no `granted_at` and no `expires_at`**; there is no
  session to be active with, so G1's invariant denies **every** ability while it waits.
- `read_only` → self-grants, but reaches only non-PHI reads.
- **No self-approval path.** **Two separate clocks:** `request_expires_at` (the ask; lapsing grants nothing) vs
  `expires_at` (the session; lapsing ends access).
- **Scope minimised: no wildcard exists** — `full_support` must name its records.

### G3 — the owner decision (`c086de5`, D-163)
**The owner is the gate.** Every `org_admin` of the target tenant is notified and may
**APPROVE / DOWNGRADE / DECLINE**.
- **Only a target-tenant `org_admin` may decide** — not the operator, not another tenant's admin, not plain
  staff.
- **Approval is the ONLY pending→active path**, asserted structurally.
- **A downgrade SUPERSEDES, never mutates** (the ARDETAIL.P6 recipe), so "what was asked" and "what was
  granted" both survive. Owners may only ever **narrow** (tier rank and per-kind scope subset).
- **Two-sided audit:** the decision is recorded with the **OWNER** as actor.
- **Notification is EMAIL ONLY** (the standing SETTINGS.P5 seam — in-app and push are unbuilt and unclaimed),
  and it cannot be switched off. **No owner ⇒ fail-closed** (`operator.owner_unreachable`; the request lapses).

**Locked by 40 tests:** `OperatorGrantAccessTest` (15) · `OperatorRequestFlowTest` (12) ·
`OperatorOwnerDecisionTest` (13).

---

## DELIBERATELY DEFERRED — G4–G11 (post-first-customer)

**An explicit product decision, not an omission.** The security-critical work is complete; everything left is
**operator-facing convenience UI**, to be built **after the first customer is live**.

| Gate | What it would add |
|---|---|
| **G4** | Elevated-session mechanics: grant-derived tenant context, per-record scope checks at request time, per-access audit rows, the session banner's server-supplied countdown |
| **G5** | Mid-session revoke (instant) + the expiry sweep + the session receipt (pages viewed, a real "0 changes" determination) |
| **G6–G11** | The ~7 operator/owner **screens**: platform console · enter-confirm / active / ended · request + waiting-on-approval + owner notification · decision / granted-read-only / declined / expired · elevated + extension + revoked · the tenant-side Patient Access Log operator rows |

**Consequences of the pause, stated plainly:**
- **No HTTP route, no UI, no controller.** The feature is unreachable over the wire.
- The backend is **inert but correct** — the model, the invariant, the request flow and the decision flow all
  work and are tested; nothing calls them from a request.
- `expireDueRequests()` is **not scheduled** (nothing operator-related is in `routes/console.php`), which is
  deliberate: with no HTTP surface there are no live requests to sweep.
- **Do not treat this as unfinished work blocking deploy.** If a gate is ever issued, resume at **G4** and read
  the MAP first.

---

## Settled product decisions

1. **`configuration` REQUIRES owner approval** (D-162). It is a WRITE tier (clinic settings + agent config); the
   wireframes drew it self-granted, which the MAP flagged as the design's weakest point. Only `read_only`
   self-grants, and only because it is non-PHI.
2. **The OWNER is the tenant's `org_admin`** (D-163). No new role — a parallel owner concept would have been a
   second, weaker path to the same authority. A tenant may hold several; all are notified, all may decide.

## STILL OPEN

- **Is an "all patient records" scope ever permitted?** **Currently FAIL-CLOSED — no wildcard exists**: `*`,
  `all`, `ALL`, `any`, `%`, empty lists and blank ids are all refused, so the only way to reach a record is to
  have named it. Answer this before G4 if the answer might be "yes".
- Non-blocking: who may be an operator (today: any user with `tenant_id = null`) · the request/session windows
  and any extension cap · an emergency no-owner-reachable path · whether `BreakGlassGrant` keeps its own
  self-grant model.

---

## Key classes

- `Modules/Platform/src/Models/OperatorGrant.php` — tiers, `TIER_RANK`, `OWNER_ROLE_KEY`, `isActiveAt()`,
  `coversResource()`, `isNarrowerOrEqual()`, `isRequestExpiredAt()`, `isAwaitingDecisionAt()`. **Deliberately
  NOT `BelongsToTenant`** — it is a platform row REFERENCING a tenant, because the grant is what *produces* the
  context (scoping it would be circular). Grant facts immutable; `granted_at`/`expires_at` **set-once**.
- `Modules/Platform/src/Services/OperatorAccessService.php` — **the single access decision point** (tier
  allow-lists + scope at access time).
- `app/Services/OperatorGrantService.php` — `request()`, `approve()`, `decline()`, `issue()`, `revoke()`,
  `ownersFor()`, `isOwnerOf()`, `assertActivatable()`, `expireDueRequests()`, `recordAccess()`. In `app/` so it
  may compose Platform + Audit + Comms without any module depending on another (D-017).
- Three migrations: `create_operator_access_grants_table` · `add_request_columns_…` · `add_decision_columns_…`.

## Gotchas (cost time once each)

- The role-assignment table is **`role_user`**, not `role_assignments`.
- `NotificationDelivery`'s body column is **`rendered_body`**, not `body`.
- Any structural assertion must use the **qualified** `OperatorGrant::STATUS_ACTIVE` — bare `STATUS_ACTIVE`
  matches ~30 unrelated models (PaymentPlan, Allergy, Agent…).
- The agent is excluded by construction (T9): an exact-file-list test pins every file that may reference a
  grant, plus no AiCore/AiTool reference and nothing scheduled.
