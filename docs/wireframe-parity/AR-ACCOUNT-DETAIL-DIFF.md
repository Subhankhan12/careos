# AR Account Detail — Wireframe-Parity Diff (audit only)

**Scope:** diff the live drill target `/billing/accounts/{account}` (the BILLAR.P7 target — currently a placeholder
header) against the decoded wireframe `resources/prototype/ar-account-detail.wireframe.html` on every axis (account
header · invoice ledger · dunning timeline · actions · styling · states · copy) **AND** the two fences — (1) the
**money fence** (ledger figures engine-computed + displayed, every write through the reconciling engine + guards, no
page-side math) and (2) **operator-gated escalation** (Betreibung/debt-enforcement is a human-operator legal action; the
agent NEVER auto-escalates; the dunning stage is the real state machine). **This is an audit. No app code was changed.**
Seventh page of the wireframe-parity pass (Admin Settings ✓, Approval Queue ✓, Branches ✓, Agent & Tool Config ✓,
Allergy safe-part ✓, Billing & AR ✓ — this page is the Billing & AR drill-in).

- **Date:** 2026-08-15 · **HEAD:** `aa82ea0` (BILLAR.P7) · **CI:** green. **Env:** `migrate:fresh --seed` +
  `DemoClinicSeeder` + injected overdue AR (back-dated due dates + real `dunning_events` through the append-only model),
  MariaDB dev. **Login:** org_admin `andrea.lindenhof@praxis-lindenhof.test` / `demo-password` / 2FA `JBSWY3DPEHPK3PXP`.
- **Live captured (Playwright):** drilled `/billing/report` → top-overdue row → `/billing/accounts/{ulid}` for a real
  overdue account (Erika Baumgartner, MRN-000001): `Billing/AccountDetail` renders the **account header (name + MRN)** +
  **four stat cards** (Total overdue CHF 313.00 · 1 invoice · oldest 33 days · dunning stage "Level 2") + a **dashed
  "Full account ledger — arrives in the next gate" placeholder**. No ledger rows, no dunning timeline, no actions.

---

## 1. The live surface + the real sources (what exists vs. wireframe-new)

**Live route/controller/page:** `GET /billing/accounts/{account}` (name `billing.accounts.show`) →
`Modules/Billing/src/Http/Controllers/AccountDetailController@show` → `resources/js/pages/Billing/AccountDetail.vue`.
`billing.view`-gated; the patient (account) is resolved from a **string id** via `whereKey()->firstOrFail()` (FIX.1 —
cross-tenant id 404s). It renders `account {id, name, mrn}`, `overdue {total_overdue_minor, invoice_count,
max_days_overdue, max_stage, ties}` (or null), `currency`, `links {report, dunning}` — every figure straight from
`MetricsService::topOverdueAccounts` (no page math). It is deliberately a **placeholder** awaiting its full ledger (the
BILLAR.P7 gate report says as much).

**Real sources the full page would display:**

