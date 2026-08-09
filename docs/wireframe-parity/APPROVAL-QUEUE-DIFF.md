# Approval Queue — Wireframe-Parity Diff (audit only)

**Scope:** diff the LIVE `/governance/approvals` page against the decoded wireframe
`resources/prototype/approval-queue.wireframe.html` on every axis (layout, sections, cards, controls,
styling, states, interactions, copy, backend gaps) **and verify the governance/fence gates (enforced +
surfaced), not just the pixels**. **This is an audit. No app code was changed.** Page 2 of the wireframe-parity
pass (page 1 Admin Settings = complete).

- **Date:** 2026-08-08 · **Branch:** `main` · **HEAD:** `e7cabf0` · **CI:** green.
- **Env:** `migrate:fresh --seed` + `DemoClinicSeeder`; the demo seed created **2 pending `AgentAction`s via the
  real agent path** (inbox `comms.draft_reply` @ suggest [a `handoff:true` draft]; scheduler `scheduler.fill_waitlist`
  @ approve) — no hand-inserted rows. Driven in Playwright as `andrea.lindenhof@praxis-lindenhof.test` (Org Admin,
  2FA).
- **Wireframe brief:** "Brief 16 · pending agent actions · every card carries the AI badge · the server
  re-authorises & re-grounds on approve."

---

## 1. The live page found & how it maps to the wireframe

| Live | Detail |
|---|---|
| Route | `GET /governance/approvals` · `POST /governance/approvals/{id}/approve` · `POST …/{id}/reject` (`ai.manage`, string-id FIX.1) |
| Controller | `App\Http\Controllers\AiApprovalQueueController` (index/approve/reject) — a **read + act-through-`ApprovalQueue`** window; it adds no execution path and no autonomy |
| Service | `Modules\AiCore\Services\ApprovalQueue` (`approve`/`reject`/`propose`/`autoExecute`) — the tested gate |
| Model | `Modules\AiCore\Models\AgentAction` (statuses: **pending / executed / rejected** only) |
| Vue | `resources/js/pages/Governance/ApprovalQueue.vue` |

**Mapping:** the live page is a **much simpler subset** of the wireframe — a single-column stack of pending
glass-cards + a read-only "Recently resolved" table. The wireframe's governance chrome (stat strip, Pending/
Resolved toggle, agent filter pills, bulk-approve bar, the resolved search/filter grid) is **absent**. The core
**per-card review (approve/reject-with-reason)** and the **server gates behind it are present and real**.

---

## 2. Section-by-section diff

Class: **(a)** visual/layout · **(b)** feature/backend gap · **(c)** governance/fence (match the visual, keep/
strengthen the gate) · **(d)** correctly-more-real. Severity = distance from parity, weighted by governance.

