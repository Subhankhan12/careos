<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/*
 * S1 — the patient clinical header shared by the dental surfaces (DENTAL-B.P1).
 *
 * PURELY PRESENTATIONAL (P0D.GU). It DISPLAYS what the caller passes and computes NOTHING:
 * no age arithmetic, no date parsing, no derived label, no clinical judgment. `age` and
 * `dateOfBirth` arrive as ALREADY-FORMATTED strings from the caller — this component never
 * constructs a Date (a date-only value parsed here would shift a day for behind-UTC viewers,
 * D-091) and never subtracts one date from another.
 *
 * The allergy chip renders RECORDED allergy facts only (ALLERGY.P1): the substance the
 * clinician documented, plus the reaction and the severity THEY recorded, shown verbatim as
 * text. The chip's styling is CONSTANT — it is never keyed to severity, never ranked, never
 * ordered by badness. This component does not compute cross-reactivity, does not infer risk,
 * and does not decide which allergy matters most; it shows what is on the record.
 */

defineProps<{
    /** Caller-supplied identity fields, displayed verbatim. */
    patient: {
        name: string;
        mrn?: string | null;
        /** Already formatted for display by the caller — never parsed here. */
        dateOfBirth?: string | null;
        /** Already computed for display by the caller — never derived here. */
        age?: string | null;
        sex?: string | null;
    };
    /** Recorded allergy facts (ALLERGY.P1). Displayed as recorded; never graded or reordered. */
    allergies?: Array<{ id: string; substance: string; reaction?: string | null; severity?: string | null }>;
    /** Small caps line above the name, e.g. the section the user is in. */
    eyebrow?: string | null;
    /** A caller-supplied context line, e.g. "SPT visit · today · probed by Dr Ferrari". */
    context?: string | null;
    /** Patient-360 link target (the existing patient chart route), supplied by the caller. */
    chartHref?: string | null;
    /** Label for the patient-360 link. */
    chartLabel?: string | null;
    /** Shown when the caller passed no allergies at all. */
    noAllergiesLabel?: string | null;
    /*
     * PC.P3 — the props Patient 360's hero needs. All OPTIONAL, so the dental callers that
     * omit them render exactly as before: this header is EXTENDED, never forked.
     */
    /**
     * The patient's RECORDED lifecycle status (active / inactive / merged / deceased). An
     * administrative fact the record already holds — never a computed state, and the pill is
     * styled identically whatever the value, so it ranks nothing.
     */
    status?: string | null;
    /**
     * Extra contextual links (e.g. "Open dental chart"). The caller decides what is offered and
     * has already applied whatever permission governs it — this component gates nothing.
     */
    links?: Array<{ key: string; label: string; href: string }>;
    /**
     * How much room this header takes.
     *
     *  - `compact` (the DEFAULT) is the band the dental surfaces have always rendered. Leaving
     *    it the default is what keeps those callers byte-identical.
     *  - `hero` is Patient 360's larger treatment: an initials avatar, a display-size name and
     *    the ivory watermark. Purely presentational — the hero shows no fact the compact band
     *    would hide.
     */
    variant?: 'compact' | 'hero';
    /** Initials for the hero avatar, composed by the caller from the recorded name. */
    initials?: string | null;
}>();
</script>

<template>
    <div
        class="euca-tile-dark rounded-2xl p-5 text-white"
        :class="variant === 'hero' ? 'relative overflow-hidden md:p-6 md:pl-24' : ''"
    >
        <!-- HERO ONLY, and purely additive: an absolutely-positioned avatar and watermark, so
             the COMPACT band's markup is untouched and the dental callers render byte-for-byte
             as before. Both are decoration — neither carries a fact. -->
        <template v-if="variant === 'hero'">
            <svg
                class="pointer-events-none absolute -right-6 -top-8 h-44 w-44 text-white/5"
                viewBox="0 0 200 200"
                fill="none"
                aria-hidden="true"
            >
                <path d="M40 170C40 100 70 55 160 45c-4 78-44 118-120 125z" fill="currentColor" />
            </svg>
            <span
                v-if="initials"
                class="absolute left-5 top-6 hidden h-14 w-14 items-center justify-center rounded-full bg-white/15 text-lg font-semibold text-euca-50 md:flex"
                aria-hidden="true"
            >{{ initials }}</span>
        </template>

        <p v-if="eyebrow" class="text-xs font-semibold uppercase tracking-[0.14em] text-white/70">{{ eyebrow }}</p>

        <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="font-semibold tracking-tight" :class="variant === 'hero' ? 'text-3xl' : 'text-2xl'">{{ patient.name }}</h1>
            <span v-if="patient.mrn" class="font-mono text-sm text-white/70">{{ patient.mrn }}</span>
            <!-- The RECORDED lifecycle status. One styling for every value: the pill states
                 what the record says, it does not rank patients. -->
            <span
                v-if="status"
                class="inline-flex items-center gap-1.5 rounded-full bg-euca-300/25 px-2.5 py-1 text-xs font-semibold text-euca-50"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-euca-200"></span>{{ status }}
            </span>
        </div>

        <!-- Identity facts, each displayed exactly as the caller supplied it. -->
        <p v-if="patient.dateOfBirth || patient.age || patient.sex" class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-white/70">
            <span v-if="patient.dateOfBirth">{{ patient.dateOfBirth }}</span>
            <span v-if="patient.age" class="text-white/50">·</span>
            <span v-if="patient.age">{{ patient.age }}</span>
            <span v-if="patient.sex" class="text-white/50">·</span>
            <span v-if="patient.sex">{{ patient.sex }}</span>
        </p>

        <p v-if="context" class="mt-1 text-sm text-white/70">{{ context }}</p>

        <!-- Recorded allergies. Constant chip styling for every entry — the chip states a
             documented fact; it never signals how bad that fact is. -->
        <div v-if="allergies && allergies.length" class="mt-3 flex flex-wrap items-center gap-2">
            <span
                v-for="allergy in allergies"
                :key="allergy.id"
                class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs text-white"
            >
                <span class="font-semibold">{{ allergy.substance }}</span>
                <span v-if="allergy.reaction" class="text-white/75">{{ allergy.reaction }}</span>
                <span v-if="allergy.severity" class="text-white/75">{{ allergy.severity }}</span>
            </span>
        </div>
        <p v-else-if="noAllergiesLabel" class="mt-3 text-xs text-white/60">{{ noAllergiesLabel }}</p>

        <Link
            v-if="chartHref"
            :href="chartHref"
            class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-white/90 underline-offset-4 hover:underline"
        >
            {{ chartLabel ?? patient.name }}
            <span aria-hidden="true">→</span>
        </Link>

        <!-- Caller-supplied contextual links. The caller has already decided which of these the
             user may see; this header offers no permission logic of its own. -->
        <div v-if="links && links.length" class="mt-3 flex flex-wrap items-center gap-2">
            <Link
                v-for="link in links"
                :key="link.key"
                :href="link.href"
                class="rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25"
            >
                {{ link.label }} →
            </Link>
        </div>
    </div>
</template>
