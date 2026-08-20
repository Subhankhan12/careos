<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { colour } from './toothConditionColour';

/*
 * S2 — the FDI tooth-arch widget (DENTAL-B.P1).
 *
 * EXTRACTED from Pages/Dental/Odontogram.vue, behaviour-IDENTICAL: the same anatomical
 * ordering, the same per-surface mini-diagram, the same selection ring, the same
 * strike-through for a missing tooth, the same FACTUAL chart key, and the same scoped
 * styles — moved, not rewritten. Six dental surfaces draw this arch (Odontogram, Perio,
 * Endo, RCT, Crown, Ortho); they now share one widget rather than six copies.
 *
 * PURELY PRESENTATIONAL (P0D.GU). It groups the SERVER-provided chart by tooth and arranges
 * the domain-provided tooth set anatomically. It computes NO index (no DMFT/dmft), NO finding
 * count, NO "site to watch", NO severity, NO trend — the wireframes draw all of those and
 * DENTAL-BATCH-DIFF.md §5.1 rules every one MUST-NOT-BUILD. The chart key states which
 * condition a colour records; it is not a scale.
 */

const { t } = useI18n();

interface ChartEntry {
    tooth: string;
    surface: string | null;
    condition: string;
}

const props = defineProps<{
    /** The tooth set for the active dentition, supplied by the server. */
    teeth: string[];
    /** The patient's CURRENT charted state, supplied by the server. */
    chart: ChartEntry[];
    /** The server's condition vocabulary, used for the factual chart key. */
    conditions: { wholeTooth: string[]; surface: string[] };
    /** The currently selected tooth, owned by the caller. */
    selected?: string | null;
    /**
     * Optional FDI → US Universal cross-reference, computed in the domain. When supplied,
     * each tooth carries both names in its accessible label — a NOTATION lookup, never a
     * clinical statement about the tooth.
     */
    universal?: Record<string, string>;
}>();

const emit = defineEmits<{ (e: 'select', tooth: string): void }>();

// Group the SERVER-provided current chart by tooth (pure presentation — no domain logic).
const byTooth = computed(() => {
    const map: Record<string, { whole: string | null; surfaces: Record<string, string> }> = {};
    for (const r of props.chart) {
        if (!map[r.tooth]) map[r.tooth] = { whole: null, surfaces: {} };
        if (r.surface === null) map[r.tooth].whole = r.condition;
        else map[r.tooth].surfaces[r.surface] = r.condition;
    }
    return map;
});

// Arrange the domain-provided tooth set anatomically (presentation): patient's right on the
// chart's left, descending; patient's left ascending.
function quadrant(tooth: string): number {
    return Number(tooth[0]);
}
function toothNum(tooth: string): number {
    return Number(tooth[1]);
}
function archRow(upper: boolean): { right: string[]; left: string[] } {
    const rightQ = upper ? [1, 5] : [4, 8];
    const leftQ = upper ? [2, 6] : [3, 7];
    const teeth = props.teeth;
    return {
        right: teeth.filter((tth) => rightQ.includes(quadrant(tth))).sort((a, b) => toothNum(b) - toothNum(a)),
        left: teeth.filter((tth) => leftQ.includes(quadrant(tth))).sort((a, b) => toothNum(a) - toothNum(b)),
    };
}
const upperArch = computed(() => archRow(true));
const lowerArch = computed(() => archRow(false));

// The chart key, split by the SERVER's two condition vocabularies (surface vs whole-tooth).
// This is a grouping by SCOPE — where a condition can be charted — not a ranking. Order
// within each group is the server's own, untouched.
const legendGroups = computed(() => [
    { key: 'surface', conditions: props.conditions.surface },
    { key: 'wholeTooth', conditions: props.conditions.wholeTooth },
]);

// The US Universal cross-reference for a tooth, when the caller supplies the map.
function universalOf(tooth: string): string | null {
    return props.universal?.[tooth] ?? null;
}

function toothTitle(tooth: string): string {
    const universal = universalOf(tooth);
    return universal === null ? tooth : t('dental.notation.cross', { fdi: tooth, universal });
}
</script>

