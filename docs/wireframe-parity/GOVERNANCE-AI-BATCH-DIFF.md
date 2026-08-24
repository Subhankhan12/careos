# Governance & AI — wireframe-parity batch diff (10 screens, AUDIT ONLY)

**Audited 2026-08-23 at `ae00b5a` (PT.P7), CI green.** Fourth domain batch, after Dental core, the
Patients & Clinical core, and the Portal batch (11/11 complete). **No app code was written for this
audit.** `resources/prototype/` stays gitignored.

**Why this batch is different from the three before it.** On a clinical screen the fence is a rule
about what may be *displayed*. Here the fence **is the product**: these ten screens are the surfaces
that show, configure and prove the caps. A wireframe that draws a control the resolver would refuse
is not a cosmetic gap — it is a promise the code deliberately does not keep. So §4 is the long
section, and it is the one to read.

**Headline:** seven of the ten are already **PARITY COMPLETE** from the APPROVAL.P1–P7 (`ea0e9b3`)
and AGENT.P1–P6 (`d0199e3`) passes. The real remainder is **two never-compared screens** (Governance
Dashboard, KB Admin) and **one with no live page** (Governance Ledger Export). But the batch's most
valuable output is not the remainder — it is that **the Governance Dashboard mock contains three
tools that do not exist, a metric family the backend cannot source, and a "confidence" number the
product has already decided (AGENT.P6) it does not have.** Building it as drawn would undo the fence
it is supposed to display.

---

## 1 — The ten screens

Decoded to `resources/prototype/gov-*.wireframe.html` (gitignored). Two were already decoded by the
earlier passes and were reused, not re-decoded.

| # | Screen | Purpose (one line) | Audience | Live route · Vue | Built state |
|---|---|---|---|---|---|
| 1 | **Admin Approval Queue** | Every pending agent output, with approve/reject through the real gate | **STAFF** (`ai.manage`) | `governance/approvals` · `Governance/ApprovalQueue.vue` | ✅ **PARITY COMPLETE** (APPROVAL.P1–P3, P7) |
| 2 | **Draft Review Composer** | One queued draft, opened to review · edit · send — the human is the one who sends | **STAFF** (reception) | APPROVAL.P4 — inside `Governance/ApprovalQueue.vue` | ✅ **PARITY COMPLETE** (APPROVAL.P4) |
| 3 | **Fence-Refused Detail** | A clinical question the agent refused — no draft ever existed | **STAFF** | APPROVAL.P5 — inside `Governance/ApprovalQueue.vue` | ✅ **PARITY COMPLETE** (APPROVAL.P5) |
| 4 | **Resolved Action Detail** | The full audit trail of one approved action: drafted, sent, who decided | **STAFF** | APPROVAL.P6 resolved view | ✅ **PARITY COMPLETE** (APPROVAL.P6) |
| 5 | **Rejected Action Detail** | Same layout, ending on the reject reason instead of a sent body | **STAFF** | APPROVAL.P6 resolved view | ✅ **PARITY COMPLETE** (APPROVAL.P6) |
| 6 | **Agent & Tool Config** | Per-agent autonomy ladder, tool whitelist, the fence vault | **STAFF** (`admin.manage`) | `admin/agents`, `governance/agents` · `Governance/Agents.vue` | ✅ **PARITY COMPLETE** (AGENT.P1–P6) |
| 7 | **New Agent Wizard** | Create an agent — autonomy and the fence chosen deliberately | **STAFF** (`admin.manage`) | AGENT.P6 — inside `Governance/Agents.vue` | ✅ **PARITY COMPLETE** (AGENT.P6) |
| 8 | **Governance Dashboard** | Everything the agents did, what a human touched, where the fence held | **STAFF** (`audit.view`) | `governance` · `Governance/Dashboard.vue` | 🔵 **LIVE, NEVER COMPARED** |
| 9 | **KB Admin** | Article list + editor; the front-desk agent answers only from active articles | **STAFF** (`ai.manage`) | `governance/kb` · `Governance/KnowledgeBase.vue` | 🔵 **LIVE, NEVER COMPARED** |
| 10 | **Governance Ledger Export** | The append-only record exported for an auditor, with the hash chain | **STAFF** (`audit.view`) | — | ⚪ **NO LIVE PAGE** |

**All ten are STAFF/admin.** Not one is patient-facing, and none should ever become so — every
screen here discloses agent internals, reviewer identities and (on the composer) patient message
bodies.

---

## 2 — The real governance machinery, as found

The audit below maps each wireframe onto this. Everything in this section is code that exists today.

**`AgentResolver` (AGENT.P1) — the capped resolver.**
`effectiveLevel = MIN(configured, tool ceiling, role ceiling)`, evaluated **at call time**, so a
forged stored level cannot widen anything. A paused agent or a non-whitelisted tool resolves to
`OFF`. `agentCeiling()` is the MIN across the tools an agent whitelists — the most restrictive tool
sets the cap, and an agent whitelisting no registered tool has a ceiling of `OFF`.

**`AutonomyPolicy` — four levels and a hard cap.**
`OFF < SUGGEST < APPROVE < AUTO`. `cap()` applies the per-tool ceiling and then a second, absolute
rule: **anything clinical or financial can never exceed APPROVE**, whatever the tool declares.

**The ten governed tools** — this is the whole set (`app/AiCore/Tools/`):

