<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

interface Measurement {
    id: string;
    tooth: string;
    site: string;
    pocket_depth_mm: number | null;
    recession_mm: number | null;
    bleeding_on_probing: boolean;
    mobility: number | null;
    furcation: number | null;
}
interface Exam {
    id: string;
    exam_date: string;
    examined_by: number;
    note: string | null;
    measurements: Measurement[];
}

const props = defineProps<{
    patient: { id: string; mrn: string; name: string };
    exams: Exam[];
    teeth: { permanent: string[]; primary: string[] };
    sites: string[];
    actions: { can_chart: boolean; store_url: string };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// ---- New exam (staged locally, then recorded in one append-only action) --------------------
interface StagedSite {
    site: string;
    pocket_depth_mm: string;
    recession_mm: string;
    bleeding_on_probing: boolean;
}
type StagedForm = { tooth: string; mobility: string; furcation: string; sites: StagedSite[] };

function freshForm(): StagedForm {
    return {
        tooth: '',
        mobility: '',
        furcation: '',
        sites: props.sites.map((site) => ({ site, pocket_depth_mm: '', recession_mm: '', bleeding_on_probing: false })),
    };
}

const examDate = ref(new Date().toISOString().slice(0, 10));
const examNote = ref('');
const entry = reactive<StagedForm>(freshForm());
// Staged measurements: one flat row per (tooth, site). The tooth's mobility/furcation are raw
// per-tooth values, carried on each of its site rows for a simple, queryable record.
const staged = ref<Array<Omit<Measurement, 'id'>>>([]);

const stagedByTooth = computed(() => {
    const groups: Record<string, Array<Omit<Measurement, 'id'>>> = {};
    for (const m of staged.value) {
        (groups[m.tooth] ??= []).push(m);
    }
    return groups;
});

function numOrNull(v: string): number | null {
    return v === '' ? null : Number(v);
}

function addTooth(): void {
    if (!entry.tooth) return;
    const mobility = numOrNull(entry.mobility);
    const furcation = numOrNull(entry.furcation);
    for (const s of entry.sites) {
        staged.value.push({
            tooth: entry.tooth,
            site: s.site,
            pocket_depth_mm: numOrNull(s.pocket_depth_mm),
            recession_mm: numOrNull(s.recession_mm),
            bleeding_on_probing: s.bleeding_on_probing,
            mobility,
            furcation,
        });
    }
    Object.assign(entry, freshForm());
}

function removeTooth(tooth: string): void {
    staged.value = staged.value.filter((m) => m.tooth !== tooth);
}

function recordExam(): void {
    if (!staged.value.length) return;
    router.post(
        props.actions.store_url,
        { exam_date: examDate.value, note: examNote.value, measurements: staged.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                staged.value = [];
                examNote.value = '';
                Object.assign(entry, freshForm());
            },
        },
    );
}

// ---- Reading exams: group measurements by tooth for the classic grid ------------------------
function toothsOf(exam: Exam): string[] {
    return [...new Set(exam.measurements.map((m) => m.tooth))];
}
function siteValue(exam: Exam, tooth: string, site: string): Measurement | undefined {
    return exam.measurements.find((m) => m.tooth === tooth && m.site === site);
}
function toothMobility(exam: Exam, tooth: string): number | null {
    return exam.measurements.find((m) => m.tooth === tooth && m.mobility !== null)?.mobility ?? null;
}
function toothFurcation(exam: Exam, tooth: string): number | null {
    return exam.measurements.find((m) => m.tooth === tooth && m.furcation !== null)?.furcation ?? null;
}
function dash(v: number | null | undefined): string {
    return v === null || v === undefined ? '·' : String(v);
}
</script>

