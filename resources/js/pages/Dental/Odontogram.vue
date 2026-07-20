<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t } = useI18n();
const page = usePage();

interface Record {
    id: string;
    tooth: string;
    surface: string | null;
    condition: string;
    note: string | null;
    reason?: string | null;
    charted_at: string;
    charted_by: number;
}

interface Performed {
    id: string;
    tooth: string | null;
    surface: string | null;
    code: string | null;
    name: string | null;
    note: string | null;
    performed_at: string;
}

const props = defineProps<{
    patient: { id: string; mrn: string; name: string; date_of_birth: string; sex: string };
    chart: Record[];
    history: Record[];
    teeth: { permanent: string[]; primary: string[] };
    surfaces: string[];
    conditions: { wholeTooth: string[]; surface: string[] };
    procedures: Array<{ id: string; code: string | null; name: string | null; tooth_scoped: boolean }>;
    branches: Array<{ id: string; name: string }>;
    performed: Performed[];
    actions: { can_chart: boolean; can_perform: boolean; store_url: string; perform_url: string };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// FACTUAL charted-condition legend (categorical, NOT a severity ramp). Each condition maps
// to a distinct hue so a dentist can DISTINGUISH what they charted — colour means "this is
// the condition recorded", never "this is how bad it is". No score/grade/gradient anywhere.
const CONDITION_COLOUR: Record<string, string> = {
    // whole-tooth
    present: 'transparent',
    missing: 'transparent',
    unerupted: 'transparent',
    implant: '#4a4a6a',
    pontic: '#8a7a5c',
    crown: '#b8862f',
    root_canal: '#7a3f6a',
    bridge_retainer: '#5c6a8a',
    // surface
    sound: 'transparent',
    caries: '#c0532e',
    restoration: '#3f6f9f',
    fracture: '#8a5cc0',
    sealant: '#3f9f8a',
    veneer: '#9f5c8a',
    erosion: '#7a8a3f',
    abrasion: '#6a6a6a',
} as unknown as Record<string, string>;

function colour(condition: string | null | undefined): string {
    if (!condition) return 'transparent';
    return CONDITION_COLOUR[condition] ?? '#9aa79a';
}

const dentition = ref<'permanent' | 'primary'>('permanent');
const selectedTooth = ref<string | null>(null);

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
    const teeth = props.teeth[dentition.value];
    return {
        right: teeth.filter((tth) => rightQ.includes(quadrant(tth))).sort((a, b) => toothNum(b) - toothNum(a)),
        left: teeth.filter((tth) => leftQ.includes(quadrant(tth))).sort((a, b) => toothNum(a) - toothNum(b)),
    };
}
const upperArch = computed(() => archRow(true));
const lowerArch = computed(() => archRow(false));

const historyForSelected = computed(() =>
    selectedTooth.value === null ? [] : props.history.filter((r) => r.tooth === selectedTooth.value).slice().reverse(),
);

// The condition vocabulary for the form comes from the server (P0D.GU) — whole-tooth set
// when no surface is chosen, surface set otherwise.
const conditionOptions = computed(() => (form.surface === '' ? props.conditions.wholeTooth : props.conditions.surface));

const form = useForm({ tooth: '', surface: '', condition: '', note: '', reason: '' });

function selectTooth(tooth: string): void {
    selectedTooth.value = tooth;
    form.tooth = tooth;
    form.surface = '';
    form.condition = '';
    form.note = '';
    form.reason = '';
    form.clearErrors();
}

function submit(): void {
    form.post(props.actions.store_url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset('condition', 'note', 'reason'),
    });
}

// Perform a procedure (G4): the resulting tooth-state options are the full G1 vocabulary
// (the dentist states the factual consequence, e.g. extraction -> missing). The service
// records the clinical fact + captures the charge + charts the tooth-state, atomically.
const allConditions = computed(() => [...props.conditions.wholeTooth, ...props.conditions.surface]);
const performedForSelected = computed(() =>
    selectedTooth.value === null ? [] : props.performed.filter((p) => p.tooth === selectedTooth.value),
);
const performForm = useForm({ dental_procedure_id: '', branch_id: props.branches.length === 1 ? props.branches[0].id : '', tooth: '', surface: '', tooth_state: '', note: '' });
function submitPerform(): void {
    performForm.tooth = selectedTooth.value ?? '';
    performForm.post(props.actions.perform_url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => performForm.reset('dental_procedure_id', 'surface', 'tooth_state', 'note'),
    });
}

