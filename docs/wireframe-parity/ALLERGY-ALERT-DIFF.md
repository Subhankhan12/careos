# Allergy Alert — Wireframe-Parity Diff (record-not-compute; certified-partner seam)

**Scope:** the decoded wireframe `resources/prototype/allergy-alert.wireframe.html` vs. the live surface. The
wireframe's HEADLINE behaviour — a COMPUTED drug-allergy cross-reactivity check, drug-CLASS matching, a
severity-gated AUTO-BLOCK with "no override", and an AUTO-SUGGESTED therapeutic alternative — is a **certified-
partner MEDICAL-DEVICE function**. CareOS must NOT build it homemade (the `MedicationSafetyProvider` seam;
homemade drug-allergy interaction / cross-reactivity / class-match / contraindication / substitution checking is a
**PERMANENT non-goal**). This is the record-vs-compute determination.

- **HEAD at build:** `d0199e3` (AGENT.P6). **CI:** green. **Env:** `migrate:fresh --seed` + `DemoClinicSeeder`,
  driven as `andrea.lindenhof@praxis-lindenhof.test` (2FA).

---

## The record-vs-compute split

| Wireframe element | Class | CareOS decision |
|---|---|---|
| Recorded allergen / reaction / severity / source / verification | **RECORD** (a clinician-documented fact) | **BUILD (ALLERGY.P1)** — surface it; grade nothing |
| "amoxicillin cross-reacts with penicillin" | **COMPUTE** (cross-reactivity) | **NON-GOAL** — certified-partner medical device; never homemade |
| "Class — All penicillins" (drug-class match) | **COMPUTE** (class-match) | **NON-GOAL** — no drug-class column even exists |
| Severity-gated AUTO-BLOCK + "no override" | **COMPUTE + ACT** (auto-block) | **NON-GOAL** — a partner's finding is advisory + human-owned, never auto-blocking |
| "Prescribe clindamycin instead" (substitution) | **COMPUTE** (therapeutic substitution) | **NON-GOAL** — certified-partner medical device |
| The advisory alert region itself | **DISPLAY** (renders a partner's output) | **BUILD (ALLERGY.P1)** — display-only shell over the seam; empty today |

The one pre-existing allergy "block" — `Modules\Clinical\Services\AllergyGuard` — is a **deterministic
EXACT-STRING-MATCH** hard-stop (a prescribed substance matched against a RECORDED active allergy, normalised
lowercase/trim), wired only into `MedicationService::record`, human-overridable via `allergy.override`. It is NOT
cross-reactivity / class-matching and is UNTOUCHED by this gate.

---

## §1. What ALLERGY.P1 built (the SAFE parts) — RESOLVED

1. **Allergy RECORD-display** — a recorded `source` field added to `Modules\Clinical\Models\Allergy` (additive
   migration `2026_08_27_000001`, nullable, a CLINICIAN-RECORDED fact) beside the existing recorded fields
   (`substance`, `reaction`, `severity` [recorded enum], `verified_at`). `ClinicalChartController` surfaces
   `source` + `recorded_at` + `verified_at`; a new `resources/js/Components/AllergyRecordPanel.vue` renders a
   record card per active allergy — allergen, reaction, **recorded severity shown as a fact** (not a computed
   grade), source, recorded date, verification/confirmed. This is the record-not-judge line (like the lab
   reference-range display): show the recorded value, compute no verdict.
2. **Display-only MedicationSafetyProvider seam shell** — the same component renders a display-only advisory
   region wired to the seam. The controller reports `medicationSafety.providerConfigured` (`false` today — the
   bound provider is `NullMedicationSafetyProvider`) + `advisories: []`. The region shows the honest **"No
   automated medication-safety checking is configured"** state. It is display-only + advisory-only by
   construction — **zero interactive controls** (browser-verified): it cannot block, cannot compute a conflict,
   cannot suggest an alternative. A real partner's findings (when licensed) would render here as advisory notes,
   never auto-blocking.

## §2. THE FENCE — grep/structural proof (RESOLVED as a certified-partner NON-GOAL)

- **Zero homemade conflict logic** — a codebase grep for cross-reactivity / class-match / contraindication /
  drug-allergy interaction / auto-block / therapeutic substitution returns NOTHING but the seam's own
  "we-don't-do-this" documentation. Locked by `tests/Feature/Clinical/AllergyAlertDisplayTest.php`:
  the bound `MedicationSafetyProvider` **is** the null-object (the only path); CareOS **constructs no**
  `SafetyAlert` anywhere in `Modules/Clinical/src` or `Modules/Pharmacy/src`; the `allergies` table has **no**
  drug-class / cross-reactivity / category column (class-matching is structurally impossible); and `AllergyGuard`
  is **exact-match only** — a Penicillin allergy does NOT trip the guard for Amoxicillin (a same-class drug),
  while it DOES catch the exact recorded substance (the deterministic guard, unchanged).
- **The wireframe's auto-block / no-override / suggested-alternative is NOT built** — it is the certified partner's
  advisory, human-owned output, and there is no partner today. Honest state: recorded allergies shown; automated
  checking shown as not-configured (the seam awaits a certified partner). See the seam contract
  `Modules\Pharmacy\src\Contracts\MedicationSafetyProvider` + `NullMedicationSafetyProvider` + `DECISIONS.md`
  (D-121, D-P0D.G3) + `docs/HOSPITAL-PHASE2-PHARMACY-MAP.md` §3.

**Status: the SAFE part (record-display + empty advisory seam) is RESOLVED (ALLERGY.P1). The computed
drug-allergy cross-reactivity / class-match / auto-block / substitution remains a certified-partner medical-device
NON-GOAL, seam-gated — never built homemade.**