| Tool key | Category | Ceiling | Permission |
|---|---|---|---|
| `comms.draft_reply` | operational | **suggest** | `comms.manage` |
| `comms.classify_document` | operational | **suggest** | `note.write` |
| `clinical.draft_recall_message` | **clinical** | **suggest** | `note.write` |
| `clinical.summarize_since_last_visit` | **clinical** | **suggest** | `note.write` |
| `scheduler.suggest_slots` | operational | approve | `appointment.manage` |
| `scheduler.fill_from_waitlist` | operational | approve | `appointment.manage` |
| `nursing.propose_assignments` | operational | approve | `dispatch.manage` |
| `nursing.replan_day` | operational | approve | `dispatch.manage` |
| `billing.suggest_charge_codes` | **financial** | approve | `billing.manage` |
| `billing.preflight_invoice` | **financial** | approve | `billing.manage` |

Read the verbs: **draft, classify, suggest, propose, replan, preflight.** There is **no send tool, no
sign tool, no charge tool, no book tool**. That absence is the architecture, not an omission.

**`AgentRegistry`** — six canonical agents (`inbox`, `scheduler`, `recall`, `clinical_summary`,
`dispatch`, `billing`), each mapped to real code-class agents and real tool keys, plus
`LEDGER_ALIASES` (the `agent` column is free-form with no FK, so the alias map is what makes
per-agent metrics real rows rather than a guessed join).

**`ApprovalQueue`** — `propose()` authorises against the tool's own permission and writes an
append-only ledger row. `approve()` **re-authorises the reviewer against the tool permission,
re-executes the tool from live state (re-grounding), and records a human-edited marker when the
reviewer edited**; an edit is not a bypass, it is content posted *through* the gate. A
`FenceRefusalException` during approve is recorded as `fence_refused` — a terminal, countable
outcome with the fence's own reason — and re-thrown. `reject()` requires a non-empty reason.
`bulkApprove()` excludes **clinical and financial** actions server-side by the tool's real category.

**The ledger** — `ai_interactions`, append-only, outcomes `proposed / approved / executed / rejected
/ fence_refused`; `audit_events` is hash-chained with DB triggers blocking UPDATE and DELETE, and
`AuditService::verifyChain()` replays it. `IntegrityCheck` holds the scheduled evidence trail.

**`AgentMetricsService` (AGENT.P5)** — `draftsToday`, `approvedAsIsPct` (**null → "—"** when nothing
has resolved: never a fabricated 0 or 100), `fenceRefused7d`. The honesty rule is explicit in its
docblock: *every number is a real count from a real column, or honestly absent.*

**The fence vault (AGENT.P3)** — six **code-enforced, toggle-free** invariants displayed read-only:
`ai_labelled`, `human_approves_send`, `clinical_reviewed`, `consent_scoped`, `immutable_ledger`,
`reground_at_approve`. There is no route and no action to disable any of them.

**Uncertainty escalation (AGENT.P6)** — `escalation.alwaysOn = true`, with **no key to disable it**,
and `confidenceThresholdWired = false`: the runtime has no confidence signal, and the screen says so
rather than drawing a phantom control. `clinician_attention_at` / `clinician_attention_reason` on
`threads` is the real hand-off marker.

### Fixture verified BY QUERY (`DemoClinicSeeder`)

```
agent_actions   pending 2 (comms.draft_reply/inbox, scheduler.fill_from_waitlist/scheduler)
                fence_refused 1 (comms.draft_reply/inbox)
ai_interactions proposed 3 · approved 1 · fence_refused 1
agents          6 entities, all configured=suggest, all active
kb_articles     3 active
```

**An honest fixture gap, stated rather than glossed:** the demo seed produces **pending and
fence_refused only — no `executed` and no `rejected` agent action**. Screens 4 and 5 (Resolved /
Rejected detail) therefore have **nothing to show in a demo tenant**; their coverage lives in tests.
That is a demo-data gap, not a product gap, and it is the cheapest item in §6.

---

## 3 — Per-screen diff

Severity: **High** = a fence or contract issue · **Med** = a real gap a user would notice ·
**Low** = chrome.

