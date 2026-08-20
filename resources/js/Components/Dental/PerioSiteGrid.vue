<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/*
 * The full-mouth 6-point probing grid (DENTAL-B.P3).
 *
 * LAYOUT + ENTRY ERGONOMICS ONLY. It renders one column per tooth and one row per probing
 * site — the buccal three, then the lingual three, as a periodontist reads a chart — and moves
 * the caret with the arrow keys so a whole arch can be probed without touching the mouse. It
 * writes the SAME raw values through the SAME recording path as before.
 *
 * ELECTRIC FENCE — this is the most computed screen in the dental pack after Endo, and every
 * one of the mock's right-rail figures is refused here:
 *
 *   NO BOP %, NO "sites ≥ 4 mm" count, NO mean/average pocket depth, NO plaque score,
 *   NO trend arrow, delta or direction label ("▼ from 3.1", "plateau"), NO "site to watch",
 *   NO severity colour band keyed to depth, NO imaging finding.
 *
 * Two consequences are deliberate and easy to get wrong:
 *
 *  - EVERY CELL LOOKS THE SAME. A colour scale keyed to depth ranges IS a severity ramp, so a
 *    6 mm pocket is styled exactly like a 2 mm one. The dentist reads the numbers.
 *  - A PRIOR VALUE IS A NUMBER, NOT A DIRECTION. Where a previous exam's reading is shown it
 *    is printed as the raw recorded figure. Saying "3 → 5" with an arrow, a delta or a word
 *    like "deepened" would be the judgment; showing both numbers is the record.
 *
 * Bleeding-on-probing is a RECORDED OBSERVATION (the clinician saw it or did not), so its
 * marker is a fact, not a grade — and it is styled identically whatever the depth beside it.
 */

const { t } = useI18n();

export interface SiteEntry {
    pocket_depth_mm: string;
    recession_mm: string;
    bleeding_on_probing: boolean;
}

const props = defineProps<{
    /** The teeth to render as columns, in the caller's anatomical order. */
    teeth: string[];
    /** The six probing sites, from the domain. */
    sites: string[];
    /** Entry state owned by the caller: tooth → site → raw strings. Mutated in place. */
    entry: Record<string, Record<string, SiteEntry>>;
    /**
     * Optional PRIOR RECORDED pocket depths (tooth → site → mm) from an earlier exam, shown
     * beneath the input as a plain number. No delta is derived from it, ever.
     */
    prior?: Record<string, Record<string, number | null>>;
    /** The date of the exam the prior values came from, for an honest caption. */
    priorLabel?: string | null;
    disabled?: boolean;
}>();

// The six sites split into the two rows a perio chart is read in. This is ANATOMY (which
// aspect of the tooth the site is on), not a ranking.
const buccalSites = computed(() => props.sites.slice(0, 3));
const lingualSites = computed(() => props.sites.slice(3, 6));
const rowSites = computed(() => [...buccalSites.value, ...lingualSites.value]);

const focusedTooth = ref<string | null>(null);

const inputs = ref<Record<string, HTMLInputElement | null>>({});

function cellKey(tooth: string, site: string): string {
    return `${tooth}:${site}`;
}

function setInput(tooth: string, site: string, el: unknown): void {
    inputs.value[cellKey(tooth, site)] = el as HTMLInputElement | null;
}

function priorFor(tooth: string, site: string): number | null {
    return props.prior?.[tooth]?.[site] ?? null;
}

/**
 * Arrow-key movement across the grid — left/right between teeth, up/down between sites.
 * Enter behaves like "down", so a column can be probed top to bottom in one run.
 */
