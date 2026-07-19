<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import AllergyBanner from '@/Components/AllergyBanner.vue';
import Tabs from '@/Components/Tabs.vue';
import Timeline from '@/Components/Timeline.vue';
import VersionHistory from '@/Components/VersionHistory.vue';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

const props = defineProps<{
    patient: { id: string; mrn: string; name: string; date_of_birth: string; sex: string; status: string };
    encounters: Array<{ id: string; type: string; status: string; started_at: string; ended_at: string | null }>;
    notes: Array<{
        id: string;
        status: string;
        version: number;
        author_name: string;
        created_at: string | null;
        signed_at: string | null;
        edit_url: string;
        versions: Array<{ id: string; version: number; status: string; author_name: string; created_at: string | null; signed_at: string | null; amendment_reason: string | null; edit_url: string }>;
    }>;
    problems: Array<{ id: string; description: string; code: string | null; status: string; recorded_at: string; resolved_at: string | null }>;
    allergies: Array<{ id: string; substance: string; reaction: string | null; severity: string; status: string; verified_at: string | null }>;
    vitals: Array<{ id: string; recorded_at: string; systolic: number | null; diastolic: number | null; heart_rate: number | null; temperature_c: string | null; spo2: number | null; weight_g: number | null; height_mm: number | null; extra: Record<string, unknown> | null }>;
    vitalsHistory: Record<string, Array<{ recorded_at: string; value: number | string; source: string }>>;
    medications: Array<{ id: string; name: string; dose_text: string | null; route: string | null; frequency_text: string | null; status: string; started_on: string; ended_on: string | null }>;
    documents: Array<{ id: string; category: string; title: string; original_filename: string; uploaded_at: string; shared_with_patient: boolean; download_url: string }>;
    carePlans: Array<{ id: string; title: string; status: string; started_on: string; ended_on: string | null; goals: Array<{ id: string; description: string; target_date: string | null; status: string }> }>;
    referrals: Array<{ id: string; direction: string; status: string; specialty: string | null; reason: string; to_provider_name: string | null; from_provider_name: string | null; to_branch_id: string | null; sent_at: string | null; responded_at: string | null; notes: string | null }>;
    recalls: Array<{ id: string; rule_id: string; rule_name: string; due_on: string; status: string }>;
    orders: Array<{
        id: string;
        item: string | null;
        category: string | null;
        priority: string;
        status: string;
        clinical_note: string | null;
        ordered_at: string;
        cancelled_reason: string | null;
        reviewed_at: string | null;
        reviewed_by: number | null;
        results: Array<{ id: string; value: string | null; has_document: boolean; source: string; entered_at: string }>;
    }>;
    orderableItems: Array<{ id: string; category: string; code: string; name: string }>;
    aiSummary: {
        status: string;
        label: string;
        human_handoff: boolean;
        action_id: string | null;
        insert_url: string;
        lines: Array<{ text: string; source: { type: string; id: string; section?: string; label: string; url: string | null } }>;
    } | null;
    actions: {
        can_view: boolean;
        can_write_notes: boolean;
        can_sign_notes: boolean;
        can_order: boolean;
        summary_draft_url: string;
        order_place_url: string;
        order_transition_url: string;
        order_result_url: string;
        order_review_url: string;
    };
}>();

const activeTab = ref('timeline');
const encounterFilter = ref('all');
const summaryInsertForm = useForm({ action_id: props.aiSummary?.action_id ?? '' });
const placeOrderForm = useForm({ patient_id: props.patient.id, orderable_item_id: props.orderableItems[0]?.id ?? '', priority: 'routine', clinical_note: '' });
const resultForm = useForm({ order_id: '', value: '' });

