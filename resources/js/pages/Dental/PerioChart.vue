<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import DentalSectionNav from '@/Components/DentalSectionNav.vue';
import PatientClinicalHeader from '@/Components/Clinical/PatientClinicalHeader.vue';
import PerioSiteGrid from '@/Components/Dental/PerioSiteGrid.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import { formatDateOnly } from '@/lib/date';

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
    /** The previous exam's RAW readings (tooth → site → mm). No delta is derived from these. */
    previous: { exam_date?: string; pocket_depth_mm?: Record<string, Record<string, number | null>> };
    actions: { can_chart: boolean; store_url: string };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// ---- New exam: a full-arch 6-point grid, recorded in one append-only action ----------------
//
// The grid is LAYOUT + ENTRY ERGONOMICS over the unchanged recording path: what gets POSTed is
// still one flat row per (tooth, site) carrying the same raw fields the server has always
// accepted. Nothing is summarised on the way out.
interface SiteEntry {
    pocket_depth_mm: string;
    recession_mm: string;
    bleeding_on_probing: boolean;
}

/** Which arch is being probed. Purely which columns are on screen. */
const arch = ref<'upper' | 'lower'>('upper');

const archTeeth = computed(() => {
    // Anatomical order across the arch: patient's right descending, then left ascending —
    // the same reading order as the odontogram.
    const quadrants = arch.value === 'upper' ? [1, 2] : [4, 3];
    const right = props.teeth.permanent.filter((t) => Number(t[0]) === quadrants[0]).slice().sort((a, b) => Number(b[1]) - Number(a[1]));
    const left = props.teeth.permanent.filter((t) => Number(t[0]) === quadrants[1]).slice().sort((a, b) => Number(a[1]) - Number(b[1]));
    return [...right, ...left];
});

function freshEntry(): Record<string, Record<string, SiteEntry>> {
    const grid: Record<string, Record<string, SiteEntry>> = {};
    for (const tooth of [...props.teeth.permanent, ...props.teeth.primary]) {
        grid[tooth] = {};
        for (const site of props.sites) {
            grid[tooth][site] = { pocket_depth_mm: '', recession_mm: '', bleeding_on_probing: false };
        }
    }
    return grid;
}

function freshPerTooth(): Record<string, { mobility: string; furcation: string }> {
    const perTooth: Record<string, { mobility: string; furcation: string }> = {};
    for (const tooth of [...props.teeth.permanent, ...props.teeth.primary]) {
        perTooth[tooth] = { mobility: '', furcation: '' };
    }
    return perTooth;
}

const examDate = ref(new Date().toISOString().slice(0, 10));
const examNote = ref('');
const entry = ref(freshEntry());
const perTooth = ref(freshPerTooth());

function numOrNull(v: string): number | null {
    return v === '' ? null : Number(v);
}

/**
 * The rows to record: every site the clinician actually entered something for. A site left
 * blank was not probed, so it is simply not recorded — an untouched cell is not a zero.
 */
const measurements = computed<Array<Omit<Measurement, 'id'>>>(() => {
    const rows: Array<Omit<Measurement, 'id'>> = [];
    for (const [tooth, sites] of Object.entries(entry.value)) {
        for (const [site, values] of Object.entries(sites)) {
            const probed = values.pocket_depth_mm !== '' || values.recession_mm !== '' || values.bleeding_on_probing;
            if (!probed) continue;
            rows.push({
                tooth,
                site,
                pocket_depth_mm: numOrNull(values.pocket_depth_mm),
                recession_mm: numOrNull(values.recession_mm),
                bleeding_on_probing: values.bleeding_on_probing,
                mobility: numOrNull(perTooth.value[tooth]?.mobility ?? ''),
                furcation: numOrNull(perTooth.value[tooth]?.furcation ?? ''),
            });
        }
    }
    return rows;
});