| Wireframe needs | Real source today | Exists / new |
|---|---|---|
| Account identity + contact (name · #P-id · phone · email · address) | `Patient` (name, mrn); contact fields (phone/email/address) on `Patient`/related | **name/mrn exist**; contact fields need reading (patient read — audited) |
| Balance due · total outstanding · oldest age · dunning stage · last payment | `MetricsService` (`topOverdueAccounts` per-account rollup; `outstandingBalanceMinor`; `agingBuckets`); `Payment` for last payment | **partly exists** — overdue rollup + stage exist; account-wide *outstanding* (incl. not-yet-due) + *last payment* need a method |
| **Invoice/account ledger** (every charge · payment · reminder fee, running balance) | `Invoice`, `Charge`, `Payment`/`PaymentAllocation`, `InvoiceAdjustment`, `DunningEvent` — the data exists, but **no engine method assembles a per-account chronological ledger with a running balance** | **WIREFRAME-NEW engine method** |
| **Dunning timeline** (reminder → 1./2. Mahnung → Betreibung) | `DunningEvent` (append-only, real state-machine output; `level`, `triggered_on`, `status`) via `DunningService` | **reminder→Mahnung real**; **Betreibung stage is new** (not a modeled dunning level) |
| Record payment | `PaymentService::record` + `allocate` (over-allocation guard; append-only; reconciles) — already wired at `/billing/payments/record` | **exists** (guarded); needs wiring on this page |
| Send new QR-bill | Swiss QR-bill/PDF rendering exists in billing (`DunningLetterRenderer`, invoice PDF) | **partly exists**; account-level "send" action is new wiring |
| Set up payment plan | *none* — no payment-plan model/service anywhere | **WIREFRAME-NEW backend (its own gate)** |
| **Approve Betreibung / debt-enforcement escalation** | *none* — no Betreibung/escalation model or action (grep: the only "escalation" in code is the agent uncertainty hand-off, unrelated) | **WIREFRAME-NEW backend (its own gate) — MUST be operator-gated + agent-excluded + audited** |
| Billing-agent panel ("4 notices sent · 1 awaiting you · 0 auto-escalated") | `DunningEvent` counts + the ApprovalQueue/agent-cap governance (AGENT.P1–P6) | **display gap** over existing governance |
| Open patient chart link | Patient 360 (`/patients/{id}`) | **exists** (link) |

---

## 2. Section-by-section diff

| Section | Wireframe | Live (today) | Diff | Class | Severity |
|---|---|---|---|---|---|
| **Account header / hero** | Graphite hero: avatar, name, `#P-2041`, phone·email·address, "oldest 248 days · opened · patient since", big **Balance due CHF 4'820**, "4 invoices · 1 payment"; a rust **Betreibung** status pill | Eucalyptus dark tile: name + MRN + "Back to report" | Header present but minimal — no contact, no balance-due hero, no invoice/payment count, no status pill | (a) visual + (b) gap | Med |
| **4 KPI stat cards** | Balance due · Oldest invoice (days + INV#) · **Dunning stage** (Betreibung · "needs your approval") · **Last payment** (−CHF 350 · date · method) | 4 glass cards: Total overdue · Overdue invoices (count) · Oldest (days) · Dunning stage (Level N) | Close in shape; figures differ (overdue rollup vs. total outstanding; count vs. oldest-INV#; no last-payment card) | (a) visual + (b) gap | Med |
| **Account ledger** (running balance) | Full transactional ledger: Date · Description · Charge · Payment · **Balance** running column; charges, QR-bill sent, payment received, reminder **fees** (CHF 20/30), reminder events; Tarmed/dental grounding ("Crown + endodontics, tooth 26"); "Balance due today CHF 4'820" | **Absent** — dashed "full ledger next gate" placeholder | Entire ledger missing → the page's core | (b) backend gap | **High** |
| **Dunning timeline** | Vertical timeline: Invoice issued → QR-bill sent → Payment reminder → 1. Mahnung (fee 20) → 2. Mahnung (fee 30) → **Betreibung (drafted · awaiting approval)** | Absent | Timeline missing; reminder→Mahnung derivable from `dunning_events`, **Betreibung stage new** | (b) gap + (d) escalation | **High** |
| **Actions panel** | Record payment · Send new QR-bill · Set up payment plan · **Approve Betreibung** ("it never files without you") | Absent (only "Back to report" link) | All actions missing; two are wireframe-new backend | (b)/(c)/(d) | **High** |
| **Billing-agent panel** | Graphite: "sent reminders + both Mahnungen, drafted Betreibungsandrohung and stopped…"; **4 sent · 1 awaiting · 0 auto-escalated** | Absent | Missing; display over existing governance | (b) gap | Med |
| **Swiss QR-bill** | IBAN `CH93…` · structured ref · Praxis Lindenhof AG | Absent | Missing (billing has QR rendering) | (b) gap | Low |
| **Open patient chart** link | history · treatment plan · recall | Absent | Missing (patient 360 exists) | (a) visual | Low |
| **Styling** | Graphite hero + glass cards + rust status/timeline accents (Eucalyptus Glow) | Eucalyptus dark tile + glass cards | Same system; hero is graphite in WF vs. euca-dark live — DRIFT (cosmetic) | (a) visual | Low |
| **Copy** | Swiss dunning cadence, Tarmed/dental grounding, **"needs your approval" / "it never files without you" / "0 auto-escalated"** | Minimal ("Full account ledger arrives in the next gate") | Governance copy absent (must arrive with the escalation) | (a)/(d) | Med |
| **States** | current / overdue / **in-collection (Betreibung)** | overdue (figures) / "no overdue balance" empty state | Live has an honest empty state; no in-collection/Betreibung state yet | (b) gap | Med |

---

## 3. Visual deltas (keep the design system; these are the cosmetic gaps)

- **Hero:** wireframe uses a **graphite** balance-due hero (name/contact left, big CHF balance right); live uses the
  `euca-tile-dark` header with name+MRN only. Add contact + the balance-due hero when the ledger lands.
- **Status pill:** wireframe carries a rust **Betreibung** pill in the header + a rust-bordered dunning-stage card; live
  shows the stage as a plain "Level N" label. The rust "in-collection/Betreibung" accent is a visual gap that arrives
  with the real escalation state.
- **Ledger table + timeline:** wireframe's running-balance ledger and vertical Swiss-cadence timeline are the two big
  visual/structural additions; both are glass/graphite-consistent.
- **Number format:** wireframe is Swiss-grouped `CHF 4'820`; live formats `minor/100` + currency (313.00 EUR in the demo).
  Match the Swiss grouping + CHF when the ledger lands (a formatting choice, not money math).

---

## 4. Feature / backend gaps (each its own gate)

1. **Per-account ledger (engine method)** — *High.* A new `MetricsService` (or billing service) method that assembles a
   patient's chronological AR ledger: every issued invoice (charge), payment allocation, reminder **fee** (dunning
   fee = a real new charge), and adjustment, with a **running balance**, all from the reconciled projection. Figures
   engine-computed + displayed; the running balance ties to the account's outstanding (δ=0). No page-side math.
2. **Dunning timeline (display)** — *Med.* Read the account's `dunning_events` (append-only, real state machine) into a
   timeline (reminder → 1./2. Mahnung → …). Display-only; the stage is the persisted `max(level)` (as BILLAR.P7 already
   surfaces). **Does not include Betreibung until #4 models it.**
3. **Account-wide balance figures** — *Med.* Total outstanding (incl. not-yet-due), last payment, invoice/payment counts
   — small engine methods (the P7 rollup only covers the *overdue* slice). Point-in-time, engine-computed.
4. **Betreibung / debt-enforcement escalation** — *High, governance-critical (see §6).* A new operator-gated,
   agent-excluded, audited action + a modeled escalation stage beyond the configured Mahnung levels. Its own gate.
5. **Payment plan** — *Med.* No model exists; wireframe-new. A modeled installment plan (its own gate); must not compute
   money in the page.
6. **Record-payment + Send-QR-bill wiring** — *Low/Med.* Record payment already exists (`PaymentService`, guarded); wire
   the account action to it. QR-bill rendering exists; wire a "send" action.

---

## 5. THE MONEY FENCE (verification)

- **Ledger figures (per-invoice amount / paid / balance / age; account totals):** today **none are page-computed** — the
  live page only displays `MetricsService::topOverdueAccounts` returns (proven at BILLAR.P7: displayed props === the
  service returns). **Required for the ledger gate:** the per-row charge/payment/balance and the **running balance** must
  be **engine-computed** over the reconciled `invoice_balances` projection + the append-only payment/adjustment ledger —
  **never a Vue running-sum**. The account total must **tie to the rows (Σ rows === account balance, δ=0)**, the same
  reconcile-to-the-unit discipline as the P7 rollup. **Flag:** a running-balance ledger is the classic place a page
  recomputes money — it MUST be assembled in the engine and displayed, with the closing balance tying to
  `outstandingBalanceMinor` for the account.
- **Record payment:** the wireframe's "Record payment" MUST go through the real `PaymentService` — which **guards
  over-allocation**: `allocate()` throws *"Cannot allocate more than the invoice open balance"* and *"…more than the
  payment unallocated remainder"*, only issued invoices with an open balance receive allocations, and every movement is
  an append-only row that reconciles (`openBalance = total − net allocations − net adjustments`). **No payment write may
  bypass this** — no page-side balance mutation.
- **Reminder fees:** the wireframe shows Mahnung fees (CHF 20/30) as ledger lines that raise the balance. In the engine
  these are **real new charges** captured by `DunningService::captureFee` (ChargeCaptureService) — never a page-side add.
  The ledger must display them as the engine recorded them.
- **Payment plan:** if built, it must be a modeled ledger construct; the page must not compute installment amounts.

**Verdict:** the live page holds the money fence (display-only). The *risk surface* is entirely in the unbuilt ledger +
record-payment wiring — both must stay engine-computed/guarded (called out for their gates).

---

## 6. THE OPERATOR-GATED ESCALATION (verification — the governance crux)

- **Dunning stage / timeline = the real state machine:** the stage the live page shows is the persisted
  `max(DunningEvent.level)` — the append-only output of `DunningService::evaluate` (settings-driven thresholds, never
  skips a level, idempotent). The timeline (when built) must render those **real events**, not a page-computed cadence.
  ✅ confirmed real.
- **Betreibung / debt-enforcement escalation — REQUIRED DESIGN (currently unbuilt):** the wireframe is explicit — the
  agent **drafts** the Betreibungsandrohung and **stops** ("it never files without you", "0 auto-escalated", "needs your
  approval"). The escalation gate MUST therefore be:
  - **Human-operator only** — initiated by a person holding a billing-manage/escalation permission (never the agent).
    Betreibung is a legal debt-enforcement filing; the agent has **no path** to initiate it.
  - **Agent-excluded** — the agent may *draft* the notice (suggest-only, through the existing ApprovalQueue / agent-cap
    path), but committing the escalation is a human action. The "0 auto-escalated" invariant must be enforced, not just
    displayed.
  - **Audited + append-only** — the escalation (and its approval) writes an immutable audit/event row, like every other
    money/governance movement.
  - **Flag:** because the escalation is wireframe-new, this must be its own carefully-gated backend gate; do NOT ship a
    "one-click file Betreibung" without the operator gate + agent exclusion + audit.
- **Send reminder:** the wireframe's reminders are **agent-drafted** on a cadence; sending stays inside the existing,
  idempotent `DunningService` (settings policy; `billing.manage` to run; re-running is a no-op) and the agent-draft path
  respects the AGENT.P1–P6 cap + ApprovalQueue. Any "send new QR-bill / reminder" action on this page must reuse that
  path — **not a new auto-send** that bypasses the cap/approval gate.

**Verdict:** the real dunning stage is state-machine-backed and correct. The Betreibung escalation is the single most
governance-sensitive item on this page and is unbuilt — it must be designed as operator-owned + agent-excluded + audited
from the start (the wireframe's own framing).

---

## 7. Correctly-more-real (keep — do NOT regress to the wireframe)

- **Engine-only figures (BILLAR.P7):** the live page computes no money — every figure is a `MetricsService` return, and
  the account rollup ties to its invoices (δ=0). Keep this discipline for the ledger.
- **Real dunning stage:** the live stage is the persisted `max(DunningEvent.level)`, not a hand-authored "Betreibung"
  label. The wireframe's Betreibung is illustrative; the live stage will only show what the real state machine reached.
- **Honest empty state:** the live page shows "this account has no overdue balance" when the rollup is empty — more
  honest than a wireframe that always shows a fully-overdue account.
- **Tenant-scoped + string-id resolution (FIX.1):** cross-tenant drill 404s; keep.
- **Placeholder honesty:** the live page states the ledger "arrives in the next gate" rather than faking rows.

---

## 8. Prioritized parity punch-list

**Now / near (display over existing engine):**
1. ✅ **RESOLVED (ARDETAIL.P1)** — **Per-account ledger** — `MetricsService::accountLedger($actor, $accountId, $asOf)`:
   the account's issued invoices ordered by issue date, each row = invoice # · date · status (the payment-driven
   projection status) · age · amount / paid / balance (from the reconciled `invoice_balances` projection) + a
   **running balance** computed in the engine. THE TIE: Σ rows' balance === `account_outstanding_minor` (the
   account-scoped `outstandingBalanceMinor`) and the final running balance === that total (δ=0, `ties`). Rendered
   read-only on `Billing/AccountDetail.vue` (the Vue computes no money). Locked by
   `tests/Feature/Billing/AccountLedgerTest.php` (5); browser-verified. *(Ledger displays invoices with a running
   balance; the reminder-fee ledger lines / dunning events on the same timeline are P2.)*
2. *(Med)* **Dunning timeline** — display the account's real `dunning_events` (reminder → Mahnung …).
3. *(Med)* **Account-wide figures** — total outstanding, last payment, invoice/payment counts (small engine methods).
4. *(Med)* **Header/hero + status pill + Swiss `CHF x'xxx` formatting** — visual parity over the above.
5. *(Low)* **Open patient chart** link; **Swiss QR-bill** display.

**Later / carefully-gated (backend + governance):**
6. *(High, money-fenced)* **Record payment** action → wire to `PaymentService` (the over-allocation guard); **never** a
   page-side balance write.
7. *(High, operator-gated)* **Betreibung / debt-enforcement escalation** — its own gate: **human-operator only,
   agent-excluded, audited + append-only**; the agent drafts and stops ("0 auto-escalated" enforced, not just shown).
8. *(Med)* **Payment plan** — wireframe-new model (its own gate); no page-side money math.
9. *(Low/Med)* **Send new QR-bill / reminder** — reuse the existing `DunningService` + agent-cap/ApprovalQueue path; not
   a new auto-send.

**Money-fence + operator-gate call-outs (must hold in every gate above):** ledger figures engine-computed + displayed,
the running balance tying δ=0 (no Vue sum); record-payment through `PaymentService` + the over-allocation guard; the
dunning stage the real state machine; **Betreibung human-operator + agent-excluded + audited — never auto-escalated**.