const age = computed(() => {
    const d = new Date(props.patient.date_of_birth);
    if (Number.isNaN(d.getTime())) return null;
    const now = new Date();
    let a = now.getFullYear() - d.getFullYear();
    const m = now.getMonth() - d.getMonth();
    if (m < 0 || (m === 0 && now.getDate() < d.getDate())) a -= 1;
    return a;
});
const initials = computed(() => {
    const parts = props.patient.name.trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1][0] ?? '') : '')).toUpperCase();
});
const openRecalls = computed(() => props.recalls.filter((r) => !['completed', 'dismissed', 'cancelled'].includes(r.status)).length);

const tabs = computed(() => [
    { key: 'timeline', label: t('clinical.chart.tabs.timeline') },
    { key: 'notes', label: t('clinical.chart.tabs.notes'), count: props.notes.length },
    { key: 'problems', label: t('clinical.chart.tabs.problems'), count: props.problems.length },
    { key: 'vitals', label: t('clinical.chart.tabs.vitals'), count: props.vitals.length },
    { key: 'medications', label: t('clinical.chart.tabs.medications'), count: props.medications.length },
    { key: 'documents', label: t('clinical.chart.tabs.documents'), count: props.documents.length },
    { key: 'orders', label: t('clinical.orders.tab'), count: props.orders.length },
    { key: 'care', label: t('clinical.chart.tabs.care') },
]);

const encounterTypes = computed(() => Array.from(new Set(props.encounters.map((e) => e.type))));
const monthGroups = computed(() => {
    const filtered = encounterFilter.value === 'all' ? props.encounters : props.encounters.filter((e) => e.type === encounterFilter.value);
    const groups: Record<string, typeof props.encounters> = {};
    const order: string[] = [];
    for (const e of filtered) {
        const d = new Date(e.started_at);
        let key = e.started_at;
        if (!Number.isNaN(d.getTime())) {
            try {
                key = new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' }).format(d).toUpperCase();
            } catch {
                key = e.started_at;
            }
        }
        if (!(key in groups)) {
            groups[key] = [];
            order.push(key);
        }
        groups[key].push(e);
    }
    return order.map((month) => ({
        month,
        items: groups[month].map((e) => ({ id: e.id, title: e.type, subtitle: e.status, at: e.started_at })),
    }));
});

// Per-metric trend: raw values over time, neutral ink only. Units are labels — NO
// ranges, bands, flags, arrows, or scores. A sparkline would already be interpretation.
const METRIC_KEYS = ['systolic', 'diastolic', 'heart_rate', 'temperature_c', 'spo2', 'weight_g', 'height_mm'] as const;
const metricSeries = computed(() =>
    METRIC_KEYS.map((key) => ({ key, points: props.vitalsHistory?.[key] ?? [] })).filter((metric) => metric.points.length > 0),
);
function rawVital(vital: (typeof props.vitals)[number]): string {
    return [
        vital.systolic !== null || vital.diastolic !== null ? `${vital.systolic ?? '-'} / ${vital.diastolic ?? '-'}` : null,
        vital.heart_rate !== null ? `${vital.heart_rate}` : null,
        vital.temperature_c !== null ? `${vital.temperature_c} C` : null,
        vital.spo2 !== null ? `${vital.spo2}%` : null,
        vital.weight_g !== null ? `${vital.weight_g} g` : null,
        vital.height_mm !== null ? `${vital.height_mm} mm` : null,
    ].filter(Boolean).join(' | ');
}