| Screen | Deltas (mock → live) | Classify | Severity |
|---|---|---|---|
| **1 · Approval Queue** | Parity-complete. Live is **correctly more real**: per-tool RBAC filtering of what a reviewer may even see, the server-side clinical/financial bulk exclusion, fence-refused as a countable status. Nothing outstanding. | — | — |
| **2 · Draft Review Composer** | Parity-complete for the review/edit/send spine. Mock adds three things the backend cannot source: **"Answer confidence 0.86 · above the 0.68 draft threshold"** (no confidence signal exists — AGENT.P6 recorded `confidenceThresholdWired: false`); **"Ask agent to revise"** (no re-draft tool call from the queue); **an 18-second "Undo" window** on a sent message (no recall/unsend path — and `human_approves_send` means the send already happened). Also `"318 characters · calm, plain tone"` — the character count is real, **"calm, plain tone" is a graded judgment of the text**. | (a) **fabricated metric** · (b) backend-gap · (c) **unbacked affordance** | **High** (confidence) · Med (revise) · **High** (undo) |
| **3 · Fence-Refused Detail** | Parity-complete and **the mock and the code agree exactly**: refused before drafting, no content ever generated, `clinician_attention` set with the refusal reason, ledgered and immutable. The one difference is that live shows the *tool key* where the mock shows a friendlier label. | correctly-more-real | — |
| **4 · Resolved Action Detail** | Parity-complete (APPROVAL.P6). Mock's `ceiling: suggest` chip and "re-grounded at approval · sources matched current state" are both **true of the code** (`approve()` re-executes). Mock shows grounding chips (`slots:17.07`, `appointment:0912`) — live shows real structured sources where the action recorded them and nothing where it did not. **No live demo data** (see the fixture gap). | backend-gap (demo data only) | Low |
| **5 · Rejected Action Detail** | Parity-complete. Mock's tool key `recall.send_batch` **does not exist** — the real recall tool is `clinical.draft_recall_message`, ceiling **suggest**, and there is no batch-send tool at all. The mock's own body text ("Nothing was posted") is right; its *tool name* implies a capability the product refuses to have. **No live demo data.** | **invented tool** + backend-gap (demo data) | Med |
| **6 · Agent & Tool Config** | Parity-complete (AGENT.P1–P6): ladder capped by the resolver, whitelist editing, the read-only permission mirror, the toggle-free fence vault, real metrics with "—", rate/quiet-hour limits, always-on escalation. Nothing outstanding. | correctly-more-real | — |
| **7 · New Agent Wizard** | Parity-complete (AGENT.P6) for creating an agent of a **real kind** with real tools. Mock differs on **the ladder itself**: it offers "Level 1 / 2 / 3" where the product has four levels (`off/suggest/approve/auto`), and calls Level 3 *"requires governance sign-off"* — a workflow that does not exist; in the product `auto` is simply **unreachable for clinical/financial tools, forever**. Mock's tool table lists `scheduling.read`, `waitlist.read`, `comms.draft`, `comms.send`, `scheduling.book` — **five keys, none of which are registered tools**. | **invented tools** + **different ladder** | **High** |
| **8 · Governance Dashboard** | **The big one — see §4.** Live has: chain verification (live replay + last `IntegrityCheck`), reconciliation status, AI outcome counts + cost over a fixed 30-day window, queue depth, kill-switch state, recent + security audit events. Mock adds: a **date-range picker with presets and Compare**, **per-agent filtering**, an **agent-fleet grid with per-agent counts**, an **outcome-mix bar**, a **"needs a human" worklist**, a **"most refused tools" list**, a **KB-gap panel**, a **sparkline/trend**, and **ledger export**. Of those, the picker, per-agent filter, fleet grid, outcome mix and needs-a-human list are **real backend gaps** (sourceable); the rest are **not sourceable**. | mixed — see §4 | **High** |
| **9 · KB Admin** | Live has the list, the editor (title/body/tags), the active toggle, the audit trail on save, and the embedding sync. Mock adds: a **"6 active · 2 drafts"** split (live has a boolean `is_active`, so "draft" is not a state), **"Last saved by R. Steiner · 09.07. 14:20"** (the audit row exists; it is not surfaced on the card), a **Preview** action, and the standing explainer line *"deactivate one and the agent stops using it immediately"* — which is **exactly true** (`KbRetriever` filters `is_active = true`) and worth showing. | (a) chrome · (b) backend-gap (last-saved-by) | Low–Med |
| **10 · Ledger Export** | **No live page.** Mock: filtered ledger table with hash column, date-range + format (CSV / JSON / **signed PDF**) + **include-toggles** (sources, reviewer identity, hash chain, **full message bodies**), an estimated size, a chain-head verification line, and the export **writing its own ledger row**. The chain and the verification are real (`verifyChain`, `IntegrityCheck`); **the export path, the signing key, and the PDF are not** — nothing in the codebase exports the AI ledger. | backend-gap (substantial) + **one PHI decision** | **Med–High** |

---

## 4 — THE FENCE VERIFICATION

The six areas the gate names, each answered with *does the live build already refuse this?*

### 4.1 Raise, or appear to raise, autonomy

| Finding | Where | Live enforcement | Verdict |
|---|---|---|---|
| **The wizard's "Level 3 · Extended autonomy — requires sign-off"** implies `auto` is reachable for any agent after a governance review. It is not: `AutonomyPolicy::cap()` returns `APPROVE` for **every** clinical or financial tool regardless of configuration, and `AgentResolver` re-clamps at call time. There is no sign-off workflow, and adding one would not raise the cap. | New Agent Wizard | `AutonomyPolicy::cap()`; `AgentResolver::effectiveLevel()`; the AGENT.P1 clamp tests | **MUST-NOT-BUILD-AS-DRAWN** — the live ladder already renders locked rungs with the reason; keep that. |
| **"Level 2 · Act within limits — sends or acts automatically"** describes autonomous sending. **No send tool exists.** The nearest real thing is `approve`, which still requires a human to press approve. | New Agent Wizard | The tool table above — no send/sign/charge/book tool | **MUST-NOT-BUILD-AS-DRAWN** |
| **Three-rung ladder (1/2/3)** vs the product's four levels. Not a fence breach on its own, but it renames the caps, and a renamed cap is a cap nobody can check. | New Agent Wizard | `AutonomyPolicy::LEVELS` | reconcile — keep the real names |
| A configured level above a tool's ceiling | any config surface | **Clamped twice**: `AgentConfigService` clamps on write, `AgentResolver` clamps at call time | **correctly-more-real** — the live build refuses this already |

**The rule, restated for anyone building here:** config only ever *narrows*. If a screen offers a
rung, the resolver must be able to grant it; otherwise draw it locked, with the reason.

### 4.2 Weaken the approval contract

| Finding | Where | Live enforcement | Verdict |
|---|---|---|---|
| **"Batch of 12 recall reminders approved & sent"** in the activity feed, and `recall.send_batch` on the Rejected screen | Dashboard · Rejected detail | The recall tool is `clinical.draft_recall_message`, **clinical, ceiling suggest**; the batch send does not exist. PC.P7 already refused the mock's auto-send for the same reason (D-180) | **MUST-NOT-BUILD-AS-DRAWN** |
| **The 18-second Undo after send** | Draft Review Composer | Nothing recalls a sent message. `human_approves_send` is a vault invariant: the human's send is the terminal act | **MUST-NOT-BUILD-AS-DRAWN** (D-179 — the UI would claim an action the system cannot perform) |
| Bulk-approving clinical or financial actions | Approval Queue | `bulkApprove()` excludes them **on the server** by real tool category; the page's `bulkEligible` flag mirrors it | **correctly-more-real** |
| Approving without re-authorise / re-ground | Approval Queue | `approve()` re-authorises against the tool permission and **re-executes from live state** | **correctly-more-real** |
| An edit path outside the gate | Draft Review Composer | The edited payload is executed **through** `approve()`, marked `human_edited` on the action, the result and the ledger row | **correctly-more-real** |