<template>
    <div v-for="(arch, idx) in [upperArch, lowerArch]" :key="idx" class="overflow-x-auto">
        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ idx === 0 ? t('dental.upper') : t('dental.lower') }}</p>
        <div class="flex items-start gap-3">
            <div class="flex gap-1">
                <button
                    v-for="tth in arch.right"
                    :key="tth"
                    type="button"
                    class="tooth"
                    :title="toothTitle(tth)"
                    :aria-label="toothTitle(tth)"
                    :class="{ 'tooth-selected': selected === tth, 'tooth-missing': byTooth[tth]?.whole === 'missing' }"
                    @click="emit('select', tth)"
                >
                    <span class="tooth-no">{{ tth }}</span>
                    <span class="surfaces">
                        <span class="s-b" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.buccal) }"></span>
                        <span class="s-m" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.mesial) }"></span>
                        <span class="s-o" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.occlusal) }"></span>
                        <span class="s-d" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.distal) }"></span>
                        <span class="s-l" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.lingual) }"></span>
                    </span>
                    <span v-if="byTooth[tth]?.whole && byTooth[tth]?.whole !== 'present'" class="tooth-mark" :style="{ color: colour(byTooth[tth]?.whole) }">●</span>
                </button>
            </div>
            <div class="w-px self-stretch bg-line"></div>
            <div class="flex gap-1">
                <button
                    v-for="tth in arch.left"
                    :key="tth"
                    type="button"
                    class="tooth"
                    :title="toothTitle(tth)"
                    :aria-label="toothTitle(tth)"
                    :class="{ 'tooth-selected': selected === tth, 'tooth-missing': byTooth[tth]?.whole === 'missing' }"
                    @click="emit('select', tth)"
                >
                    <span class="tooth-no">{{ tth }}</span>
                    <span class="surfaces">
                        <span class="s-b" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.buccal) }"></span>
                        <span class="s-m" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.mesial) }"></span>
                        <span class="s-o" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.occlusal) }"></span>
                        <span class="s-d" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.distal) }"></span>
                        <span class="s-l" :style="{ backgroundColor: colour(byTooth[tth]?.surfaces.lingual) }"></span>
                    </span>
                    <span v-if="byTooth[tth]?.whole && byTooth[tth]?.whole !== 'present'" class="tooth-mark" :style="{ color: colour(byTooth[tth]?.whole) }">●</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Chart key: a FACTUAL legend of charted conditions, not a severity scale.
         DENTAL-B.P2 polish — the two vocabularies are labelled and the swatches sit in a
         calmer grid. The key is still UNORDERED within each group and still carries the
         on-screen note that colour is not severity: presentation changed, meaning did not. -->
    <div class="rounded-2xl border border-line bg-surface-2/60 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('dental.legend.title') }}</p>
        <p class="mt-0.5 text-xs text-ink-subtle">{{ t('dental.legend.note') }}</p>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div v-for="group in legendGroups" :key="group.key">
                <p class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-subtle">{{ t(`dental.legend.${group.key}`) }}</p>
                <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1.5">
                    <span v-for="c in group.conditions" :key="c" class="inline-flex items-center gap-1.5 text-xs text-ink">
                        <span class="inline-block h-3 w-3 rounded-sm border border-line" :style="{ backgroundColor: colour(c) }"></span>
                        {{ t(`dental.conditions.${c}`) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.tooth {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    width: 34px;
    padding: 4px 2px;
    border: 1px solid var(--color-line);
    border-radius: 6px;
    background: var(--color-surface);
}
.tooth-selected {
    outline: 2px solid var(--color-euca-500);
    outline-offset: 1px;
}
.tooth-missing {
    opacity: 0.45;
}
.tooth-missing .tooth-no {
    text-decoration: line-through;
}
.tooth-no {
    font-size: 10px;
    font-weight: 600;
    color: var(--color-ink-muted);
    font-family: ui-monospace, monospace;
}
/* Mini per-surface diagram: buccal(top) / mesial-occlusal-distal(mid) / lingual(bottom). */
.surfaces {
    display: grid;
    grid-template-columns: repeat(3, 8px);
    grid-template-rows: repeat(3, 8px);
    gap: 1px;
}
.surfaces > span {
    border: 1px solid var(--color-line);
    border-radius: 1px;
}
.s-b {
    grid-area: 1 / 2 / 2 / 3;
}
.s-m {
    grid-area: 2 / 1 / 3 / 2;
}
.s-o {
    grid-area: 2 / 2 / 3 / 3;
}
.s-d {
    grid-area: 2 / 3 / 3 / 4;
}
.s-l {
    grid-area: 3 / 2 / 4 / 3;
}
.tooth-mark {
    position: absolute;
    top: 2px;
    right: 3px;
    font-size: 8px;
    line-height: 1;
}
</style>