function move(tooth: string, site: string, dCol: number, dRow: number, event: KeyboardEvent): void {
    const col = props.teeth.indexOf(tooth);
    const row = rowSites.value.indexOf(site);
    if (col === -1 || row === -1) return;

    const nextCol = col + dCol;
    const nextRow = row + dRow;
    if (nextCol < 0 || nextCol >= props.teeth.length || nextRow < 0 || nextRow >= rowSites.value.length) return;

    event.preventDefault();
    const target = inputs.value[cellKey(props.teeth[nextCol], rowSites.value[nextRow])];
    target?.focus();
    target?.select();
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-ink-subtle">{{ t('perioGrid.hint') }}</p>
            <p v-if="priorLabel" class="text-xs text-ink-subtle">{{ t('perioGrid.priorCaption', { date: priorLabel }) }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-separate border-spacing-0 text-sm">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-surface px-2 py-1 text-left text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-subtle">
                            {{ t('perioGrid.site') }}
                        </th>
                        <th
                            v-for="tooth in teeth"
                            :key="tooth"
                            scope="col"
                            class="px-1 py-1 text-center font-mono text-xs"
                            :class="focusedTooth === tooth ? 'text-ink' : 'text-ink-muted'"
                        >
                            <span class="inline-block rounded px-1" :class="focusedTooth === tooth ? 'bg-euca-50' : ''">{{ tooth }}</span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <!-- Template refs are auto-unwrapped here, so these are the arrays themselves. -->
                    <template v-for="(group, gi) in [buccalSites, lingualSites]" :key="gi">
                        <tr>
                            <th
                                :colspan="teeth.length + 1"
                                scope="colgroup"
                                class="sticky left-0 bg-surface px-2 pt-3 pb-1 text-left text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-subtle"
                            >
                                {{ gi === 0 ? t('perioGrid.buccal') : t('perioGrid.lingual') }}
                            </th>
                        </tr>
                        <tr v-for="site in group" :key="site" class="align-top">
                            <th scope="row" class="sticky left-0 z-10 whitespace-nowrap bg-surface px-2 py-1 text-left text-xs font-medium text-ink-muted">
                                {{ t(`perio.sites.${site}`) }}
                            </th>
                            <td v-for="tooth in teeth" :key="tooth" class="px-0.5 py-0.5 text-center">
                                <input
                                    :ref="(el) => setInput(tooth, site, el)"
                                    v-model="entry[tooth][site].pocket_depth_mm"
                                    type="number"
                                    min="0"
                                    max="15"
                                    inputmode="numeric"
                                    :disabled="disabled"
                                    :aria-label="t('perioGrid.cellLabel', { tooth, site: t(`perio.sites.${site}`) })"
                                    class="h-8 w-11 rounded-md border border-line bg-surface text-center font-mono text-sm text-ink focus:border-euca-600 focus:ring-2 focus:ring-euca-500/30"
                                    @focus="focusedTooth = tooth"
                                    @keydown.left="move(tooth, site, -1, 0, $event)"
                                    @keydown.right="move(tooth, site, 1, 0, $event)"
                                    @keydown.up="move(tooth, site, 0, -1, $event)"
                                    @keydown.down="move(tooth, site, 0, 1, $event)"
                                    @keydown.enter="move(tooth, site, 0, 1, $event)"
                                />

                                <!-- Bleeding on probing: a RECORDED observation, styled the same
                                     whatever number sits above it. -->
                                <label class="mt-0.5 flex items-center justify-center gap-1 text-[0.6rem] text-ink-subtle">
                                    <input
                                        v-model="entry[tooth][site].bleeding_on_probing"
                                        type="checkbox"
                                        :disabled="disabled"
                                        :aria-label="t('perioGrid.bopLabel', { tooth, site: t(`perio.sites.${site}`) })"
                                        class="h-3 w-3 rounded border-line text-euca-600"
                                    />
                                    {{ t('perioGrid.bopShort') }}
                                </label>

                                <!-- The PRIOR recorded reading: a plain number, no direction. -->
                                <p v-if="priorFor(tooth, site) !== null" class="mt-0.5 font-mono text-[0.6rem] text-ink-subtle">
                                    {{ t('perioGrid.prior', { value: priorFor(tooth, site) }) }}
                                </p>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