### 4.3 Make the fence configurable

**Nothing in any of the ten mocks offers a toggle for a vault invariant** — the wireframes respect
this. Two near-misses worth naming so nobody drifts into them:

- the wizard's *"These choices become the enforced fence"* is **true** of the whitelist and the
  level (both narrow), but it must never be read as "the fence is what you configured here". The six
  invariants are not on this screen because they are not choices.
- the ledger export's **include-toggles** shape content, not enforcement. Fine — but "Hash chain
  (verification manifest)" must not be *optional* in a way that lets an export claim to be
  tamper-evident without it.

**Verdict: correctly-more-real.** `FENCE_INVARIANTS` is display-only, and there is no route to
disable one.

### 4.4 Fabricate a metric

This is where the Dashboard mock does the most damage. Sourceable vs not:

| Number on the mock | Sourceable? | From what |
|---|---|---|
| Agent actions in a window | ✅ | `ai_interactions` count over a date range |
| Outcome mix (approved-as-is / edited / refused) | ✅ | `agent_actions.status` + `edited_payload IS NULL` — the same arithmetic as `approvedAsIsPct` |
| Fence refusals in a window | ✅ | `agent_actions.status = fence_refused` + `fence_refused_at` |
| Queue depth, oldest-waiting | ✅ | pending count + `MIN(created_at)` |
| Per-agent counts (drafts/reminders/summaries) | ✅ | `LEDGER_ALIASES` + outcome |
| Active / paused / new agent counts | ✅ | `agents.status` |
| **"▲ 8% vs prior 7 days"** | ❌ **as drawn** | Comparable if both windows are queried — but the mock draws a **sparkline**, and the trend of a governance metric is close to a judgment about whether things are getting better. Build the two counts, not the verdict. |
| **"Escalated 4%"** as an outcome slice | ❌ | **`escalated` is not a status.** The real hand-off is `clinician_attention_at` on a thread, which is not an `agent_action` outcome. Mixing them into one pie invents a fifth outcome. |
| **"The fence held 100% · 0 breaches"** | ❌ | There is no "breach" record. A fence refusal is the fence *working*; "0 breaches" measures the absence of a thing that has no representation — an unfalsifiable reassurance (the PC.P5 lesson: a false assurance is worse than silence). |
| **"Answer confidence 0.86 · above the 0.68 threshold"** | ❌ | **No confidence signal exists.** AGENT.P6 already decided this and rendered it as honestly deferred. |
| **"8 low-confidence drafts" · "held a low-confidence draft"** | ❌ | Same — and it compounds by treating a non-existent score as a countable event. |
| **"46 ungrounded · last 7 days" + the whole KB-gap ranking** | ❌ | **Nothing tracks ungrounded questions.** No table, no column, no counter (grep: zero hits). The panel's own subtitle — *"ranked by how often a patient asked and the agent had to fall back"* — describes a telemetry pipeline that does not exist. |
| **"~4.6 MB estimated size"** | ❌ until the export exists | Trivially real once there is an exporter |

**Verdict: MUST-NOT-BUILD-AS-DRAWN for the trend verdict, the escalated slice, "0 breaches", every
confidence number, and the KB-gap panel.** The last one is the most seductive: it is genuinely
useful, and it is a *feature*, not a display — if it is wanted, it needs a real ungrounded-answer
record first, and then a screen. (D-170: never fabricate a backend to match a mock. AGENT.P5's "—"
convention is the honest fallback for all of these.)

### 4.5 Show an agent or tool that does not exist

**Nine invented tool keys across three screens.** The registered set is the ten in §2.

| Invented key | Screen | What the product actually has |
|---|---|---|
| `comms.send` | Dashboard ("most refused"), Wizard ("human only") | **Nothing.** A human sends, from the inbox |
| `clinical.sign` | Dashboard | **Nothing.** A clinician signs a note; no tool touches it |
| `billing.charge` | Dashboard | **Nothing.** `billing.suggest_charge_codes` (suggest) and `preflight_invoice` (approve) |
| `recall.send_batch` | Rejected detail | `clinical.draft_recall_message`, clinical, suggest |
| `clinical.summary_draft` | Ledger export | `clinical.summarize_since_last_visit` |
| `nursing.dispatch_suggest` | Ledger export | `nursing.propose_assignments` |
| `scheduling.read`, `waitlist.read`, `scheduling.book`, `comms.draft` | Wizard | Not tools. Reads are RBAC permissions, not governed tools |

Note the pattern: **the invented tools are all the acting ones** — send, sign, charge, book. The mock
reaches for them because a governance screen looks more impressive when the fence is stopping
something dramatic. But drawing `comms.send` as "refused 7 times" tells the reader that a send tool
**exists and was blocked**, when the truth is stronger: *it was never built*. Showing a fence around
a capability the product does not have is a fabrication in both directions (D-170 + D-176).

**Agents:** the mock's fleet lists five ("Front-desk, Recall reminders, Clinical summary, Dispatch
suggestions, Billing follow-up") against six real `Agent` entities — `scheduler` is missing. Every
mock agent does map to a real one, so the fleet grid is **a real backend gap, not an invention** —
the only correction is to show all six and use the real names.

