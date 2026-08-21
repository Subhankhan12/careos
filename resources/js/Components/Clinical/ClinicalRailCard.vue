<script setup lang="ts">
/*
 * N1 — the clinical rail card (PC.P1).
 *
 * The container the chart's rails sit in: problems, medications, allergies, care plans,
 * referrals, recalls. It is a TITLED BOX with a slot for caller-supplied rows and an honest
 * empty state — nothing more.
 *
 * PURELY PRESENTATIONAL (P0D.GU). It computes NOTHING: no count is derived here (a caller that
 * wants one passes it), no row is sorted, ranked or filtered, and there is no tone/severity/
 * trend prop by which a caller could tint the card from a clinical value (D-166/D-169).
 *
 * `count` is a caller-supplied STRING so this component cannot be handed a number to do
 * arithmetic on; the caller has already decided what, if anything, is worth counting — and per
 * the batch audit a count on these rails is a factual row count, never a clinical index.
 */

defineProps<{
    title: string;
    /** Optional caller-supplied count, already a display string. */
    count?: string | null;
    /** Shown when the caller renders no rows. */
    emptyLabel?: string | null;
    /** True when the caller has nothing to show — the caller decides, not this component. */
    isEmpty?: boolean;
}>();
</script>

<template>
    <section class="rounded-2xl border border-line bg-surface p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-ink">{{ title }}</h2>
            <span v-if="count" class="rounded-full bg-euca-50 px-2 py-0.5 text-xs font-semibold text-euca-700">{{ count }}</span>
        </div>

        <p v-if="isEmpty && emptyLabel" class="mt-2 text-sm text-ink-muted">{{ emptyLabel }}</p>
        <div v-else class="mt-2 space-y-2">
            <slot />
        </div>

        <div v-if="$slots.footer" class="mt-3 border-t border-line pt-3">
            <slot name="footer" />
        </div>
    </section>
</template>
