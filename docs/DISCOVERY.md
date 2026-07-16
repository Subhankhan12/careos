# DISCOVERY.md — the customer-discovery brief

**The backend is feature-complete for MVP+. The next unit of progress is NOT another module — it is
5–8 honest conversations with real Swiss Spitex coordinators, nurses, clinic managers, and doctors.**
This brief lives in the repo so no future session mistakenly restarts building. See also the CURRENT
FOCUS section at the top of `PROJECT-STATE.md`.

> Caveat: current Swiss/EU regulatory specifics (KVG/KLV, canton funding) have NOT been verified from
> here. Treat everything below about Swiss reimbursement as a HYPOTHESIS to confirm in the first
> calls, not as fact.

## The 3 goals of discovery

1. **Which market?** EU-cash vs **CH-insurance-funded (Spitex)** vs US-claims. The nursing/offline
   wedge points hard at Swiss Spitex home-care, but that is unproven. The **reimbursement mechanics**,
   heard consistently across the first ~3 coordinators, decide this. Everything else is prioritization.
2. **Which real problems?** Not the ~50 features on the gap list — the actual daily, costly, unsolved
   pains. Features are guesses; problems are their reality.
3. **Which deferred features are actually real?** By the end, rank the deferred list by **evidence,
   not intuition**, and park the rest with a clear conscience.

## The demo-truth boundary (what is REAL today vs must NOT be claimed)

Overclaiming eRx / lab / claims in a first meeting burns credibility with a burned buyer. The repo
truth:

**REAL — show it:**
- Offline **encrypted nurse PWA** (visit capture + sync) — **the wedge**.
- **Billing → reconciliation-to-the-unit** (EU-Generic; all six invariants, delta 0).
- **CSV patient import** (mandatory dry-run + dedup) — the "get off your old system" story.
- **Dot-phrases** (personal + shared text macros).
- **Structured orders + MANUAL results** (lab transmission stubbed).
- **Recurring / series appointments**; **waitlist auto-fill**.
- **Self-check-in kiosk** — ⚠️ **clinic-only; do NOT demo to a Spitex coordinator** (irrelevant to home care).

**DEFERRED — do NOT claim:**
- **eRx / e-prescribing** — needs a certified pharmacy-network partner.
- **Real lab HL7/FHIR** — only a **stubbed `ManualLabConnectivity` no-op** exists; no live client.
- **Insurance claims / clearinghouse (X12)** — not built.
- **Online payments / PSP**; **reporting / analytics**; **staff rostering**.
- **CH statutory billing pack** — deferred (this is the one most likely to become the real first build).

**Rule:** lead with **import + offline + billing-reconciliation**. About the rest, say *"not yet, and
here's why — it needs a lab / pharmacy / payer partner."* That honesty is itself a selling point.

## The weighted coordinator question (billing = the market decision)

Ask it early and weight it heavily:

> **"Führen Sie mich durch die Abrechnung — an wen stellen Sie was, und wo klemmt es?"**
> (Walk me through billing — who do you invoice for what, and where does it jam?)

- If the answer is Krankenkassen (KVG/KLV) + Gemeinde/Kanton contributions + patient co-pays →
  **CH-insurance-funded**; the EU-Generic billing likely does NOT fit as-is, and the **CH pack is the
  real first build**.
- If it's patient-direct / simple private invoicing → **EU-cash**; the built billing fits.
- If it's submit-claims-and-chase-reimbursement to payers → **US-shaped**; a huge, unbuilt undertaking.

## Recruiting order

1. **Coordinator first** — the buyer, and the person who owns the billing answer.
2. Then ask her for a **warm intro to one of her nurses** (frontline reality of the offline PWA). The
   intro is itself the **first small commitment test**.
3. Separately: a **private clinic practice manager** (clinic-side buyer) and a **doctor** (clinical
   workflow) — but for market choice, the coordinator's billing answer is decisive.

## The truest signal

Talk is cheap. A real signal is a **costly offer** — "can I be a pilot?", "come show my team", an
intro to their boss, time on their calendar. **If no one offers anything costly across 5–8
conversations, that IS the finding** — the pain isn't acute enough yet, and it saves you from building
into a void.

## Discipline notes

- Talk about their life, not the product. Ask about the **past** ("walk me through the last time…"),
  not the future ("would you use…"). Dig for **what each pain costs** (time/money) and whether they've
  tried to fix it.
- **Do not show the product until the end, if at all** — and only if it directly addresses a pain they
  described.
- **Do not start the next gate before the market is decided.** The two markets need opposite products.