### 4.6 Interpret clinically

| Finding | Verdict |
|---|---|
| **"Escalation · possible red flag — chest tightness · awaiting a clinician · 4 min"** with a **Take** action | The *hand-off* is real (`clinician_attention_at` + reason). **"Possible red flag" is a clinical judgment** the system must not make, and surfacing the patient's symptom text on a governance dashboard puts clinical content on an `audit.view` screen. Show that a thread needs a clinician, with the recorded reason — not a characterisation of the symptom. **MUST-NOT-BUILD-AS-DRAWN** (D-169: the styling carries the ramp, and no judgment word is needed) |
| **"calm, plain tone"** under the draft | A graded verdict on text. The character count is a fact; the tone is not. Drop it |
| **"Non-clinical topic — safe for the agent to draft"** in the composer rail | **Keep.** This is the *classification the fence already made*, stated plainly — the same fact the fence-refused screen shows from the other side. It is a record of a routing decision, not a new judgment |
| Clinical summary tool | Already extractive-only, ceiling suggest, "no interpret / diagnose / infer / prioritize" in its own docblock | **correctly-more-real** |

---

## 5 — Shared components and shared backend gaps

### 5.1 Components — reuse before building

| Need | **Reuse** | Notes |
|---|---|---|
| KPI tiles on the Dashboard | **`ClinicalStatTile` (D-166)** | The closed-tile pattern: a tile shows a real count and is **not a filter**. The mock's tiles are decorative — keep them closed |
| The agent/provenance panel | **the PC.P2 agent panel** | Same "what the agent did and why" shape as the composer rail |
| The fence-vault card | **AGENT.P3's card** | Already read-only and toggle-free; the Dashboard's "the fence" block should *link* to it, not restate it |
| Outcome pills, resolved filters | **APPROVAL.P6's resolved view** | Already has the status pills, reviewer filter and date range the ledger-export table wants |
| Action-ledger table | **AGENT.P5's ledger tab** | The export screen's left-hand table is this table plus a hash column |
| Date-range + presets | **genuinely new** | No shared range picker exists; Billing & AR has period selection but not this shape |
| Export panel (format · include · generate) | **genuinely new** | The billing export is a different contract (no PHI toggles, no signing) |

**The reuse story is unusually strong here:** four of the seven needs are already-built, already-
audited components. Only the range picker and the export panel are new.

### 5.2 Backend gaps — one fix unlocking several

