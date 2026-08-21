<script setup lang="ts">
/*
 * N3 — one read-audit row's chrome (PC.P1).
 *
 * A record's access log answers "who opened this, when, and on what basis". Every value here is
 * CALLER-SUPPLIED and comes from an append-only audit row: this component derives nothing.
 *
 * THE BASIS IS SERVER-DERIVED, NEVER SELF-DECLARED. The wireframe's access log distinguishes a
 * care-team read from a reception read, an agent read and an operator-mode read — and that
 * distinction must come from the caller's role + grant on the server, exactly as the audit rows
 * record it. This component only prints the label it is given; it must never infer a basis from
 * the actor's name or the surface, because a self-declared basis is worth nothing in an audit.
 *
 * PURELY PRESENTATIONAL (P0D.GU): no computation, no ordering, no grouping, and no tone prop —
 * a read is a fact, not a severity. An agent read is marked with the same ✦ the rest of the app
 * uses, which is an ATTRIBUTION, not a judgment.
 */

defineProps<{
    /** Who — already resolved to a display name by the caller. */
    actor: string;
    /** What they did, in the caller's own words ("opened the record"). */
    action: string;
    /** When — already formatted by the caller. */
    at: string;
    /** The surface touched (e.g. "patient_360"), recorded on the audit row. */
    surface?: string | null;
    /** The server-derived access basis label. Printed, never inferred here. */
    basis?: string | null;
    /** True when the actor was an agent — an attribution mark, not a grade. */
    isAgent?: boolean;
}>();
</script>

<template>
    <li class="flex items-start gap-3 border-b border-line/60 pb-2 last:border-0 last:pb-0">
        <span
            class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-euca-50 text-[0.6rem] font-semibold text-euca-700"
            aria-hidden="true"
        >{{ isAgent ? '✦' : actor.slice(0, 2) }}</span>

        <span class="min-w-0 flex-1">
            <span class="block text-sm text-ink">
                <span class="font-medium">{{ actor }}</span>
                <span class="text-ink-muted"> {{ action }}</span>
            </span>
            <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-subtle">
                <span v-if="basis" class="rounded-full bg-surface-2 px-2 py-0.5">{{ basis }}</span>
                <span v-if="surface" class="font-mono">{{ surface }}</span>
                <span>{{ at }}</span>
            </span>
            <slot name="detail" />
        </span>
    </li>
</template>