// How many SITES have been entered. A count of the clinician's own data-entry progress — it
// says nothing about the patient, and is not a count over findings.
const enteredSiteCount = computed(() => measurements.value.length);

const previousReadings = computed(() => props.previous?.pocket_depth_mm ?? {});
const previousExamDate = computed(() => (props.previous?.exam_date ? formatDateOnly(props.previous.exam_date) : null));

function recordExam(): void {
    if (!measurements.value.length) return;
    router.post(
        props.actions.store_url,
        { exam_date: examDate.value, note: examNote.value, measurements: measurements.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                entry.value = freshEntry();
                perTooth.value = freshPerTooth();
                examNote.value = '';
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
            <!-- P1's shared S1 header (DENTAL-B.P3 adoption). -->
            <PatientClinicalHeader
                :patient="{ name: patient.name, mrn: patient.mrn }"
                :eyebrow="t('perio.eyebrow')"
                :context="t('perio.subtitle')"
            />

            <DentalSectionNav :patient-id="patient.id" active="perio" />

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

                <!-- The full-arch 6-point grid: raw numbers only, every cell styled alike. -->
                <div class="mt-4 rounded-2xl border border-line p-4">
                    <div class="inline-flex rounded-xl border border-line bg-surface p-1" role="group" :aria-label="t('perioGrid.archLabel')">
                        <button
                            v-for="a in (['upper', 'lower'] as const)"
                            :key="a"
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-sm font-semibold"
                            :class="arch === a ? 'bg-euca-700 text-white' : 'text-ink-muted hover:text-ink'"
                            :aria-pressed="arch === a"
                            @click="arch = a"
                        >
                            {{ t(`perioGrid.${a}`) }}
                        </button>
                    </div>

                    <div class="mt-3">
                        <PerioSiteGrid
                            :teeth="archTeeth"
                            :sites="sites"
                            :entry="entry"
                            :prior="previousReadings"
                            :prior-label="previousExamDate"
                        />
                    </div>

                    <!-- Per-tooth raw indices for the teeth on screen (Miller mobility, Glickman
                         furcation) — recorded scales the clinician reads off, not computed. -->
                    <div class="mt-4 overflow-x-auto border-t border-line pt-3">
                        <p class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-subtle">{{ t('perioGrid.perTooth') }}</p>
                        <table class="mt-2 text-sm">
                            <tbody>
                                <tr>
                                    <th scope="row" class="whitespace-nowrap py-1 pr-3 text-left text-xs font-medium text-ink-muted">{{ t('perio.fields.mobility') }}</th>
                                    <td v-for="tooth in archTeeth" :key="tooth" class="px-0.5 py-0.5">
                                        <input
                                            v-model="perTooth[tooth].mobility"
                                            type="number"
                                            min="0"
                                            max="3"
                                            :aria-label="t('perioGrid.mobilityLabel', { tooth })"
                                            class="h-7 w-11 rounded-md border border-line bg-surface text-center font-mono text-xs text-ink"
                                        />
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" class="whitespace-nowrap py-1 pr-3 text-left text-xs font-medium text-ink-muted">{{ t('perio.fields.furcation') }}</th>
                                    <td v-for="tooth in archTeeth" :key="tooth" class="px-0.5 py-0.5">
                                        <input
                                            v-model="perTooth[tooth].furcation"
                                            type="number"
                                            min="0"
                                            max="4"
                                            :aria-label="t('perioGrid.furcationLabel', { tooth })"
                                            class="h-7 w-11 rounded-md border border-line bg-surface text-center font-mono text-xs text-ink"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Record. The count is the clinician's own data-entry progress, not a
                     statement about the patient. -->
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <Button :block="false" :disabled="!enteredSiteCount" @click="recordExam">{{ t('perio.newExam.save') }}</Button>
                    <p class="text-xs text-ink-subtle">{{ t('perioGrid.entered', { count: enteredSiteCount }, enteredSiteCount) }}</p>
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
