<script setup lang="ts">
/*
 * S4 — the clinical stat-tile SHELL (DENTAL-B.P1).
 *
 * ⚠️ THE TILES ARE WHERE THE FENCE GETS BREACHED. The dental wireframes fill this exact tile
 * with BOP %, DMFT, mean pocket depth, plaque score, "1 finding", "one site to watch" and
 * trend arrows ("▼ from 3.1", "plateau") — every one of which is a COMPUTED clinical judgment
 * and MUST NOT be built (DENTAL-BATCH-DIFF.md §5.1).
 *
 * So this component ships the CHROME and nothing else, and it is deliberately CLOSED:
 *
 *   - it takes a caller-supplied `label`, a caller-supplied `value` STRING, and an optional
 *     caller-supplied `unit` and `caption`;
 *   - it has NO slot, so a caller cannot inject arbitrary content into the tile;
 *   - it has NO tone/colour/status/trend/direction prop, so a tile can never be tinted by
 *     severity or annotated with a direction of travel;
 *   - it performs NO arithmetic, NO percentage, NO rounding, NO comparison, NO thresholding.
 *     `value` is rendered exactly as received.
 *
 * The constraint IS the feature: this tile can only ever state a recorded fact. It is not a
 * variant of the generic Components/StatCard.vue — that one exposes an icon slot, which would
 * reopen the hole this component exists to close.
 *
 * A structural test (tests/Feature/Dental/SharedComponentsTest.php) asserts the absence of
 * computation and of any severity/trend affordance here.
 */

withDefaults(
    defineProps<{
        /** The fact's name, supplied by the caller. */
        label: string;
        /** The fact itself, ALREADY a display string. Never computed, never reformatted. */
        value?: string | null;
        /** e.g. "mm" or "sites" — supplied by the caller, appended verbatim. */
        unit?: string | null;
        /** Provenance/qualifier line, e.g. "recorded 12.03.2026". */
        caption?: string | null;
    }>(),
    { value: '—', unit: null, caption: null },
);
</script>

<template>
    <div class="rounded-2xl border border-line bg-surface p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ label }}</p>
        <p class="mt-2 flex items-baseline gap-1 text-ink">
            <span class="text-2xl font-semibold">{{ value }}</span>
            <span v-if="unit" class="text-sm text-ink-muted">{{ unit }}</span>
        </p>
        <p v-if="caption" class="mt-1 text-xs text-ink-subtle">{{ caption }}</p>
    </div>
</template>