| Section | Wireframe | Live | Diff | Class | Sev |
|---|---|---|---|---|---|
| **Top nav** | Glass pill; tenant chip; Dashboard/Approvals/Knowledge base/Settings | Full app bar; Approvals under **Admin ▾** | Different chrome/IA | (a)/(d) | Low |
| **Header + caption** | "GOVERNANCE · AGENT ACTIONS" → "Approval queue" · "Every agent action is **suggest-only** — a human approves, edits, or rejects…" | "Governance" → "AI approval queue" · "AI actions awaiting human approval. Approve or reject each — it runs only through the tested path, and the caps still bind." | Copy differs; live omits **"edits"** (no edit path) | (a)/(c) | Low |
| **Pending / Resolved toggle** | segmented toggle | **absent** (pending list then a resolved card, always both) | Missing control | (a) | Low |
| **Governance stat strip** (pending · approved-% 30d · avg review · **N refused by fence**) | 4 tiles | **absent** | No metrics surfaced | (b) | Med |
| **Agent-type filter pills** (All/Front-desk/Dispatch/Recall) | pills | **absent** | No filtering | (b) | Low |
| **Bulk-action bar** ("N selected · low-risk only — clinical drafts always need individual review" + [Approve selected]) | present | **absent** | No bulk approve | (b)+(c) | Med |
| **Pending card — chrome** | dashed border · agent chip · **AI-assisted ✦** badge · **tool-permission chip** (`comms.draft_reply`) · "queued <time> · **ceiling: suggest**" | solid glass-card · tool **name** + **category** chip + **"Autonomy: <level>"** chip · "Agent: inbox · `comms.draft_reply`" · **"AI draft — requires human review"** badge · **no queued-time, no "ceiling:" label** | Different anatomy; "Autonomy: level" ≈ but not the "ceiling" cap label; no timestamp | (a)/(c) | Med |
| **Pending card — body** | **What —** … **Why —** …; diff-style mono well of the drafted payload + **"↳ sources: …"** grounding line | **Why** only; **"Proposed output (source-grounded)"** = full JSON dump of `proposed_output` (which includes grounding fields inline) | No "What"; no curated sources line (raw JSON instead) | (a)/(b) | Med |
| **Pending card — actions** | [✓ Approve & send] · **[Edit before sending]** · [Reject] + side-caption "On approve, the server **re-authorises you against `comms.manage`** and **re-grounds** the draft…" | [Approve] · [Reject] (**no Edit**); actions hidden when reviewer lacks the tool permission ("You do not hold the permission…") | **No edit-before-sending**; the re-authorise/re-ground caption not shown | (b)+(c) | High |
| **Reject flow** | expanded danger panel "Reason for rejecting · **recorded on the action**" + textarea + [Confirm reject] · [Cancel] | inline textarea "Reason for rejecting (required)" + [Confirm reject] (danger) · [Cancel] | Equivalent; live less styled (no danger panel), copy leaner | (a)/(c) | Low |
| **Empty state** | "Nothing waiting — every agent action has been reviewed." | "No AI actions are awaiting review." (plain line) | Equivalent, less styled | (a) | Low |
| **Resolved view** | Search resolved… · pills **All/Approved/Rejected/Fence-refused** · date+reviewer sub-filters · **grouped-by-day list** (action · who resolved · when · outcome badge) | "Recently resolved" **table** (Tool · Status · Reason · When), last 20, **no search/filters/grouping**, **no reviewer column** (in props but unshown) | Much thinner; no filters/search/fence-refused; reviewer not displayed | (b) | Med |

---

## 3. Visual deltas to reach parity (measured)

| Token | Wireframe | Live (measured) | Delta |
|---|---|---|---|
| Canvas | cream/sage + radial glows | `.euca-wash` present ✓ | match (headless reported blur `none` — same artifact as Admin Settings; `.glass-card` sets blur(24px)) |
| Card | **dashed** border, agent chip | **solid** `.glass-card`, radius 20px, soft `rgba(53,70,47,·)` shadow | **dashed border missing**; otherwise the glass token matches |
| Mono well | diff-style, `+`/`↳` markers, sources line | `<pre>` monospace, `surface-2/60` bg, radius 16px, `white-space: pre` ✓ | present but a **raw JSON dump**, no diff `+`/`↳ sources` styling |
| AI badge | amber "AI-assisted ✦" | amber warning-soft "AI draft — requires human review" (`bg #F5ECD8`, `text #C99B3F`) | palette match; copy/icon differ |
| Buttons | pill + gradient | `.btn-glow` gradient, radius **16px** (not pill); reject = solid `bg-danger` | gradient ✓; **radius (pill) delta**; reject styling leaner |
| Type | Inter | Inter ✓ | match |
| Missing | stat strip · toggle · filter pills · bulk bar · resolved filters | none of these render | large structural gap |
| Motion/focus | `eucardIn`, `:focus-visible` euca | not applied here (P1 only touched the Settings surface) | add if matching |

---

## 4. Feature / backend gaps (wireframe elements with no/partial live backend)

1. **Governance stat strip (b)** — pending count is trivial; **approved-% (30d)**, **avg review time**, and **"N
   refused by fence"** are **not computed** anywhere. Needs aggregate queries over `agent_actions` (+ a
   fence-refusal source — see below).
