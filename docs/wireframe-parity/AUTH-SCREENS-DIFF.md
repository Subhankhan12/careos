# Auth Screens — Wireframe-Parity Diff (audit only)

**Scope:** diff the live auth surfaces (login · 2FA challenge · 2FA enrolment · password reset · accept-invite)
against the decoded wireframe `resources/prototype/auth-screens.wireframe.html` on every axis (layout · screens ·
fields · controls · styling · states · copy) **AND** the auth-gate semantics — mandatory/locked 2FA, the
remember-me/session floor, the password policy, and no fabricated SSO. **This is an audit. No app code was
changed.** The LAST decoded page of the wireframe-parity pass (eight pages' core already complete).

- **Date:** 2026-08-16 · **HEAD:** `ca90273` (APPT.P3) · **CI:** green. **Env:** `migrate:fresh --seed` +
  `DemoClinicSeeder`, MariaDB dev, `php artisan serve`. **Drivers:** org_admin
  `andrea.lindenhof@praxis-lindenhof.test` / `demo-password` / 2FA `JBSWY3DPEHPK3PXP`, plus a purpose-made
  **un-enrolled** user to exercise the forced-enrolment gate.

> **TWO FINDINGS OUTRANK THE PARITY WORK ON THIS PAGE, AND NEITHER COMES FROM THE WIREFRAME.** The live
> "Remember me" issues a ~400-day cookie that **re-authenticates without a 2FA challenge**, and the password-reset
> pages **return HTTP 500**. Both are live-app defects found while auditing; see §4. The wireframe is, if anything,
> stricter than a generic design and implies nothing weaker than the real gate.

---

## 1. The live auth surfaces + the enforced gates

| Surface | Route / component | Gate |
|---|---|---|
| Login | `GET/POST /login` → `Auth/Login.vue` (Fortify `loginView`) | credentials + **suspended-tenant rejection** (fail-closed, indistinguishable from bad credentials); throttle **5/min** per email+IP |
| 2FA challenge | `GET /two-factor-challenge` → `Auth/TwoFactorChallenge.vue` (Fortify `twoFactorChallengeView`) | required after password; throttle 5/min keyed on `login.id` |
| 2FA enrolment | `GET /two-factor/enrollment` → `Auth/TwoFactorEnroll.vue` + Fortify `user/two-factor-*` endpoints | **`EnsureTwoFactorEnabled`** — an un-enrolled user cannot reach ANY app route |
| Password reset | `GET/POST /forgot-password`, `GET/POST /reset-password` (Fortify `resetPasswords()`) | `ResetUserPassword` + `PasswordValidationRules` → `Password::default()` |
| Accept invite | `GET/POST /invite/{token}` → `Auth/AcceptInvite.vue` (SETTINGS.P6) | single-use / expiring / tenant-bound token; throttle 10/min |
| Session | `careos_session`, `SESSION_LIFETIME=120`, `http_only=true`, `same_site=lax`, `secure=env(SESSION_SECURE_COOKIE)` (**unset in dev**) | driver `database` in dev, Redis in prod |

**Verified live this audit:**
- **2FA is genuinely mandatory.** The un-enrolled user signed in with a correct password and was redirected to
  `/two-factor/enrollment`; requesting `/app` bounced straight back to enrolment. The enrolment screen offers
  **no skip, no "later", no disable**.
- **The challenge cannot be bypassed.** For an enrolled user, password alone lands on `/two-factor-challenge`;
  requesting `/app` mid-challenge redirects to `/login` (not authenticated).

---

## 2. Screen-by-screen diff

| Screen | Wireframe | Live | Class | Severity |
|---|---|---|---|---|
| **Login** | CareOS wordmark · "Sign in" · "Staff and administrator access." · Email · Password · **Remember me** · Sign in. Caption: no self-registration, no reset link, suspended-tenant looks like bad credentials | **Identical field set** — Email, Password, Remember me checkbox, Sign in; no forgot link, no sign-up, no SSO. Suspended-tenant rejection confirmed in code | (c) matches / **(b) see §4.1** | Low visual · **High gate** |
| **2FA challenge** | "Two-factor check" · "Enter the 6-digit code…" · **six segment inputs** · Verify · "Use a recovery code instead" | Same: 6 inputs, Verify, "Use a recovery code instead" | (c) matches | Low |
| **2FA enrolment** | "Set up two-factor authentication" · "Required for every CareOS account before first use." · **3 numbered steps**: QR + **"Can't scan? Enter the secret: JBSW·Y3DP·EHPK·3PXP"** · 8 recovery codes (selectable text) · 6-digit confirm → "Confirm & finish" | 3 steps, QR, **8 recovery codes as selectable text**, 6-digit confirm, "Confirm and finish". **The manual-secret fallback is ABSENT** | (a) visual gap | **Med** |
| **Password reset** | Not drawn; caption says "reset routes exist server-side, unlinked" | **BOTH GET pages return HTTP 500** — no Fortify view bound. The POSTs work (302) | **(d) live defect** | **High** |
| **Accept invite** | **Absent from the wireframe entirely** | Exists and works — honest invalid/expired state: *"This invitation is no longer valid…"* | (c) correctly-more-real | — |
| **States** | Generic credential error ("These credentials don't match our records."), throttle shows the same line, field errors icon+text, "Signing in…" processing, recovery-code mode | "Signing in…" observed; generic-error and throttle behaviour confirmed in code (`authenticateUsing` returns `null` for both bad credentials and suspended tenant) | (c) matches | Low |
| **SSO / social** | **NONE** (the two "google" hits are the Fonts CDN) | None | — | — |
| **Styling** | Eucalyptus Glow, centred card on cream, GuestLayout, zero nav chrome, Inter | Same GuestLayout treatment | (a) visual | Low |

---

## 3. Visual deltas

1. **The enrolment manual-secret fallback (Med).** The wireframe offers *"Can't scan? Enter the secret:
   `JBSW·Y3DP·EHPK·3PXP`"*; the live screen shows only the QR. This is an accessibility/usability gap (a user
   on the same device, or with a camera-less setup, cannot enrol), and the backend already supports it — Fortify's
   `user/two-factor-secret-key` endpoint is even in `EnsureTwoFactorEnabled`'s exemption list. **Safe to add**;
   it exposes nothing the QR does not already encode. *(Note: the wireframe prints the fixed demo/test secret —
   the real screen must render the user's own server-provided secret, never a literal.)*
2. **Everything else matches.** Field sets, controls, copy tone and layout are already at parity on login, the
   challenge and enrolment. There is no meaningful re-skin backlog on this page.

---

## 4. THE SECURITY-GATE VERIFICATION (the crux)

### 4.1 "Remember me" — **MUST-NOT-WEAKEN · the wireframe is safe, the LIVE behaviour is not** — **High**

The wireframe merely draws a "Remember me" checkbox, which is unremarkable. The live implementation is the
problem, and it exists **today, independent of this parity work**:

**Reproduced end-to-end in the browser.** Logged in as org_admin with Remember me ticked and completed the 2FA
challenge, then inspected cookies:

| Cookie | httpOnly | secure | sameSite | expires |
|---|---|---|---|---|
| `remember_web_*` | true | **false** | Lax | **~400 days** |
| `careos_session` | true | **false** | Lax | session |

Then **deleted the session cookie, keeping only `remember_web_*`** (i.e. simulating a browser restart) and
requested `/app`:

> **Result: HTTP 200, the full workspace rendered — no password, NO 2FA challenge.**

So a "Remember me" tick converts into a **~400-day bearer credential that re-authenticates without a second
factor**. `EnsureTwoFactorEnabled` does not catch it: that middleware asserts the user has *enrolled*, not that
this session *passed a challenge*, so a remember-cookie login satisfies it. Mandatory 2FA (SETTINGS.P4) is
therefore enforced at enrolment and at interactive login, but **the remember-me recaller path is a standing
bypass**. Secondary: `SESSION_SECURE_COOKIE` is unset, so in this environment the cookie is not `Secure` —
acceptable on localhost, **must be set in production** (it is on the `docs/DEPLOY-ENV.production.template`
MUST-FILL list, worth re-checking).

**The parity rule this fixes in place:** match the visual (keep the checkbox if desired), but a persistent
session must **never** skip the 2FA challenge and never lower the session floor. Options for a future gate, none
of which is a parity change: (a) drop `remember` from the login payload (the simplest — the checkbox becomes
display-only or is removed); (b) require a fresh 2FA challenge whenever authentication came from the recaller;
(c) bound the remember lifetime to something far below 400 days and re-challenge on expiry. **Do not build a
"trust this device" affordance on top of this** — that would deepen the bypass.

### 4.2 Password reset — **live defect, not a wireframe issue** — **High**

`GET /forgot-password` and `GET /reset-password/{token}` both return **HTTP 500**:
`BindingResolutionException: Target [Laravel\Fortify\Contracts\RequestPasswordResetLinkViewResponse] is not
instantiable`. Fortify's `resetPasswords()` feature is enabled in `config/fortify.php` (so the routes are
registered), but `FortifyServiceProvider` binds **only** `loginView` and `twoFactorChallengeView` — no
`requestPasswordResetLinkView` / `resetPasswordView`. The POST endpoints are fine (`POST /forgot-password` → 302),
because they need no view.

