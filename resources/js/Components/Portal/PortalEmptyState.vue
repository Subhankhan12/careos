<script setup lang="ts">
/*
 * PortalEmptyState (PT.P2) — the "nothing here yet" treatment the portal screens repeat.
 *
 * The portal mocks are consistent that an empty state on a PATIENT-facing screen should read as a
 * calm statement of fact, and where it makes sense, a next step — "Nothing booked yet — pick a
 * time whenever you're ready", "Try another date — mornings usually open up first" — rather than a
 * dead end. This renders that shape; the words stay the caller's (and the translators').
 *
 * PURELY PRESENTATIONAL (P0D.GU): no computation, no tone prop, nothing derived. An empty list is
 * an absence of records, never a judgment about the patient.
 */

defineProps<{
    /** The plain statement, e.g. "No documents shared yet." */
    message: string;
    /** An optional calm next step. Never an instruction, never an alarm. */
    hint?: string | null;
}>();
</script>

<template>
    <div class="glass-card p-6">
        <p class="text-sm text-ink-muted">{{ message }}</p>
        <p v-if="hint" class="mt-1 text-sm text-ink-subtle">{{ hint }}</p>
        <slot />
    </div>
</template>
