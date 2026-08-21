<script setup lang="ts">
/*
 * N6 — the sign-off action bar (PC.P1).
 *
 * The "dark moment" bar that sits under a note, a care-plan review or a consult summary. It is
 * CHROME: a status line, a caller-supplied readiness label, and the actions the CALLER passes in
 * through the default slot.
 *
 * IT PERFORMS NO SIGNING LOGIC AND GRANTS NOTHING. It does not decide whether signing is
 * allowed, does not know what a required section is, and never calls an endpoint. The server
 * authorises signing exactly as it does today (`note.sign` re-checked server-side, required
 * sections re-validated); this component cannot widen that, because it holds no permission and
 * issues no request. Disabling a button here hides an affordance — it is not a gate, in the same
 * way DENTAL-B.P2's read mode was a UI mode and not a permission (D-168).
 *
 * PURELY PRESENTATIONAL (P0D.GU): no computation, no arithmetic, no tone/severity/trend prop.
 * `readiness` is a caller-supplied STRING ("2 of 3 required sections filled") — this component
 * never counts anything itself.
 */

defineProps<{
    /** What is being signed, in the caller's words. */
    label: string;
    /** Caller-supplied readiness line. Already composed; nothing is counted here. */
    readiness?: string | null;
    /** A caller-supplied caveat, e.g. that signing is permanent. */
    note?: string | null;
}>();
</script>

<template>
    <div class="euca-tile-dark flex flex-wrap items-center justify-between gap-3 rounded-2xl p-4 text-white">
        <div class="min-w-0">
            <p class="text-sm font-semibold">{{ label }}</p>
            <p v-if="readiness" class="mt-0.5 text-xs text-white/70">{{ readiness }}</p>
            <p v-if="note" class="mt-0.5 text-xs text-white/60">{{ note }}</p>
        </div>

        <!-- The actions are the caller's. This bar renders them; it never performs them. -->
        <div class="flex flex-wrap items-center gap-2">
            <slot />
        </div>
    </div>
</template>
