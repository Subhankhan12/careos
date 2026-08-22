<script setup lang="ts">
/*
 * PortalPageHeader (PT.P2) — the patient-facing page header the portal screens repeat.
 *
 * THIS IS DELIBERATELY NOT S1. `PatientClinicalHeader` is a STAFF component: it renders MRN,
 * date of birth, sex and the recorded-allergy chips on a dark clinical tile, because a clinician
 * opening a record needs to identify the patient and see what they are allergic to. A patient
 * reading their own record needs none of that — they know who they are, they do not need their
 * medical record number quoted back at them, and their allergy list is not a page banner. Framing
 * a patient's own portal in staff chrome would be a category error, so S1 is left untouched and
 * this is a separate component in the portal namespace.
 *
 * WHAT IT SHOWS: an eyebrow naming the section in the practice's words, the page title, and an
 * optional lead line. WHAT IT DELIBERATELY DOES NOT: no MRN, no date of birth, no sex, no allergy
 * tile, no clinical status — and no dark tile, because the portal's whole visual language is the
 * lighter one (16px base, clinic-branded, CareOS's own mark absent).
 *
 * PURELY PRESENTATIONAL (P0D.GU): it displays the strings the caller passes and computes nothing.
 * It carries no tone/severity/urgency prop, so nothing on a patient-facing page can be tinted by a
 * balance, an overdue date or any other value (D-166/D-169).
 */

defineProps<{
    /** Small caps line above the title, e.g. "Your practice · Billing". */
    eyebrow?: string | null;
    /** The page's own title, in the patient's language. */
    title: string;
    /** One calm sentence under the title, when the screen needs it. */
    lead?: string | null;
}>();
</script>

<!--
  The markup below is the EXACT block the portal screens already render, extracted verbatim so
  adopting this component changes no pixel (the PC.P1 precedent: the promotion was proven
  byte-for-byte). A fragment root, not a wrapping element, so the surrounding layout flow is
  identical too.
-->
<template>
    <p v-if="eyebrow" class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ eyebrow }}</p>
    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{{ title }}</h1>
    <p v-if="lead" class="mt-1 max-w-2xl text-ink-muted">{{ lead }}</p>
    <slot />
</template>