2. **"Fence-refused" as a metric/category (b)+(c)** — **`AgentAction` has no fence-refused status** (pending/
   executed/rejected only). The fence refusal is modelled *differently*: the agent returns a **`handoff:true`
   draft** (visible on the live inbox card: `"handoff": true, "explanation": "…a human must reply."`), and
   `DraftReplyTool::execute` throws if a handed-off draft is approved. So there's a real fence, but no
   "fence-refused" **action row** to count/list. Surfacing the stat/category needs a decision: either record a
   fence-refused `AgentAction` (or `AiInteraction` outcome) to count, or derive it from `handoff` drafts.
3. **Bulk-approve (b)+(c)** — no bulk endpoint/UI. If built, it **must** exclude clinical (and financial) drafts
   (individual review only) — the AutonomyPolicy already hard-caps clinical/financial at APPROVE, so bulk-approve
   must additionally refuse those categories in the batch.
4. **Edit-before-sending (b)** — the **service already supports it** (`ApprovalQueue::approve($action, $reviewer,
   $editedPayload)`), but the live controller approves **as-is** (`approve($action, $actor)`, no edited payload)
   and the UI has no editor. Wiring the editor + passing `editedPayload` closes it (re-authorise/re-ground/pending
   all still apply).
5. **Resolved search + filters + grouping (b)** — live is a flat 20-row table; the wireframe's search, the
   All/Approved/Rejected/Fence-refused pills, date/reviewer sub-filters, and day-grouping are absent. The
   **reviewer is already in props** (`reviewedBy`) but not displayed — a quick surface win.
6. **Curated grounding "↳ sources" line + the "What" summary (a/b)** — live dumps the whole `proposed_output`
   JSON; the wireframe shows a human "What —" summary + a compact sources line. Needs a presenter (the data is
   present in the payload).
7. **Ceiling label + queued-time (a/c)** — the card shows the action's `autonomy_level` ("Autonomy: suggest"),
   not the AutonomyPolicy **ceiling** as a cap label, and shows no queued timestamp. Both are display-only.

---

## 5. GOVERNANCE / FENCE verification (enforced server-side? surfaced in UI?)

| Gate | Enforced server-side? (code path) | Surfaced in UI? (browser) | Verdict / rule |
|---|---|---|---|
| **Suggest-only — nothing acts on its own** | **YES.** The queue only holds `pending`; approve/reject are the only transitions. `autoExecute()` exists but is for AUTO-ceiling **reversible** tools only, gated by `AutonomyPolicy` (P2 caps clinical/financial at APPROVE) — those never enter the pending queue. | **YES** (subtitle: "runs only through the tested path, and the caps still bind"). | **Solid.** Keep. Optionally restore the wireframe's stronger "suggest-only" wording. |
| **Approve = re-authorise + re-ground + still-pending** | **YES, all three.** `ApprovalQueue::approve()` → `authorize($reviewer, tool.permission)` (a reviewer lacking the tool's permission is refused), `assertPending()` (a non-pending action can't be re-approved), then `tool->execute()` which **re-grounds** (`DraftReplyTool::execute` explicitly *"Re-ground the draft against current state before anything is posted"* via `preview($input)`, then the **human** posts through `ThreadService` with `ai_assisted=true`). | **PARTIAL.** Actions hide when the reviewer lacks the permission (a UX hint; server authoritative). But the wireframe's explicit **"On approve the server re-authorises you against `comms.manage` and re-grounds the draft"** side-caption is **not shown** — a *surface-it* gap over a real gate. | **The crux, and it holds.** Match the visual by **surfacing** the re-authorise/re-ground caption; never weaken the gate. |
| **Reject requires a recorded reason** | **YES.** Controller `validate(['reason' => required, max:2000])` + `ApprovalQueue::reject()` throws `AiCoreException` on an empty reason; the reason is persisted (`rejection_reason`) and recorded on the `AiInteraction` (`metadata.reason`) — append-only/audited via `AgentActionLifecycleChanged`. | **YES — verified live:** confirming reject with an empty reason returned **"The reason field is required."**, the action stayed pending, nothing executed. | **Solid.** Keep; the reason shows in the resolved table. |
| **Bulk-approve excludes clinical drafts** | **N/A — no bulk-approve exists.** | — | **Feature gap.** If built, it **must** exclude clinical/financial from the batch (individual review only). Match the wireframe's "low-risk only" copy AND enforce category exclusion server-side. |
| **Ceiling: suggest / the cap** | **YES** (P2 `AutonomyPolicy::cap()` clamps every tool to its ceiling; clinical/financial hard-capped at APPROVE; enforced in `AgentRuntime`). | **PARTIAL** — the card shows `Autonomy: <level>` (the proposed level), **not** the ceiling as a labelled cap. | **Real gate, thin surface.** Show "ceiling: suggest" (from `AutonomyPolicy::effectiveCeiling`) to match. |
| **Fence-refused** | **PARTIAL** — the fence is real (a `handoff:true` draft; approving a handed-off draft throws in `execute`), but it is **not recorded as a fence-refused action** to count/list. | **NO** (no stat, no category; the handoff surfaces only as a payload field). | **Backend gap.** Model a fence-refused outcome (or derive from `handoff`) before the stat/category can be honest — do not fake a count. |
| **AI provenance (badge + tool-permission chip + grounding sources)** | Provenance IS recorded (agent, tool_key, `AiInteraction` ledger, `ai_assisted=true` on the posted message). | **PARTIAL** — AI badge ✓, tool_key shown in the agent line, category chip ✓; but no dedicated tool-**permission** chip and no curated **sources** line (raw JSON instead). | Keep the badge; surface the permission + a sources line for parity. |

