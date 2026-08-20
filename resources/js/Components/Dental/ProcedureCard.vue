<script setup lang="ts">
/*
 * S5 — the procedure / treatment-phase card chrome (DENTAL-B.P1).
 *
 * PURELY PRESENTATIONAL (P0D.GU). The card does NO ARITHMETIC. Every money figure arrives as
 * an ALREADY-FORMATTED string that the caller obtained from the billing engine — this
 * component never divides minor units, never sums items, never totals a phase, never applies
 * a tax point or a point value. CareOS money math lives in the engine and reconciles to the
 * unit (δ=0); a display card is the last place it may be re-derived.
 *
 * `done` is a LIFECYCLE fact the caller passes (the item was performed), not a clinical or
 * severity judgment, and it is the only thing that changes the pill. There is no tone prop,
 * no priority, no ranking.
 */

defineProps<{
    /** Tenant-authored procedure code, e.g. "D-CROWN". */
    code?: string | null;
    /** Procedure or phase name. */
    name?: string | null;
    /** FDI tooth code, displayed verbatim. */
    tooth?: string | null;
    /** Surface, already translated by the caller. */
    surface?: string | null;
    /** ENGINE-SUPPLIED, already-formatted money string, e.g. "CHF 1240.00". Never computed here. */
    amount?: string | null;
    /** Caller-supplied status label, already translated. */
    statusLabel?: string | null;
    /** Lifecycle fact: the item has been performed. */
    done?: boolean;
}>();
</script>

<template>
    <div class="rounded-2xl border border-line bg-surface p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2">
                    <span v-if="code" class="font-mono text-xs text-ink-muted">{{ code }}</span>
                    <span v-if="name" class="font-semibold text-ink">{{ name }}</span>
                </p>
                <p v-if="tooth || surface" class="mt-0.5 text-sm text-ink-muted">
                    <span v-if="tooth">{{ tooth }}</span>
                    <span v-if="tooth && surface"> · </span>
                    <span v-if="surface">{{ surface }}</span>
                </p>
                <slot name="detail" />
            </div>

            <div class="flex shrink-0 flex-col items-end gap-1.5">
                <!-- Engine-supplied string, rendered as received. -->
                <span v-if="amount" class="font-semibold text-ink">{{ amount }}</span>
                <span
                    v-if="statusLabel"
                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                    :class="done ? 'bg-success-soft text-success' : 'bg-euca-50 text-euca-700'"
                >
                    {{ statusLabel }}
                </span>
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>