function dateTime(iso: string): string {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <AppLayout>
        <Head :title="t('dental.title')" />
        <div class="space-y-6">
            <!-- Patient header tile. -->
            <div class="euca-tile-dark rounded-2xl p-5 text-white">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/70">{{ t('dental.eyebrow') }}</p>
                <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h1 class="text-2xl font-semibold tracking-tight">{{ patient.name }}</h1>
                    <span class="font-mono text-sm text-white/70">{{ patient.mrn }}</span>
                </div>
                <p class="mt-1 text-sm text-white/70">{{ t('dental.dob') }}: {{ patient.date_of_birth }}</p>
            </div>

            <p v-if="flash === 'charted'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">{{ t('dental.flash.charted') }}</p>

            <!-- Dentition toggle + fence note. -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="inline-flex rounded-xl border border-line bg-surface p-1">
                    <button
                        v-for="d in (['permanent', 'primary'] as const)"
                        :key="d"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold"
                        :class="dentition === d ? 'bg-euca-700 text-white' : 'text-ink-muted hover:text-ink'"
                        @click="dentition = d"
                    >
                        {{ t(`dental.dentition.${d}`) }}
                    </button>
                </div>
                <p v-if="!actions.can_chart" class="text-xs text-ink-subtle">{{ t('dental.readOnly') }}</p>
            </div>

            <div class="grid gap-6 lg:grid-cols-[1fr,20rem]">
                <!-- The chart. -->
                <div class="glass-card space-y-6 p-5">
                    <p class="text-sm text-ink-muted">{{ t('dental.clickHint') }}</p>

                    <div v-for="(arch, idx) in [upperArch, lowerArch]" :key="idx" class="overflow-x-auto">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ idx === 0 ? t('dental.upper') : t('dental.lower') }}</p>
                        <div class="flex items-start gap-3">
                            <div class="flex gap-1">
                                <button
                                    v-for="tth in arch.right"
                                    :key="tth"
                                    type="button"
                                    class="tooth"
                                    :class="{ 'tooth-selected': selectedTooth === tth, 'tooth-missing': byTooth[tth]?.whole === 'missing' }"
                                    @click="selectTooth(tth)"
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
                                    :class="{ 'tooth-selected': selectedTooth === tth, 'tooth-missing': byTooth[tth]?.whole === 'missing' }"
                                    @click="selectTooth(tth)"
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

                    <!-- Chart key: a FACTUAL legend of charted conditions, not a severity scale. -->
                    <div class="border-t border-line pt-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('dental.legend.title') }}</p>
                        <p class="mt-0.5 text-xs text-ink-subtle">{{ t('dental.legend.note') }}</p>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
                            <span v-for="c in [...conditions.surface, ...conditions.wholeTooth]" :key="c" class="inline-flex items-center gap-1.5 text-xs text-ink">
                                <span class="inline-block h-3 w-3 rounded-sm border border-line" :style="{ backgroundColor: colour(c) }"></span>
                                {{ t(`dental.conditions.${c}`) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Side panel: selected tooth detail, record form, history. -->
                <div class="glass-card p-5">
                    <p v-if="selectedTooth === null" class="text-sm text-ink-muted">{{ t('dental.selectPrompt') }}</p>
                    <div v-else class="space-y-5">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('dental.tooth') }}</p>
                            <p class="text-2xl font-semibold text-ink">{{ selectedTooth }}</p>
                        </div>

                        <!-- Current charted state (facts only). -->
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('dental.current') }}</p>
                            <ul class="mt-1 space-y-1 text-sm">
                                <li v-if="byTooth[selectedTooth]?.whole" class="flex items-center gap-2">
                                    <span class="inline-block h-3 w-3 rounded-sm border border-line" :style="{ backgroundColor: colour(byTooth[selectedTooth]?.whole) }"></span>
                                    <span class="text-ink">{{ t(`dental.conditions.${byTooth[selectedTooth]?.whole}`) }}</span>
                                </li>
                                <li v-for="(cond, surf) in byTooth[selectedTooth]?.surfaces ?? {}" :key="surf" class="flex items-center gap-2">
                                    <span class="inline-block h-3 w-3 rounded-sm border border-line" :style="{ backgroundColor: colour(cond) }"></span>
                                    <span class="text-ink-muted">{{ t(`dental.surfaces.${surf}`) }}:</span>
                                    <span class="text-ink">{{ t(`dental.conditions.${cond}`) }}</span>
                                </li>
                                <li v-if="!byTooth[selectedTooth]?.whole && Object.keys(byTooth[selectedTooth]?.surfaces ?? {}).length === 0" class="text-ink-subtle">{{ t('dental.nothingCharted') }}</li>
                            </ul>
                        </div>

                        <!-- Record a charted condition (through the append-only service). -->
                        <form v-if="actions.can_chart" class="space-y-3 border-t border-line pt-4" @submit.prevent="submit">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('dental.record') }}</p>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-ink">{{ t('dental.scope') }}</span>
                                <select v-model="form.surface" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink" @change="form.condition = ''">
                                    <option value="">{{ t('dental.wholeTooth') }}</option>
                                    <option v-for="s in surfaces" :key="s" :value="s">{{ t(`dental.surfaces.${s}`) }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-ink">{{ t('dental.condition') }}</span>
                                <select v-model="form.condition" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                    <option value="" disabled>{{ t('dental.chooseCondition') }}</option>
                                    <option v-for="c in conditionOptions" :key="c" :value="c">{{ t(`dental.conditions.${c}`) }}</option>
                                </select>
                                <span v-if="form.errors.condition" class="mt-1 block text-xs text-danger">{{ form.errors.condition }}</span>
                            </label>
                            <input v-model="form.note" type="text" :placeholder="t('dental.notePlaceholder')" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink" />
                            <input v-model="form.reason" type="text" :placeholder="t('dental.reasonPlaceholder')" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink" />
                            <button type="submit" class="btn-glow w-full rounded-xl px-4 py-2 text-sm font-semibold" :disabled="form.processing || form.condition === ''">{{ t('dental.recordButton') }}</button>
                        </form>

                        <!-- Perform a procedure (G4): records the clinical fact + charge + tooth-state, atomically. -->
                        <form v-if="actions.can_perform" class="space-y-3 border-t border-line pt-4" @submit.prevent="submitPerform">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('dental.perform.title') }}</p>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-ink">{{ t('dental.perform.procedure') }}</span>
                                <select v-model="performForm.dental_procedure_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                    <option value="" disabled>{{ t('dental.perform.chooseProcedure') }}</option>
                                    <option v-for="p in procedures" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                                </select>
                                <span v-if="performForm.errors.procedure" class="mt-1 block text-xs text-danger">{{ performForm.errors.procedure }}</span>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-ink">{{ t('dental.perform.branch') }}</span>
                                <select v-model="performForm.branch_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                    <option value="" disabled>{{ t('dental.perform.chooseBranch') }}</option>
                                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-ink">{{ t('dental.perform.resultingState') }}</span>
                                <select v-model="performForm.tooth_state" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                    <option value="">{{ t('dental.perform.noChange') }}</option>
                                    <option v-for="c in allConditions" :key="c" :value="c">{{ t(`dental.conditions.${c}`) }}</option>
                                </select>
                            </label>
                            <label v-if="performForm.tooth_state && conditions.surface.includes(performForm.tooth_state)" class="block">
                                <span class="mb-1 block text-xs font-medium text-ink">{{ t('dental.scope') }}</span>
                                <select v-model="performForm.surface" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                    <option value="" disabled>{{ t('dental.condition') }}</option>
                                    <option v-for="s in surfaces" :key="s" :value="s">{{ t(`dental.surfaces.${s}`) }}</option>
                                </select>
                            </label>
                            <input v-model="performForm.note" type="text" :placeholder="t('dental.perform.note')" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink" />
                            <button type="submit" class="btn-glow w-full rounded-xl px-4 py-2 text-sm font-semibold" :disabled="performForm.processing || performForm.dental_procedure_id === '' || performForm.branch_id === ''">{{ t('dental.perform.button') }}</button>
                            <p class="text-xs text-ink-subtle">{{ t('dental.perform.hint') }}</p>
                        </form>

                        <!-- Procedures performed on this tooth. -->
                        <div v-if="performedForSelected.length" class="border-t border-line pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('dental.performed.title') }}</p>
                            <ul class="mt-2 space-y-2 text-sm">
                                <li v-for="pp in performedForSelected" :key="pp.id" class="border-b border-line/60 pb-2">
                                    <p class="text-ink">{{ pp.code }} — {{ pp.name }}</p>
                                    <p class="text-xs text-ink-subtle">{{ dateTime(pp.performed_at) }}<span v-if="pp.note"> · {{ pp.note }}</span></p>
                                </li>
                            </ul>
                        </div>

                        <!-- Per-tooth charting history (the append-only trail). -->
                        <div class="border-t border-line pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('dental.history') }}</p>
                            <ul v-if="historyForSelected.length" class="mt-2 space-y-2 text-sm">
                                <li v-for="h in historyForSelected" :key="h.id" class="border-b border-line/60 pb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-block h-2.5 w-2.5 rounded-sm border border-line" :style="{ backgroundColor: colour(h.condition) }"></span>
                                        <span class="text-ink">{{ h.surface ? t(`dental.surfaces.${h.surface}`) + ' · ' : '' }}{{ t(`dental.conditions.${h.condition}`) }}</span>
                                    </div>
                                    <p class="text-xs text-ink-subtle">{{ dateTime(h.charted_at) }}<span v-if="h.reason"> · {{ h.reason }}</span></p>
                                </li>
                            </ul>
                            <p v-else class="mt-1 text-sm text-ink-subtle">{{ t('dental.noHistory') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
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
    outline: 2px solid var(--color-euca-500, #6f8f5f);
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