Consequences: the wireframe's caption ("reset routes exist server-side, unlinked") is **half true** — they exist
but are **broken**, not merely unlinked; and a locked-out user has **no self-service recovery path** in
production. This is a C-1-class request-time 500 that shipped green because **the FIX.5 route smoke covers
authenticated staff routes only — no guest route is smoked.**

**The policy itself, when reached, is real but minimal:** `ResetUserPassword` → `PasswordValidationRules` →
`Password::default()`, and no `Password::defaults(...)` is configured anywhere, so the effective rule is
**"at least 8 characters"** — no mixed case, digits, symbols or breach check. That is a product decision to make
deliberately rather than a parity item; flagged because "reset goes through the real policy" is only reassuring
if the real policy is what you intend.

### 4.3 Mandatory, locked 2FA — **ENFORCED; the wireframe agrees** — no action

The wireframe declares `mandatory MFA` / *"Required for every CareOS account before first use"* and draws **no**
skip/postpone/disable control. The live flow matches and enforces it (§1). **Nothing in the wireframe implies an
optional-2FA path.** Keep it that way: no "set up later", no disable, no per-user opt-out.

### 4.4 SSO / social login — **not present anywhere; nothing to fake** — no action

The wireframe shows no SSO or social buttons (the only `google` strings are the Google **Fonts** CDN links), and
the app has no SSO backend (SSO/SAML is parked in `DEFERRED.md`). Nothing to build, nothing to fabricate.