| Gap | Unlocks | Size |
|---|---|---|
| ~~**G1 · A windowed governance-metrics reader**~~ ✅ **DONE (GOV.P1)** — `AgentMetricsService::window()`: counts by real status, by canonical agent, by REGISTERED tool (with each tool's real ceiling), the ledger by outcome, the fence-refusal count and the live queue depth | The dashboard; the export summary panel when GOV.P5 lands | — |
| ~~**G2 · An "actions needing a human" reader**~~ ✅ **DONE (GOV.P2)** — `NeedsHumanReader`: pending approvals + threads still awaiting a clinician, each permission-scoped fail-closed, with the excluded worklists named on screen | The dashboard panel | — |
| **G3 · A governance-ledger exporter** (filtered range → CSV/JSON, chain manifest, private-disk stream, **its own audit row**) | Screen 10 entirely | **Med-High**, security-sensitive |
| ~~**G4 · Demo governance data**~~ ✅ **DONE (GOV.P4)** — executed (as-is + edited), rejected, fence-refused, spread across 12 days, every state driven through its real path | Screens 4 and 5 are demonstrable; AGENT.P5's approved-as-is % has a real denominator | — |
| **G5 · Last-saved-by on a KB article** | The KB card's provenance line | **Low** — the audit row exists; surface it |

**Deliberately NOT gaps:** confidence scoring, ungrounded-question telemetry, a send/undo path, and
a governance-board sign-off workflow. Those are *features the product has decided against or has not
designed*, and listing them as gaps would smuggle them into a parity chain.

---

## 6 — Correctly more real — keep, do not trim

1. **`MIN(configured, tool ceiling, role ceiling)` re-clamped at call time** — stronger than any mock
   states, and the reason a config screen is safe to expose at all.
2. **Clinical and financial capped at APPROVE forever** — no sign-off, no override, no exception.
3. **No send / sign / charge / book tool exists.** The mocks assume these and show them being
   refused; the product never built them.
4. **`bulkApprove` excludes clinical + financial server-side**, by the tool's real category.
5. **Approve re-authorises and re-grounds**, and an edit is recorded as human-edited on the action,
   the result *and* the ledger row.
6. **`fence_refused` is a first-class, countable terminal outcome** with the fence's own reason — the
   mock treats a refusal as an event; the product treats it as a record.
7. **AGENT.P5's "—" for an unsourceable number**, instead of a fabricated 0 or 100.
8. **The fence vault is display-only** — six invariants, no disable route.
9. **Uncertainty escalation is always on, with no key to disable it**, and the confidence threshold
   is rendered as honestly deferred rather than as a phantom control.
10. **Reject requires a reason**, recorded on the action and in the ledger.
11. **Per-tool RBAC filtering of the queue** — a reviewer sees only what they may review.
12. **The governance dashboard has no mutation path at all**; its single POST re-runs verification
    and writes nothing.

---

## 7 — Proposed fix chain

| Gate | Builds | Proves |
|---|---|---|
| ~~**GOV.P1**~~ ✅ **DONE** | **G1 + the Dashboard's honest half.** The windowed reader, the server-re-parameterised range picker (7/30/90), closed KPI tiles per real status, per-agent and per-tool activity over all six agents, the fence-refusal count, the windowed ledger, and the mirrored fence vault. | Every number a real count or "—". **No trend verdict, no "0 breaches", no escalated slice, no confidence score, no invented tool.** The omissions are STATED on the page. See §10. |
| ~~**GOV.P2**~~ ✅ **DONE** | **G2 — "needs a human".** Pending approvals + threads still awaiting a clinician, each with a real count, its items and a link to where a person acts. | Every category is a real state with a cited setter and clearer; the excluded worklists are NAMED so an empty panel is not a false all-clear; no urgency, SLA or priority; the patient's message body never reaches the panel. See §11. |
| **GOV.P3** | **KB Admin parity** — the explainer line, the active/inactive grouping, last-saved-by (G5), Preview. | Deactivation still stops grounding immediately (assert it); no "draft" state invented for a boolean column. |
| ~~**GOV.P4**~~ ✅ **DONE** | **G4 — demo governance data**: one executed as-is, one edited-then-approved, one rejected, the fence refusal kept, spread across 12 days. | Screens 4/5 demonstrable; the outcome mix non-degenerate. **Every state driven through its real path** — the mutation that hand-sets a status turns the suite red. See §9. |
| **GOV.P5** | **G3 — the ledger exporter.** Filtered range → CSV/JSON, chain manifest, private-disk stream, **its own audit row**, `audit.view`-gated. | The export is itself ledgered; the hash manifest is **not optional**; **PHI (message bodies) defaults OFF and is a separate, permission-checked decision** — and if the practice cannot justify it, it should not be a toggle at all. **Security-sensitive: pair with a review.** |
| **GOV.P6** *(optional)* | **Signed PDF export.** | Needs a signing key and a key-management story. **Recommend DEFERRING** until a customer asks — a "signed" PDF with an unmanaged key is worse than an unsigned one. |

**Realistic gate count: 5 core + 1 optional.**

**Recommended order:** GOV.P4 first (it is an afternoon and it makes everything else demonstrable),
then GOV.P1, GOV.P2, GOV.P3, and GOV.P5 last and separately.

**Recommended deferrals:** the confidence score, the ungrounded-question telemetry and KB-gap
ranking, the undo-send window, the governance-board sign-off tier, and signed-PDF export. The first
two are the ones most likely to be asked for; both need a **real signal recorded first**, and
neither is a parity gate.

---

## 9 — GOV.P4 outcome (2026-08-24)

**The gap, restated:** the demo produced `pending` and `fence_refused` only. Two governance screens
had nothing to show, and AGENT.P5's approved-as-is percentage correctly rendered "—" because nothing
had ever resolved.

**Every state is now reached by driving its real path.** No status column is written anywhere in the
seeder, and no ledger row is inserted by hand:

| Outcome | Path actually driven | Reviewer (holds the tool's real permission) |
|---|---|---|
| `executed`, approved AS-IS | `propose('scheduler.suggest_slots')` → `ApprovalQueue::approve()` | reception (`appointment.manage`) |
| `executed`, EDITED then approved | `propose('comms.draft_reply')` → `approve($action, $reviewer, $editedPayload)` | reception (`comms.manage`) |
| `rejected` | `propose('clinical.draft_recall_message')` → `reject($action, $reviewer, $reason)` | Dr Brunner (`note.write`) |
| `fence_refused` | unchanged — propose a non-groundable draft, approve it, the fence fires | reception |

**A tool-safety mistake worth recording, because an existing invariant caught it.** The first
approved-as-is action used `billing.preflight_invoice`, on the reasoning that a "preflight report"
writes nothing. **It writes:** its charge validator persists validation state, and the seeder run
flipped the demo's dunning fee from `draft` to `validated` — breaking an assertion an earlier gate
had pinned. The action was moved to `scheduler.suggest_slots`, whose execute only reads the
availability finder and whose own result says `books_on_approval: false`. **A "report" can still
write**; the only way to know is to read the execute path and then watch what the suite says.

**The time spread — and why it cannot corrupt the chain.** Each real call runs inside a travelled
clock (`Carbon::setTestNow`, always restored), so an action and its append-only ledger rows move
together: rejected 12 days ago, executed-as-is 5 days ago, edited-then-approved 2 days ago, the fence
refusal and the two pending proposals today. Nothing is adjusted afterwards — `ai_interactions`
refuses `UPDATE` at the database, so a post-hoc edit is not even possible there.

The obvious worry is the hash-chained audit, which `verifyChain()` replays in `occurred_at` order.
**It is safe by construction:** `AuditService::record()` forces `occurred_at` strictly monotonic per
tenant (`prevTime + 1µs` when the clock is not ahead), precisely so the stored order and the
verification order always agree. I reasoned my way to the opposite conclusion first and was wrong;
the empirical check settled it — a back-dated governed call left `verifyChain()['ok'] === true` — and
the seeder test still asserts it.

**Tests:** `tests/Feature/Demo/DemoGovernanceDataTest.php` (8 tests). They assert the FINGERPRINTS of
a real traversal, not the status string: a tool-produced `result`, the `approved` + `executed` ledger
pair only `approve()` writes, `human_edited` in all three places an edit stamps it, the fence's own
message as the refusal reason, and the `approved`-then-`fence_refused` ledger pair that no other path
produces. **Mutation-checked four ways, all red:** hand-setting the executed status, dropping the
edit, hand-setting the rejected status, and wiring the demo seeder into `DatabaseSeeder`.

**Two existing fixture counts were updated (flagged, not weakened):** the patient-thread count 4 → 5
(the new groundable thread) and `executed_at count` 0 → 2 — the latter pinned "nothing in the demo
has ever been approved", which is exactly what this gate changed. Two stricter assertions were added
beside it (rejected = 1, fence_refused = 1). The seeder's prior invariants all still hold: the demo
period still reconciles to the unit, the audit chain still verifies, and the seeder is still
idempotent.
---

## 10 — GOV.P1 outcome (2026-08-24)

**G1 — the reader.** `AgentMetricsService::window(from, to)` returns, for a period: counts by **real
agent-action status**, per **canonical agent** (through the same `LEDGER_ALIASES` the agent pages
use), per **registered tool** (with the tool's real category and ceiling beside the count), the
ledger by outcome, the fence-refusal count, and the live queue depth. Tenant-scoped by the models'
own global scope; the dashboard route is `audit.view`-gated, and a user without it gets a 403 with a
positive control proving the gate is what refused.

**One definition, not two.** `approvedAsIsPct` was extracted from AGENT.P5's `hero()` into a private
helper that **both** call, so an agent's own page and the dashboard cannot disagree about the same
number — the BILLAR.P3/P5 precedent. A test asserts the two agree for four agents, and the mutation
that gives the dashboard its own arithmetic turns the suite red. The honest `null → "—"` survives:
`dispatch` and `clinical_summary` show a dash because nothing of theirs has resolved, which is the
control that makes `scheduler = 100%` and `inbox = 0%` meaningful.

**Which timestamp a window uses.** An action counts in the window in which the thing **happened** —
a resolved action by when it resolved, a pending one by when it was raised. Windowing everything on
`created_at` would drop an action raised weeks ago and approved this morning out of "this week",
which is the opposite of what an oversight screen is for.

**The range picker re-parameterises the SERVER.** Picking 7/30/90 days re-requests the page and the
reader recomputes from the records; there is no client-side re-slice, which could only narrow what
was already fetched and would disagree with the database past the page size (the BILLAR.P6 rule).
Proven by fetching two ranges and asserting the server returned different figures — over GOV.P4's
spread, the 12-day-old rejection is present at 30 days and **absent** at 7.

### The omissions — each stated on the page, not silently dropped

The wireframe drew seven things the code deliberately does not have. Every one is absent from the
build, and the page carries a **"What this page does not show"** card naming four of them with the
reason, so a reader who expected a number learns it has no source rather than assuming a bug (the
PC.P5 / PT precedent).

| Omitted | Why |
|---|---|
| **Nine invented acting tool keys** (`comms.send`, `clinical.sign`, `billing.charge`, `recall.send_batch`, `clinical.summary_draft`, `nursing.dispatch_suggest`, `scheduling.read`, `scheduling.book`, `comms.draft`) | Only `ToolRegistry` keys are emitted. Every invented key names an **acting** capability — send, sign, charge, book — and none was ever built; printing one would say it exists and was merely refused (D-170). Rows with an unregistered key are counted in `unregisteredTools` so nothing is hidden, without naming a tool that does not exist. |
| **"The fence held 100% · 0 breaches"** | Nothing records a breach, so the number is unfalsifiable. **Shown instead:** the fence-refusal count — real `fence_refused` actions (APPROVAL.P5) — with a caption saying outright that there is no breaches figure and why. |
| **The confidence score (0.86 / 0.68)** | No runtime confidence signal exists; AGENT.P6 already rendered it honestly deferred. Stated on the page. |
| **The "escalated" outcome slice** | Not an `agent_action` status. The real hand-off is `clinician_attention_at` on a **thread**, and the page says so — pointing to the inbox, where it can be acted on, rather than inventing a fifth slice a chart cannot source. |
| **The KB-gap ranking** | Rests on ungrounded-question telemetry that does not exist anywhere (zero greps). A feature needing a record first, not a parity gap (D-170). Stated on the page. |
| **The "Level 3 requires governance sign-off" tier** | Implies AUTO is reachable. It is not — clinical and financial cap at APPROVE forever, clamped on write and again at call time. No such tier is drawn; instead every per-tool row states the tool's **real ceiling**. |
| **"Possible red flag — chest tightness"** | Both a clinical judgment and clinical content on an `audit.view` screen. The fence scan asserts no symptom text reaches the payload. |

**Reused, not rebuilt:** the closed `StatCard` for the KPI tiles (D-166 — no computed value enters
one, and they are not filters), AGENT.P5's ledger presenter (one method now serves both the agent
tab and the dashboard's windowed table), and AGENT.P3's `FENCE_INVARIANTS` — mirrored from the same
constant, so the two surfaces state one set of invariants rather than two that could drift.

**Tests:** `tests/Feature/Governance/GovernanceWindowTest.php` (7 tests, 116 assertions) — window
boundaries against GOV.P4's spread, the one-definition agreement, registry-only tools (with the nine
invented keys asserted absent *and* asserted still-unregistered), server re-parameterisation, the
fence re-assertion over a **non-empty** payload, the stated omissions, and the RBAC gate.
**Mutation-checked eight ways, all red:** ignoring the window, a second approved-as-is definition,
an unregistered tool reaching the screen, the honest "—" becoming a fabricated 0, the range ignored
server-side, a confidence score added, a "0 breaches" counter added, and `Dashboard.vue` naming
`comms.send`.

**A near-miss worth recording:** the metrics prop was first called `window`, which **shadows the
browser global inside a Vue SFC** — `window.location.pathname` in the range handler resolved to the
prop, not the browser. Renamed to `metrics` and the URL now comes from the server. The rename's
regex also rewrote two i18n keys (`governance.window.byAgent` → `governance.metrics.byAgent`), which
the browser check caught as two untranslated headings.
---

## 11 — GOV.P2 outcome (2026-08-24)

**The enumeration was the gate's real work.** Every candidate "waiting on a human" state, with where
it is set, where it is cleared, and whether it can occur today:

| Candidate | Set | Cleared | Blocks a HUMAN? | Reachable today? | Verdict |
|---|---|---|---|---|---|
| **Pending agent actions** | `ApprovalQueue::propose()` → `pending` | `approve()` / `reject()`, each re-authorising the tool's own permission | **Yes** — nothing else moves it | Yes (2 in the demo) | **INCLUDED** |
| **Threads flagged for a clinician** | `InboxAgent::refuseClinical()` writes `clinician_attention_at` + reason, audits `thread.flagged_for_clinician` | **nothing clears the column** — see below | **Yes** | Yes (1 in the demo) | **INCLUDED**, with a derived definition |
| **Fence-refused actions** | `ApprovalQueue::recordFenceRefusal()` | — (terminal) | **No** — there is no approve or reject left to perform | Yes | **EXCLUDED — terminal.** Where the refusal did leave work, it is already counted: the same inbox refusal sets the clinician flag |
| **Operator access requests** (OPMODE.G3) | `OperatorGrant` / `operator_access_grants` | owner decision | Yes, in principle | **No** — Operator Mode is inert (D-164): no HTTP route, no UI, so none can be raised | **EXCLUDED — cannot occur.** Stated on screen rather than shown as an empty queue |
| **Orders resulted-but-unreviewed** | result entry → `Order::STATUS_RESULTED` | `OrderService::markReviewed()` (`order.manage`) | Yes | Yes | **EXCLUDED — own screen** (`/clinical/orders/review`), named on the panel |
| **Recalls due** (PC.P7) | `RecallEngine::evaluate()` | `RecallService` transitions | Yes | Yes (3 in the demo) | **EXCLUDED — own screen**, named |
| **Draft referrals awaiting send** (PC.P6) | `ReferralService::create()` | `send()` | Yes | Yes | **EXCLUDED — own screen**, named |
| **Unsigned clinical notes** | `ClinicalNoteService` draft | sign | Yes | Yes (3 in the demo) | **EXCLUDED — own screen**, named |
| **Draft timesheet lines** | nursing capture | coordinator `approve()` | Yes | Yes | **EXCLUDED — own screen** |
| **Dunning / waitlist offers** | — | — | Waits on a PAYER or a PATIENT, not on our staff | — | **EXCLUDED — not blocked on us** |

**The finding that shaped the design:** `clinician_attention_at` is **set and never cleared** — a
whole-repo search finds no writer that nulls it. Counting flagged threads directly would produce a
number no human action could ever reduce: a worklist you cannot empty, which is worse than none. So
the reader defines *still waiting* as a conjunction of three real facts — **flagged**, **still
open**, and **no staff message since the flag** — each cleared by a real human action (reply, or
close). Both clearing paths are tested through the real services.

**Scope, stated on the page.** The panel covers agent governance. The clinical and operational
worklists above are real, are not in it, and are **named on screen with the note that they live on
their own screens** — so the honest empty state ("nothing in agent governance is waiting") can never
be read as a global all-clear. That is the PC.P5 completeness lesson applied to the *boundary*
rather than to the contents.

**Per-category permission scoping, fail-closed.** Approvals need `ai.manage`, the clinician hand-off
needs `comms.manage`. A viewer without one gets `visible: false`, a zero and no items — never
another category's data — and the total reflects only what they can see, so they are not told a
queue is empty when they simply cannot see it. Pinned at the reader, called directly, with the
control that the permission they *do* hold still returns real data.

**One definition.** The pending count comes from `AgentMetricsService::pendingApprovalCount()`, the
same figure the GOV.P1 dashboard shows; the test asserts panel = dashboard = queue, and the mutation
that gives the panel its own arithmetic turns the suite red.

**No invented urgency.** No priority, SLA, overdue band or age tint. Items are ordered oldest-first —
a date sort over a recorded timestamp, never presented as importance (the PC.P7 formulation, D-169).

**Where the clinical line falls, precisely.** The panel shows the thread's **subject** (a
staff-authored title already visible in the inbox) and the fence's **recorded reason** (a routing
code) — the item's identity, without which the row is an anonymous "1 waiting" nobody can act on. It
never shows the patient's words. The demo's flagged message reads *"mir ist seit gestern
schwindlig"*, and the fence test asserts that body — **read from the database, so it holds in any
language and survives a seed change** — never reaches the payload. A first version of that test
scanned English symptom words only and would have missed it.

**Tests:** `tests/Feature/Governance/NeedsHumanTest.php` (8 tests, 101 assertions).
**Mutation-checked seven ways, all red:** a category dropped (the exhaustiveness control), the
clinician category counting the raw flag, the panel computing its own pending count, the approvals
permission ignored, an SLA/urgency field added, the excluded worklists no longer named, and the
patient's message body leaked into the panel.

**A gap mutation found in my own test.** With only the *reply* path covered, deleting the
`status = OPEN` conjunct left the suite green — the fixture's one flagged thread was open either way,
so nothing measured that clause. A second test now closes the thread through `ThreadService::close()`
and asserts it leaves the list, which makes the conjunct load-bearing. Same shape as PT.P7's
tenant-binding gap: a conjunct no test isolates is a conjunct that can be deleted in silence.
---

## 8 — What this audit did not do

Fixed nothing. No app code, no tests, no seeders. `resources/prototype/` remains gitignored; the ten
decoded artefacts live there and are not committed.