---

## 6. Correctly-more-real items (keep — do NOT "fix" toward the wireframe)

- **Real pending actions from the real agent path** — the seed's `handoff:true` inbox draft shows the electric
  fence *actually firing* ("Nothing in the permitted sources answers this message; a human must reply"), and the
  scheduler action carries a real `will_book_on_approval` payload. More honest than the wireframe's mock copy.
- **`canReview` per action** — the UI hides approve/reject when the reviewer lacks the **tool's own permission**,
  and the server re-authorises regardless. This is a real per-tool RBAC gate the wireframe only implies.
- **The full `proposed_output` payload** (ids, `handoff`, `explanation`, matches) is real, inspectable grounding —
  more real than a one-line "↳ sources:" mock (though it should be *presented* better, §4.6).
- **Append-only audit** of every approve/reject/execute via `AiInteraction` + `AgentActionLifecycleChanged`.
- **Approve routes only through `ApprovalQueue`/`tool->execute`** — no new execution path in the controller.

---

## 7. Prioritized parity punch-list

**Governance-critical first** (match the visual, keep/strengthen the gate). F = frontend, B = backend.

1. **[High · c] Surface the approve = re-authorise + re-ground contract** — add the per-card caption ("On approve
   the server re-authorises you against `<tool.permission>` and re-grounds the draft before it posts") + show the
   **ceiling** label. The gate already holds; this makes it legible. `F` (+ tiny `B` to pass `effectiveCeiling`/
   permission into props).
2. **[High · b] Edit-before-sending** — wire the editor and pass `editedPayload` to the (already-supporting)
   `ApprovalQueue::approve`; re-authorise/re-ground/pending still apply. `F` + thin `B` (controller accepts an
   edited payload; validate it).
3. **[Med · b/c] Governance stat strip** — pending · approved-% (30d) · avg review · **fence-refused**. Needs
   aggregates + a fence-refused source (see #4). `B` + `F`.
4. **[Med · b/c] Fence-refused modelling** — record a fence-refused outcome (or derive from `handoff` drafts) so
   the stat/category is honest; then surface a "Fence-refused" resolved filter. `B` first.
5. **[Med · b/c] Bulk-approve (low-risk only)** — batch endpoint + UI that **excludes clinical/financial** and
   re-authorises/asserts-pending per action. `B` + `F`.
6. **[Med · b] Resolved view: search + filters + reviewer + grouping** — add search, All/Approved/Rejected/
   Fence-refused pills, date/reviewer sub-filters, day-grouping; **display the already-available `reviewedBy`**. `F`
   (+ `B` for pagination/filtering at scale).
7. **[Med · a] Card anatomy + grounding presentation** — dashed border, tool-permission chip, "What —" summary,
   queued-time, and a diff-style mono well with a curated "↳ sources" line (present the JSON, don't dump it). `F`.
8. **[Low · a] Visual polish** — Pending/Resolved toggle, agent filter pills, pill Save/primary buttons, the
   danger-panel reject styling, `eucardIn`/focus ring on this surface. `F`.
9. **[Keep · d]** the real agent-path actions, `canReview` per-tool gate, the append-only audit, the
   act-through-`ApprovalQueue`-only approve.

**Fence guard for the whole page:** every item must **match the wireframe's governance visual while preserving/
strengthening the real gate** — suggest-only, approve=re-authorise+re-ground+pending, reason-required, the
autonomy ceiling, and bulk-excludes-clinical are the gates; surfacing them must never weaken them.

---

## 8. Parity progress (RESOLVED status per punch-list)

Updated as APPROVAL.P1–… land. One commit per part.

| Punch-list item | Status | Commit |
|---|---|---|
| 8 · Visual/chrome (dashed cards, pill buttons, Pending/Resolved toggle, agent filter pills, eucardIn/focus) | **RESOLVED (P1)** | `APPROVAL.P1` |
| 7 · Card anatomy + grounding presentation (What/Why, tool-permission chip, ceiling cap, sources) | **RESOLVED (P2)** | `APPROVAL.P2` |
| 1 · Surface approve = re-authorise + re-ground (ceiling label in P2; the contract caption in P3) | **RESOLVED (P3)** | `APPROVAL.P2` + `APPROVAL.P3` |
| 2 · Edit-before-sending | **RESOLVED (P4)** | `APPROVAL.P4` |
| 4 · Fence-refused modelling | **RESOLVED (P5)** | `APPROVAL.P5` |
| 3 · Governance stat strip | **RESOLVED (P5)** | `APPROVAL.P5` |
| 6 · Resolved search/filters/reviewer/grouping | **RESOLVED (P6)** | `APPROVAL.P6` |
| 5 · Bulk-approve (low-risk only) | **RESOLVED (P7)** | `APPROVAL.P7` |

> **✅ APPROVAL QUEUE WIREFRAME-PARITY COMPLETE** (P1 chrome → P2 anatomy → P3 approve-contract → P4 edit →
> P5 fence-refused + stats → P6 resolved → P7 bulk). Every punch-list item RESOLVED. **Two pages done in the
> wireframe-parity pass: Admin Settings + Approval Queue. Branches is next** (`resources/prototype/branches.wireframe.html`).

**P7 note — bulk-approve (low-risk only; clinical + financial EXCLUDED server-side; per-item gate):** resolves
punch-list item 5 — the LAST part; the Approval Queue page is now at full wireframe parity.
- **A LOOP, not a batch shortcut.** `bulkApprove()` iterates the selected ids and calls the SAME
  `ApprovalQueue::approve()` per item — each still re-authorises against ITS tool permission + re-grounds +
  asserts-pending. Bulk lowers no per-action gate.
- **THE SAFETY GATE (server-enforced).** Risk is read from the tool's REAL `ToolDefinition::category` (via
  `isClinicalOrFinancial()`) — no invented risk field. Clinical AND financial actions are refused for bulk BEFORE
  approve is called, so a **forged** bulk request that includes a clinical/financial id does NOT approve it (it
  stays pending — individual review only), even for a reviewer who holds `note.write`/`billing.manage`. Proven by
  the exclusion test. An unregistered tool key is fail-closed (skipped).
- **Per item still real:** the fence fires per item (a handoff draft in a bulk is recorded `fence_refused`, P5, not
  forced); per-item RBAC binds (an item the reviewer can't review is skipped, the rest approve). Results are
  reported as a real summary flash (approved / excluded / skipped counts).
- **UI:** only `bulkEligible` (low-risk + canReview) cards carry a checkbox; clinical/financial show an
  "Individual review only" chip. A bulk bar (select-all-low-risk · "clinical/financial need individual review" ·
  Approve selected · Clear). i18n unique `aiQueue.bulk.*`. A new `bulk` shared flash key carries the summary. **No
  existing behaviour test modified; the electric-fence eval + all suites green.** 4 new tests
  (`ApprovalBulkApproveTest`): low-risk bulk approves each through the full gate; **the exclusion proof** — a bulk
  including a clinical AND a financial action refuses both server-side (they stay pending) while the low-risk item
  approves; a fenced bulk item is recorded fence_refused (not forced); per-item RBAC skips an unauthorized item.
  Browser-verified.

**P6 note — the Resolved view: search + status/date/reviewer filters + grouping, over REAL data:** resolves
punch-list item 6.
- **The list** (behind the P1 Pending/Resolved toggle) now renders the real resolved set — **executed / rejected /
  P5 fence_refused** — grouped by day, each row showing the action (tool name · agent), **real attribution** (the
  human reviewer's name, or **"Refused by the electric fence (system)"** for a fence_refused row — never a human),
  the **real reason** (a human reject reason, or the fence's own reason), and the outcome badge.
- **Search + filters query REAL columns only** (`AiApprovalQueueController::index` + helpers, server-side): the
  free-text **search** matches the action's `tool_key` / `agent` / `feature` / `why` / `rejection_reason` (the
  real text columns — not the nested payload, stated honestly); the **status pills** (All / Approved=executed /
  Rejected / Fence-refused) carry **real per-status counts**; the **reviewer** dropdown lists real reviewers
  (`reviewed_by`); the **date** range filters the real resolved timestamp. Every filter maps to a real field — no
  fabricated attribute is filterable.
- **THE FENCE / honesty:** the **Fence-refused** pill uses the P5 `fence_refused` status; those rows are
  **system-attributed** (the fence, with its reason), not a human. Every row + count is a real record.
- **Tenant + RBAC scoped:** the resolved list AND its counts are limited to the tools the reviewer may review (the
  same per-tool permission the approve gate enforces — an `allowedToolKeys` `whereIn`, fail-closed on an
  unregistered key), and tenant-scoped by `BelongsToTenant`. A reviewer never sees (or counts) actions they could
  not have acted on. Grouping-by-day is presentational (client-side over the returned rows). **i18n** extends the
  unique `aiQueue.resolved.*` sub-block (search / filter pills / reviewer / dates / attribution). **No existing
  behaviour test modified.** 4 new tests (`ApprovalResolvedViewTest`): real outcome badge + reviewer/system
  attribution + real reason; the Fence-refused filter returns only system-attributed fence rows with the fence
  reason; search matches the real `why` + reviewer maps to `reviewed_by`; tenant + RBAC scoping (an ai.manage-only
  reviewer sees the demo.echo but not the comms fence refusal; a second tenant sees none). Browser-verified.

**P5 note — model fence-refused as a countable outcome + the stat strip from real data (no faked numbers):**
resolves punch-list items 4 (fence-refused modelling) + 3 (stat strip).
- **The fence is UNCHANGED — only the RECORDING is new.** A handed-off draft still throws on approve, at exactly
  the same condition/moment (`DraftReplyTool::execute` — the handoff/empty-body check); it now throws a
  `FenceRefusalException` (a **subclass of `AiCoreException`**, so every existing `catch (AiCoreException)` still
  catches it — the eval + inbox/live suites are untouched and green). `ApprovalQueue::approve()` catches it and
  **records** the refusal: a terminal `AgentAction::STATUS_FENCE_REFUSED` (+ `fence_refused_at`; the fence's own
  reason kept in `rejection_reason`, distinguished from a human reject by the status), an **append-only
  `fence_refused` `ai_interactions` row** (metadata.reason), and an audited `agent_action.fence_refused` lifecycle
  event — then re-throws. Nothing executes. **No change to when/whether the fence fires** (proven: a non-handoff
  action still executes; the eval that locks the pre-draft clinical refusal is unchanged).
- **The stat strip — REAL data only.** `AiApprovalQueueController::index` now passes `stats`: **pending** = real
  pending count; **fenceRefused** = real `fence_refused` count (danger-tint per the wireframe); **approvedPct
  (30d)** = executed ÷ resolved-in-window (executed + rejected + fence_refused), from real timestamps;
  **avgReviewMinutes (30d)** = avg(resolvedAt − createdAt) from real timestamps. **THE HONESTY RULE:** approvedPct
  and avgReviewMinutes are computed only when there is a real denominator — with **no resolved action in the
  window they are `null`, rendered as an honest "—"**, never 0 or an estimate. So: pending + fence count are always
  real; approved-% + avg-review are real when resolved actions exist, honestly absent otherwise. The Resolved view
  now includes the `fence_refused` category (a warning-tinted badge; P6's filters will use it).
- **i18n** unique `aiQueue.stats.*` sub-block + `flash.fence_refused` + `status.fence_refused`. Migration adds only
  `fence_refused_at` (the `status` column is a plain string — the new value needs no schema change). **No existing
  behaviour test modified; the electric-fence eval unchanged + green.** 4 new tests
  (`ApprovalFenceRefusedTest`): the refusal is recorded (status + fence_refused_at + reason + append-only ledger +
  audit) and nothing executes; only the fence path records fence_refused (a clean action still executes — trigger
  unchanged); the stat strip shows honest "—" when nothing is resolved; the fence count + approved-% + avg-review
  are real when resolved records exist. Browser-verified: approving the seeded handoff draft → flash "the electric
  fence refused this action…recorded as fence-refused", the fence tile increments to 1, it appears in Resolved as
  Fence-refused; approved-%/avg-review show real values.

**P4 note — edit-before-sending (over the EXISTING `editedPayload` support, purely additive — no second approve
path):** the wireframe's **[Edit before sending]** action is now wired on each pending card, and it goes through the
SAME gate as an unedited approve — the edit is NOT a bypass.
- **The affordance** — an **Edit before sending** pill opens an inline editor seeded with the action's **input
  payload** (the tool INPUT that `execute()` re-grounds — surfaced as a new `inputPayload` prop, distinct from the
  read-only `proposed_output` preview well, which is kept). The reviewer edits the payload; **Approve edited**
  submits it as `edited_payload` to the **same** `POST …/approve` route; invalid JSON is refused client-side before
  any submit.
- **THE GATE (unchanged) — the edit changes CONTENT, not the gate.** The controller reads only `edited_payload`
  (`validate(['edited_payload' => 'sometimes|array'])` — the request still cannot raise autonomy or swap the tool)
  and passes it to the **already-supporting** `ApprovalQueue::approve($action, $reviewer, $editedPayload)`. That
  path **re-authorises** the reviewer against the tool's own permission, **asserts still-pending**, and **re-runs
  the tool** (which re-grounds/re-derives from live state) on the payload — exactly as an unedited approve. Proven:
  an **unauthorized** edited approve is **403** (nothing runs), a **non-pending** edited approve is refused by
  assert-pending (the executed result is not overwritten), and an **edited CLINICAL draft** is re-authorised against
  the clinical tool's `note.write` — a reviewer lacking it is denied (the edit does not lower the clinical bar).
- **PROVENANCE — recorded as human-edited (distinguishable).** When an edited payload is supplied, the action's
  `edited_payload` column holds the edit, the executed `result` carries a **`human_edited` marker** (beside the
  tool's own provenance, e.g. `ai_assisted`), and the executed `ai_interactions` ledger row records
  `metadata.human_edited=true` — so an edited post is always distinguishable from an unedited approve (an unedited
  approve carries none of these). The flash reads "…posted through the same gate — recorded as human-edited."
- **RBAC** — the edit affordance renders only when `canReview` (a UX hint); the server re-authorises regardless
  (same gate as approve — no separate weaker path). **i18n** — a unique `aiQueue.edit.*` sub-block + a
  `flash.approved_edited`; reuses the P1/P2 visual (mono well / pill buttons) and the P3 approve-contract caption
  (which applies to the edited approve too). **Purely additive over the existing `editedPayload` support** — no
  second approve path, no new backend judgment. 5 new tests (`ApprovalEditBeforeSendingTest`); no existing behaviour
  test modified; the eval suite + AiApprovalQueue/anatomy/caption/chrome suites stay green.

**P3 note — surface the approve contract (presentational caption, no gate change):** each pending card now carries,
beside its approve/reject controls, the caption **"On approve, the server re-authorises you against `<permission>`
and re-grounds the draft / re-derives the action against current state before it posts / runs."** It states only
what the real path already does — `ApprovalQueue::approve()` re-authorises the reviewer against the tool's own
permission, asserts still-pending, then re-runs `tool->execute()` (which re-derives from live state via `preview()`)
before anything takes effect (confirmed in P2/step-1). The `<permission>` is **interpolated from the action's real
tool permission** (the same value the P2 chip shows and the service re-checks — never hardcoded). The wording is
**accurate per action**: a draft-shaped action (`comms.draft_reply`, its `proposed_output` carries body/lines/handoff)
reads *"re-grounds the draft … before it posts"*; a direct action (`scheduler.fill_from_waitlist`, no draft) reads
*"re-derives the action … before it runs"* — driven by a real `reGroundsDraft` flag read from the action's own
recorded output, so the caption never claims a draft where there is none. **No gate/backend change** — approve/
reject/re-authorise/re-ground/reason-required are untouched; 3 new tests assert the surfaced permission is the real
one and that `reGroundsDraft` tracks the action's actual shape. Browser-verified: the two seeded actions render the
two variants with `comms.manage` / `appointment.manage`.

**P2 note — card anatomy (surface real provenance, presentational only, no gate change):** the pending card now
shows, over the action's EXISTING data:
- **What / Why** — What = the tool's declared name (the action's real intent), Why = the action's recorded `why`.
- **Tool-permission chip** — `<tool_key> · requires <permission>` where the permission is the tool's **real
  declared permission** (`comms.draft_reply → comms.manage`, `scheduler.fill_from_waitlist → appointment.manage`)
  — **the same one `ApprovalQueue::approve` re-authorises against**.
- **Ceiling cap label** — `ceiling: <cap>` from `AutonomyPolicy::effectiveCeiling` (the CAP), rendered **distinct**
  from the `Autonomy: <proposed>` level (a test proves ceiling comes from `effectiveCeiling`, not the proposed
  level).
- **↳ Sources line** — the action's **real recorded grounding** (`proposed_output.lines[].source` →
  `{type: kb_article|admin_fact, ref}`, deduped) or an **honest "No linked sources on this action."** when none
  (the seeded handoff draft + the scheduler action both carry none) — **never fabricated** (the fence-adjacent
  honesty point). Queued-at time added; the **full inspectable `proposed_output` well is kept** (correctly-more-
  real, un-regressed).
- **No gate/backend change** — the controller only surfaces `permission`/`ceiling`/`sources`/`queuedAt` (present-
  only, read from the tool's own declaration + the payload); approve/reject/re-authorise/re-ground/reason-required
  are untouched. 3 new tests; no existing behavior test modified.

**P1 note — visual/chrome parity (frontend only, no gate change):** `Governance/ApprovalQueue.vue` +
`aiQueue.*` i18n (unique `aiQueue.chrome.*` sub-block; the `@`-escape/dup-key guards observed):
- **Cards** now render as **dashed glass** (`.glass-card border-dashed border-euca-300`) with the **`eucardIn`**
  entrance (staggered) and **P1 pill buttons** (`Button pill`: Approve = primary/gradient, Reject = secondary,
  Confirm reject = **danger**, Cancel = ghost); the reject flow gained a danger-tinted panel with the "Reason for
  rejecting · recorded on the action" label. Euca-wash + glass already applied via `AppLayout` (verified in
  browser — not re-added); `.settings-surface` added for the euca-700 focus ring.
- **Pending / Resolved toggle** — a segmented tablist over the two **already-loaded** views (pending stack /
  resolved table); browser-verified switching.
- **Agent-type filter pills** — **All agents + the REAL agents present** (`inbox`, `scheduler` — derived from the
  loaded actions' `agent`, not hardcoded); a client-side filter; browser-verified (Inbox → only the inbox card).
- **Honest copy** — eyebrow "Governance · Agent actions", caption "Every agent action is suggest-only — a human
  approves or rejects before anything happens" (**no premature "edit"** — that's a later part — and **no faked
  stat strip**).
- **PURELY presentational — no gate/governance/backend change.** The approve/reject POST targets, the
  re-authorise + re-ground + assert-pending path, and the reason-required gate are untouched (3 light tests
  assert the prop shape the chrome renders over, that approve still routes through `ApprovalQueue` →
  `executed`, and that reject still requires a reason). Correctly-more-real items (§6) un-regressed.