function requestSummary(): void {
    router.post(props.actions.summary_draft_url, {}, { preserveScroll: true });
}
function insertSummary(): void {
    if (!props.aiSummary?.action_id) return;
    summaryInsertForm.action_id = props.aiSummary.action_id;
    summaryInsertForm.post(props.aiSummary.insert_url);
}
function placeOrder(): void {
    placeOrderForm.post(props.actions.order_place_url, { preserveScroll: true });
}
function recordResult(orderId: string): void {
    resultForm.order_id = orderId;
    resultForm.post(props.actions.order_result_url, { preserveScroll: true, onSuccess: () => resultForm.reset('value') });
}
function reviewOrder(orderId: string): void {
    useForm({ order_id: orderId }).post(props.actions.order_review_url, { preserveScroll: true });
}
function transitionOrder(orderId: string, status: string): void {
    useForm({ order_id: orderId, status, reason: status === 'cancelled' ? t('clinical.orders.cancelReason') : '' }).post(props.actions.order_transition_url, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('clinical.chart.title')" />
        <div class="space-y-5">
            <!-- The screen's one deep-eucalyptus tile: the patient band. -->
            <div class="euca-tile-dark relative overflow-hidden p-6">
                <div class="relative flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div class="flex items-start gap-4">
                        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-white/15 text-lg font-semibold text-euca-50">{{ initials }}</span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-3xl font-semibold tracking-tight text-euca-50">{{ patient.name }}</h1>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-euca-300/25 px-2.5 py-1 text-xs font-semibold text-euca-50">
                                    <span class="h-1.5 w-1.5 rounded-full bg-euca-200"></span>{{ patient.status }}
                                </span>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                                <span class="rounded-md bg-white/10 px-2.5 py-1 font-mono text-euca-100">{{ patient.mrn }}</span>
                                <span class="rounded-md bg-white/10 px-2.5 py-1 text-euca-100">{{ patient.date_of_birth }}<template v-if="age !== null"> · {{ age }} y</template> · {{ patient.sex }}</span>
                                <span class="rounded-md bg-white/10 px-2.5 py-1 text-euca-100">{{ t('clinical.chart.summary', { encounters: encounters.length, problems: problems.length, medications: medications.length }) }}</span>
                                <span v-if="openRecalls > 0" class="inline-flex items-center gap-1.5 rounded-md bg-warning/25 px-2.5 py-1 text-euca-50">
                                    <span class="h-1.5 w-1.5 rounded-full bg-warning"></span>{{ t('clinical.chart.openRecalls', { count: openRecalls }) }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <Link :href="`/patients/${patient.id}`" class="shrink-0 rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25">
                        {{ t('clinical.chart.patient360') }} →
                    </Link>
                </div>
            </div>

            <AllergyBanner :allergies="allergies" />

            <!-- AI summary draft — badged, dashed, source-linked, human-insert only. -->
            <div v-if="aiSummary" class="rounded-2xl border border-dashed border-euca-400 bg-euca-50/50 p-5">
                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg euca-tile-dark text-euca-50">✦</span>
                        <div>
                            <p class="font-semibold text-ink">{{ t('clinical.chart.aiSummary.title') }}</p>
                            <span class="rounded-full bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning">{{ aiSummary.label }}</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="actions.can_write_notes" type="button" class="rounded-xl border border-line bg-surface/70 px-3.5 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2" @click="requestSummary">{{ t('clinical.chart.aiSummary.refresh') }}</button>
                        <button v-if="actions.can_write_notes" type="button" class="btn-glow inline-flex items-center rounded-xl px-3.5 py-2 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-60" :disabled="summaryInsertForm.processing || !aiSummary.action_id" @click="insertSummary">{{ t('clinical.chart.aiSummary.insert') }}</button>
                    </div>
                </div>
                <p class="mt-2 text-xs text-ink-subtle">{{ t('clinical.chart.aiSummary.extractive') }}</p>
                <div class="mt-3 space-y-2">
                    <div v-for="line in aiSummary.lines" :key="`${line.source.type}-${line.source.id}-${line.source.section || 'row'}`" class="flex flex-col gap-2 rounded-xl bg-surface px-3.5 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-ink">{{ line.text }}</p>
                        <Link v-if="line.source.url" :href="line.source.url" class="shrink-0 rounded-md bg-euca-50 px-2 py-0.5 font-mono text-xs font-semibold text-euca-800 transition hover:bg-euca-100">{{ line.source.label }}</Link>
                        <span v-else class="shrink-0 rounded-md bg-euca-50 px-2 py-0.5 font-mono text-xs font-semibold text-euca-800">{{ line.source.label }}</span>
                    </div>
                    <p v-if="aiSummary.lines.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                </div>
            </div>

            <div class="glass-card p-6">
                <Tabs v-model:active="activeTab" :tabs="tabs">
                    <section v-if="activeTab === 'timeline'" class="space-y-6">
                        <div class="flex flex-wrap items-center gap-1 rounded-full bg-euca-50/70 p-1">
                            <button type="button" class="rounded-full px-3 py-1.5 text-sm font-medium transition" :class="encounterFilter === 'all' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="encounterFilter = 'all'">{{ t('clinical.chart.allEncounters', { count: encounters.length }) }}</button>
                            <button v-for="type in encounterTypes" :key="type" type="button" class="rounded-full px-3 py-1.5 text-sm font-medium capitalize transition" :class="encounterFilter === type ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="encounterFilter = type">{{ type }}</button>
                        </div>
                        <div v-for="group in monthGroups" :key="group.month" class="space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ group.month }}</p>
                            <Timeline :items="group.items" />
                        </div>
                        <p v-if="monthGroups.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'notes'" class="space-y-4">
                        <div v-for="note in notes" :key="note.id" class="rounded-xl border border-line bg-surface-2 p-4">
                            <div class="flex flex-col justify-between gap-2 sm:flex-row">
                                <div>
                                    <p class="font-semibold text-ink">{{ t('clinical.note.versionLabel', { version: note.version }) }}</p>
                                    <p class="text-sm text-ink-muted">{{ note.author_name }} · {{ note.status }} · {{ note.signed_at || note.created_at || '—' }}</p>
                                </div>
                                <Link :href="note.edit_url" class="text-sm font-semibold text-euca-700 transition hover:text-euca-800">{{ t('clinical.note.open') }} →</Link>
                            </div>
                            <div class="mt-4"><VersionHistory :versions="note.versions" /></div>
                        </div>
                        <p v-if="notes.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'problems'" class="space-y-3">
                        <div v-for="problem in problems" :key="problem.id" class="flex items-start justify-between gap-3 rounded-xl border border-line bg-surface-2 p-4">
                            <div>
                                <p class="font-semibold text-ink">{{ problem.description }}</p>
                                <p class="text-sm text-ink-muted">{{ problem.code || '—' }} · {{ problem.recorded_at }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ problem.status }}</span>
                        </div>
                        <p v-if="problems.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'vitals'" class="space-y-6">
                        <!-- Raw values over time, neutral ink only. No bands, ranges, flags, arrows, or scores. -->
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <div v-for="metric in metricSeries" :key="metric.key" class="rounded-xl border border-line bg-surface-2 p-4">
                                <p class="text-sm font-semibold text-ink">
                                    {{ t('clinical.chart.vitalsHistory.metrics.' + metric.key) }}
                                    <span class="font-normal text-ink-muted">{{ t('clinical.chart.vitalsHistory.units.' + metric.key) }}</span>
                                </p>
                                <table class="mt-2 w-full text-left text-sm">
                                    <tbody>
                                        <tr v-for="(point, index) in metric.points" :key="index" class="border-t border-line/60 first:border-t-0">
                                            <td class="py-1 pr-3 tabular-nums text-ink">{{ point.value }}</td>
                                            <td class="py-1 pr-3 text-ink-muted">{{ point.recorded_at }}</td>
                                            <td class="py-1 text-xs text-ink-muted">{{ t('clinical.chart.vitalsHistory.source.' + point.source) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <p v-if="metricSeries.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>

                        <div v-if="vitals.length > 0" class="space-y-3">
                            <p class="text-sm font-semibold text-ink">{{ t('clinical.chart.vitalsHistory.log') }}</p>
                            <div v-for="vital in vitals" :key="vital.id" class="rounded-xl border border-line bg-surface-2 p-4">
                                <p class="font-semibold text-ink">{{ vital.recorded_at }}</p>
                                <p class="text-sm tabular-nums text-ink-muted">{{ rawVital(vital) || '—' }}</p>
                            </div>
                        </div>
                    </section>

                    <section v-if="activeTab === 'medications'" class="space-y-3">
                        <div v-for="medication in medications" :key="medication.id" class="flex items-start justify-between gap-3 rounded-xl border border-line bg-surface-2 p-4">
                            <div>
                                <p class="font-semibold text-ink">{{ medication.name }}</p>
                                <!-- dose_text is shown exactly as documented — raw, never interpreted. -->
                                <p class="text-sm text-ink-muted">{{ medication.dose_text || '—' }} · {{ medication.route || '—' }} · {{ medication.frequency_text || '—' }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ medication.status }}</span>
                        </div>
                        <p v-if="medications.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'documents'" class="space-y-3">
                        <div v-for="document in documents" :key="document.id" class="flex items-center justify-between gap-3 rounded-xl border border-line bg-surface-2 p-4">
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-ink">{{ document.title }}</p>
                                <p class="text-sm text-ink-muted">{{ document.category }} · {{ document.original_filename }} · {{ document.uploaded_at }}</p>
                                <span v-if="document.shared_with_patient" class="mt-1 inline-flex rounded-full bg-euca-50 px-2 py-0.5 text-xs font-medium text-euca-800">{{ t('clinical.chart.sharedWithPatient') }}</span>
                            </div>
                            <Link :href="document.download_url" class="shrink-0 text-sm font-semibold text-euca-700 transition hover:text-euca-800">{{ t('clinical.chart.download') }}</Link>
                        </div>
                        <p v-if="documents.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'orders'" class="space-y-4">
                        <form v-if="actions.can_order" class="grid gap-3 rounded-xl border border-line bg-surface-2 p-4 sm:grid-cols-[1fr_auto_auto]" @submit.prevent="placeOrder">
                            <select v-model="placeOrderForm.orderable_item_id" :aria-label="t('clinical.orders.place')" class="rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink">
                                <option v-for="item in orderableItems" :key="item.id" :value="item.id">{{ item.name }} ({{ item.category }})</option>
                            </select>
                            <select v-model="placeOrderForm.priority" :aria-label="t('clinical.orders.tab')" class="rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink">
                                <option value="routine">{{ t('clinical.orders.routine') }}</option>
                                <option value="urgent">{{ t('clinical.orders.urgent') }}</option>
                            </select>
                            <button type="submit" class="btn-glow rounded-xl px-4 py-2.5 text-sm font-semibold disabled:opacity-60" :disabled="!placeOrderForm.orderable_item_id">{{ t('clinical.orders.place') }}</button>
                            <input v-model="placeOrderForm.clinical_note" type="text" :aria-label="t('clinical.orders.reason')" :placeholder="t('clinical.orders.reason')" class="rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink sm:col-span-3" />
                        </form>

                        <div v-for="order in orders" :key="order.id" class="rounded-xl border border-line bg-surface-2 p-4">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-semibold text-ink">{{ order.item }} <span class="text-xs text-ink-muted">({{ order.category }} · {{ order.priority }})</span></p>
                                <span class="rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ order.status }}</span>
                            </div>
                            <p v-if="order.clinical_note" class="mt-1 text-sm text-ink-muted">{{ order.clinical_note }}</p>
                            <!-- Raw result values, no interpretation. -->
                            <ul v-if="order.results.length" class="mt-2 space-y-1">
                                <li v-for="r in order.results" :key="r.id" class="text-sm text-ink">
                                    <span class="font-mono tabular-nums">{{ r.value ?? (r.has_document ? t('clinical.orders.seeDocument') : '') }}</span>
                                    <span class="ml-2 text-xs text-ink-muted">{{ r.entered_at }}</span>
                                </li>
                            </ul>
                            <p v-if="order.reviewed_at" class="mt-2 text-xs font-medium text-euca-700">{{ t('clinical.orders.reviewedAt', { at: order.reviewed_at }) }}</p>
                            <div v-if="actions.can_order" class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                                <template v-if="['ordered', 'collected', 'in_progress'].includes(order.status)">
                                    <input v-model="resultForm.value" type="text" :aria-label="t('clinical.orders.resultValue')" :placeholder="t('clinical.orders.resultValue')" class="rounded-xl border border-line bg-surface px-3 py-1.5" />
                                    <button type="button" class="font-semibold text-euca-700 transition hover:text-euca-800" @click="recordResult(order.id)">{{ t('clinical.orders.recordResult') }}</button>
                                    <button type="button" class="font-medium text-ink-muted transition hover:text-ink" @click="transitionOrder(order.id, 'cancelled')">{{ t('clinical.orders.cancel') }}</button>
                                </template>
                                <button v-if="order.status === 'resulted'" type="button" class="btn-glow rounded-xl px-3.5 py-1.5 font-semibold" @click="reviewOrder(order.id)">{{ t('clinical.orders.markReviewed') }}</button>
                            </div>
                        </div>
                        <p v-if="orders.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'care'" class="grid gap-4 lg:grid-cols-3">
                        <div class="space-y-3 lg:col-span-2">
                            <p class="font-semibold text-ink">{{ t('clinical.chart.carePlans') }}</p>
                            <div v-for="plan in carePlans" :key="plan.id" class="rounded-xl border border-line bg-surface-2 p-4">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="font-semibold text-ink">{{ plan.title }}</p>
                                    <span class="rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ plan.status }}</span>
                                </div>
                                <p class="text-sm text-ink-muted">{{ plan.started_on }} · {{ plan.ended_on || '—' }}</p>
                                <div class="mt-3 space-y-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('clinical.chart.carePlanGoals') }}</p>
                                    <div v-for="goal in plan.goals" :key="goal.id" class="flex items-start justify-between gap-2 text-sm">
                                        <span class="text-ink">{{ goal.description }} <span class="text-ink-subtle">· {{ goal.target_date || '—' }}</span></span>
                                        <span class="shrink-0 rounded-full bg-euca-100 px-2 py-0.5 text-xs font-semibold text-euca-800">{{ goal.status }}</span>
                                    </div>
                                    <p v-if="plan.goals.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                                </div>
                            </div>
                            <p v-if="carePlans.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                        </div>
                        <div class="space-y-4">
                            <div class="space-y-3">
                                <p class="font-semibold text-ink">{{ t('clinical.chart.referrals') }}</p>
                                <div v-for="referral in referrals" :key="referral.id" class="rounded-xl border border-line bg-surface-2 p-4">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-semibold text-ink">{{ referral.specialty || referral.direction }}</p>
                                        <span class="rounded-full bg-euca-50 px-2 py-0.5 text-xs font-semibold text-euca-800">{{ referral.status }}</span>
                                    </div>
                                    <p class="text-sm text-ink-muted">{{ referral.direction }} · {{ referral.to_provider_name || referral.from_provider_name || referral.to_branch_id || '—' }}</p>
                                    <p class="mt-2 text-sm text-ink-muted">{{ referral.reason }}</p>
                                </div>
                                <p v-if="referrals.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                            </div>
                            <div class="space-y-3">
                                <p class="font-semibold text-ink">{{ t('clinical.chart.recalls') }}</p>
                                <div v-for="recall in recalls" :key="recall.id" class="flex items-center justify-between gap-2 rounded-xl border border-line bg-surface-2 p-4">
                                    <div>
                                        <p class="font-semibold text-ink">{{ recall.rule_name }}</p>
                                        <p class="text-sm text-ink-muted">{{ recall.due_on }}</p>
                                    </div>
                                    <span class="rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ recall.status }}</span>
                                </div>
                                <p v-if="recalls.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                            </div>
                        </div>
                    </section>
                </Tabs>
            </div>
        </div>
    </AppLayout>
</template>