### 4.5 Other gates that hold

- **Suspended-tenant rejection is fail-closed and indistinguishable from bad credentials** (`authenticateUsing`
  returns `null` in both cases) — exactly as the wireframe's caption claims.
- **Throttling** — 5/min on login (email+IP) and 5/min on the 2FA challenge (`login.id`); the wireframe's
  "throttled attempts show the same generic line" matches the generic-failure design.
- **No self-registration** — confirmed: no sign-up route or link on the staff login.

---

## 5. Correctly-more-real (keep — do NOT trim to the wireframe)

- **The accept-invite screen (SETTINGS.P6)** — absent from the wireframe, present and working live, with an
  honest invalid/expired/used state and a single-use, expiring, tenant-bound token that provisions the user and
  pushes them into 2FA enrolment. **Keep it**; apply the same auth visual treatment rather than deleting it for
  absence from the design pack.
- **Enforced mandatory 2FA** — the live middleware is the authority; the wireframe only declares it.
- **Recovery codes as selectable text** (never an image) — matches the wireframe's own instruction and is already
  live.

---

## 6. Feature / backend gaps (flag, do not fake)

1. **Password-reset views (High)** — routes registered, views unbound → 500. Needs `Fortify::
   requestPasswordResetLinkView()` + `Fortify::resetPasswordView()` bound to Inertia pages, in its own gate.
2. **Guest-route smoke coverage (Med)** — the FIX.5 smoke drives authenticated staff routes only; `/login`,
   `/forgot-password`, `/reset-password/{token}` and `/invite/{token}` are unsmoked, which is precisely why the
   500s were invisible. Extending the smoke to guest routes would have caught this.
3. **Password policy (Med, product decision)** — effectively "min 8 characters"; no configured
   `Password::defaults()`.
4. **SMS/second-channel recovery** — none; recovery codes are the only fallback. Consistent with the deferred
   SMS drivers; not a gap to fake.

---

## 7. Prioritized parity punch-list

**Security first (each its own gate; none is a visual change):**
1. *(High, MUST-NOT-WEAKEN)* **Remember-me must not bypass 2FA.** Decide between dropping `remember`,
   re-challenging when auth came from the recaller, or bounding the lifetime. Also confirm
   `SESSION_SECURE_COOKIE` is set in production.
2. *(High)* **Bind the password-reset views** so `/forgot-password` and `/reset-password/{token}` render instead
   of 500 — and add a guest-route smoke so a public 500 can never ship green again.
3. *(Med, product decision)* **Decide the password policy** explicitly rather than inheriting the 8-character
   default.

**Visual parity (safe, small):**
4. *(Med)* **Add the enrolment manual-secret fallback** ("Can't scan? Enter the secret"), rendering the user's own
   server-provided secret via the existing Fortify endpoint.
5. *(Low)* Confirm the error/processing/recovery-code states match the wireframe's copy exactly.

**Keep as-is:**
6. Accept-invite (correctly-more-real), mandatory locked 2FA, no self-registration, no reset link on the login
   card, no SSO.

**The standing rule for this page:** match the visual, but **never** ship an auth affordance that is weaker than
the enforced gate — no skip-2FA, no 2FA-bypassing "remember"/"trust this device", no weakened reset, no SSO
button without an SSO backend.