<template>
    <AppLayout>
        <Head :title="t('perio.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('perio.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('perio.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ patient.name }} · <span class="font-mono">{{ patient.mrn }}</span></p>
                <p class="mt-1 max-w-2xl text-sm text-ink-subtle">{{ t('perio.subtitle') }}</p>
            </div>

            <p class="rounded-2xl border border-line bg-surface px-4 py-3 text-xs text-ink-subtle">{{ t('perio.fenceNote') }}</p>

            <p v-if="flash" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">{{ t(`perio.flash.${flash}`) }}</p>

            <!-- Record a new exam (append-only). -->
            <Card v-if="actions.can_chart" :title="t('perio.newExam.title')" :subtitle="t('perio.newExam.subtitle')">
                <div class="flex flex-wrap items-end gap-3">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('perio.newExam.examDate') }}</span>
                        <input v-model="examDate" type="date" class="rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink" />
                    </label>
                    <label class="flex-1 text-sm">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('perio.newExam.note') }}</span>
                        <input v-model="examNote" type="text" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink" />
                    </label>
                </div>

                <!-- Per-tooth 6-site entry: raw numbers only, no colours/flags. -->
                <div class="mt-4 rounded-2xl border border-line p-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="text-sm">
                            <span class="mb-1 block font-medium text-ink-muted">{{ t('perio.newExam.tooth') }}</span>
                            <select v-model="entry.tooth" class="rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink">
                                <option value="" disabled>{{ t('perio.newExam.chooseTooth') }}</option>
                                <optgroup :label="t('perio.dentition.permanent')">
                                    <option v-for="tn in teeth.permanent" :key="tn" :value="tn">{{ tn }}</option>
                                </optgroup>
                                <optgroup :label="t('perio.dentition.primary')">
                                    <option v-for="tn in teeth.primary" :key="tn" :value="tn">{{ tn }}</option>
                                </optgroup>
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium text-ink-muted">{{ t('perio.fields.mobility') }}</span>
                            <input v-model="entry.mobility" type="number" min="0" max="3" class="w-20 rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium text-ink-muted">{{ t('perio.fields.furcation') }}</span>
                            <input v-model="entry.furcation" type="number" min="0" max="4" class="w-20 rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink" />
                        </label>
                    </div>

                    <table v-if="entry.tooth" class="mt-3 w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-ink-subtle">
                                <th class="py-1 pr-3 font-medium">{{ t('perio.table.site') }}</th>
                                <th class="py-1 pr-3 font-medium">{{ t('perio.fields.pocket_depth_mm') }}</th>
                                <th class="py-1 pr-3 font-medium">{{ t('perio.fields.recession_mm') }}</th>
                                <th class="py-1 font-medium">{{ t('perio.fields.bleeding_on_probing') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in entry.sites" :key="s.site" class="border-t border-line/60">
                                <td class="py-1 pr-3 text-ink-muted">{{ t(`perio.sites.${s.site}`) }}</td>
                                <td class="py-1 pr-3"><input v-model="s.pocket_depth_mm" type="number" min="0" max="15" class="w-16 rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" /></td>
                                <td class="py-1 pr-3"><input v-model="s.recession_mm" type="number" min="-15" max="30" class="w-16 rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" /></td>
                                <td class="py-1"><input v-model="s.bleeding_on_probing" type="checkbox" class="h-4 w-4 rounded border-line text-euca-600" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <Button v-if="entry.tooth" class="mt-3" :block="false" @click="addTooth">{{ t('perio.newExam.addTooth') }}</Button>
                </div>

                <!-- Staged teeth for this exam. -->
                <div v-if="staged.length" class="mt-4 space-y-2">
                    <p class="text-sm font-semibold text-ink">{{ t('perio.newExam.staged') }}</p>
                    <div v-for="(rows, tooth) in stagedByTooth" :key="tooth" class="flex items-center justify-between rounded-xl border border-line px-3 py-2 text-sm">
                        <span class="text-ink">{{ t('perio.table.tooth') }} {{ tooth }} · {{ rows.length }} {{ t('perio.newExam.sitesLabel') }}</span>
                        <button type="button" class="text-xs font-semibold text-ink-subtle hover:text-danger" @click="removeTooth(String(tooth))">✕</button>
                    </div>
                    <Button :block="false" :disabled="!staged.length" @click="recordExam">{{ t('perio.newExam.save') }}</Button>
                </div>
            </Card>

            <!-- Prior exams: the classic grid, raw numbers only. -->
            <p v-if="!exams.length" class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-muted">{{ t('perio.exams.empty') }}</p>

            <Card v-for="exam in exams" :key="exam.id" :title="`${t('perio.exams.on')} ${exam.exam_date}`" :subtitle="exam.note ?? undefined">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-ink-subtle">
                                <th class="py-1 pr-3 font-medium">{{ t('perio.table.tooth') }}</th>
                                <th v-for="s in sites" :key="s" class="py-1 pr-3 font-medium">{{ t(`perio.sitesShort.${s}`) }}</th>
                                <th class="py-1 pr-3 font-medium">{{ t('perio.fields.mobilityShort') }}</th>
                                <th class="py-1 font-medium">{{ t('perio.fields.furcationShort') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tooth in toothsOf(exam)" :key="tooth" class="border-t border-line/60">
                                <td class="py-1 pr-3 font-mono text-ink">{{ tooth }}</td>
                                <td v-for="s in sites" :key="s" class="py-1 pr-3 text-ink-muted">
                                    <span class="font-mono">{{ dash(siteValue(exam, tooth, s)?.pocket_depth_mm) }}/{{ dash(siteValue(exam, tooth, s)?.recession_mm) }}</span>
                                    <span v-if="siteValue(exam, tooth, s)?.bleeding_on_probing" class="ml-0.5 text-ink" :title="t('perio.fields.bleeding_on_probing')">•</span>
                                </td>
                                <td class="py-1 pr-3 font-mono text-ink-muted">{{ dash(toothMobility(exam, tooth)) }}</td>
                                <td class="py-1 font-mono text-ink-muted">{{ dash(toothFurcation(exam, tooth)) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-ink-subtle">{{ t('perio.exams.legend') }}</p>
            </Card>
        </div>
    </AppLayout>
</template>
