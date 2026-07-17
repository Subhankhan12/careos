<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import AllergyBanner from '@/Components/AllergyBanner.vue';
import Card from '@/Components/Card.vue';
import Tabs from '@/Components/Tabs.vue';
import Timeline from '@/Components/Timeline.vue';
import VersionHistory from '@/Components/VersionHistory.vue';

const { t } = useI18n();

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
    carePlans: Array<{
        id: string;
        title: string;
        status: string;
        started_on: string;
        ended_on: string | null;
        goals: Array<{ id: string; description: string; target_date: string | null; status: string }>;
    }>;
    referrals: Array<{
        id: string;
        direction: string;
        status: string;
        specialty: string | null;
        reason: string;
        to_provider_name: string | null;
        from_provider_name: string | null;
        to_branch_id: string | null;
        sent_at: string | null;
        responded_at: string | null;
        notes: string | null;
    }>;
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
        lines: Array<{
            text: string;
            source: { type: string; id: string; section?: string; label: string; url: string | null };
        }>;
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
const summaryInsertForm = useForm({ action_id: props.aiSummary?.action_id ?? '' });

const placeOrderForm = useForm({ patient_id: props.patient.id, orderable_item_id: props.orderableItems[0]?.id ?? '', priority: 'routine', clinical_note: '' });
const resultForm = useForm({ order_id: '', value: '' });

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

const tabs = computed(() => [
    { key: 'timeline', label: t('clinical.chart.tabs.timeline') },
    { key: 'notes', label: t('clinical.chart.tabs.notes') },
    { key: 'problems', label: t('clinical.chart.tabs.problems') },
    { key: 'vitals', label: t('clinical.chart.tabs.vitals') },
    { key: 'medications', label: t('clinical.chart.tabs.medications') },
    { key: 'documents', label: t('clinical.chart.tabs.documents') },
    { key: 'orders', label: t('clinical.orders.tab') },
    { key: 'care', label: t('clinical.chart.tabs.care') },
]);

const encounterTimeline = computed(() =>
    props.encounters.map((encounter) => ({
        id: encounter.id,
        title: encounter.type,
        subtitle: encounter.status,
        at: encounter.started_at,
    })),
);

// Neutral per-metric ordering for the trend view. Units are labels only — no
// conversion, no reference ranges, no bands. Raw values over time, that's all.
const METRIC_KEYS = ['systolic', 'diastolic', 'heart_rate', 'temperature_c', 'spo2', 'weight_g', 'height_mm'] as const;

const metricSeries = computed(() =>
    METRIC_KEYS
        .map((key) => ({ key, points: props.vitalsHistory?.[key] ?? [] }))
        .filter((metric) => metric.points.length > 0),
);

function rawVital(vital: typeof props.vitals[number]): string {
    return [
        vital.systolic !== null || vital.diastolic !== null ? `${vital.systolic ?? '-'} / ${vital.diastolic ?? '-'}` : null,
        vital.heart_rate !== null ? `${vital.heart_rate}` : null,
        vital.temperature_c !== null ? `${vital.temperature_c} C` : null,
        vital.spo2 !== null ? `${vital.spo2}%` : null,
        vital.weight_g !== null ? `${vital.weight_g} g` : null,
        vital.height_mm !== null ? `${vital.height_mm} mm` : null,
    ].filter(Boolean).join(' | ');
}

function insertSummary(): void {
    if (!props.aiSummary?.action_id) {
        return;
    }

    summaryInsertForm.action_id = props.aiSummary.action_id;
    summaryInsertForm.post(props.aiSummary.insert_url);
}
</script>

<template>
    <AppLayout>
        <Head :title="t('clinical.chart.title')" />
        <div class="space-y-6">
            <Card>
                <p class="text-sm font-semibold uppercase text-brand-700">{{ patient.mrn }}</p>
                <h1 class="mt-1 text-3xl font-semibold text-ink">{{ patient.name }}</h1>
                <p class="mt-2 text-sm text-ink-muted">{{ patient.date_of_birth }} | {{ patient.sex }} | {{ patient.status }}</p>
            </Card>

            <AllergyBanner :allergies="allergies" />

            <Card v-if="aiSummary" class="space-y-4">
                <div class="flex flex-col justify-between gap-3 sm:flex-row">
                    <div>
                        <p class="text-xs font-semibold uppercase text-brand-700">{{ aiSummary.label }}</p>
                        <p class="mt-1 font-semibold text-ink">{{ t('clinical.chart.aiSummary.title') }}</p>
                        <p class="text-sm text-ink-muted">{{ t('clinical.chart.aiSummary.status', { status: aiSummary.status }) }}</p>
                    </div>
                    <button
                        v-if="actions.can_write_notes"
                        type="button"
                        class="inline-flex items-center justify-center rounded-md bg-brand-700 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="summaryInsertForm.processing || !aiSummary.action_id"
                        @click="insertSummary"
                    >
                        {{ t('clinical.chart.aiSummary.insert') }}
                    </button>
                </div>
                <div class="space-y-3">
                    <div v-for="line in aiSummary.lines" :key="`${line.source.type}-${line.source.id}-${line.source.section || 'row'}`" class="rounded-md border border-line p-3">
                        <p class="text-sm text-ink">{{ line.text }}</p>
                        <Link v-if="line.source.url" :href="line.source.url" class="mt-2 inline-flex text-xs font-semibold text-brand-700 hover:text-brand-900">
                            {{ line.source.label }}
                        </Link>
                        <p v-else class="mt-2 text-xs font-semibold text-ink-subtle">{{ line.source.label }}</p>
                    </div>
                    <p v-if="aiSummary.lines.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                </div>
            </Card>

            <Card>
                <Tabs v-model:active="activeTab" :tabs="tabs">
                    <section v-if="activeTab === 'timeline'">
                        <Timeline :items="encounterTimeline" />
                    </section>

                    <section v-if="activeTab === 'notes'" class="space-y-4">
                        <div v-for="note in notes" :key="note.id" class="rounded-md border border-line p-4">
                            <div class="flex flex-col justify-between gap-2 sm:flex-row">
                                <div>
                                    <p class="font-semibold text-ink">{{ t('clinical.note.versionLabel', { version: note.version }) }}</p>
                                    <p class="text-sm text-ink-muted">{{ note.author_name }} | {{ note.status }} | {{ note.signed_at || note.created_at || '-' }}</p>
                                </div>
                                <Link :href="note.edit_url" class="text-sm font-semibold text-brand-700 hover:text-brand-900">
                                    {{ t('clinical.note.open') }}
                                </Link>
                            </div>
                            <div class="mt-4">
                                <VersionHistory :versions="note.versions" />
                            </div>
                        </div>
                        <p v-if="notes.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'problems'" class="space-y-3">
                        <div v-for="problem in problems" :key="problem.id" class="rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ problem.description }}</p>
                            <p class="text-sm text-ink-muted">{{ problem.code || '-' }} | {{ problem.status }} | {{ problem.recorded_at }}</p>
                        </div>
                        <p v-if="problems.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'vitals'" class="space-y-6">
                        <!-- Per-metric trend: each metric is its own time-ordered
                             column of raw values (clinic + visit merged). Neutral
                             styling only — no bands, ranges, flags, arrows, or scores. -->
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <div v-for="metric in metricSeries" :key="metric.key" class="rounded-md border border-line p-4">
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

                        <!-- The existing per-reading log is retained below. -->
                        <div v-if="vitals.length > 0" class="space-y-3">
                            <p class="text-sm font-semibold text-ink">{{ t('clinical.chart.vitalsHistory.log') }}</p>
                            <div v-for="vital in vitals" :key="vital.id" class="rounded-md border border-line p-4">
                                <p class="font-semibold text-ink">{{ vital.recorded_at }}</p>
                                <p class="text-sm text-ink-muted">{{ rawVital(vital) || '-' }}</p>
                            </div>
                        </div>
                    </section>

                    <section v-if="activeTab === 'medications'" class="space-y-3">
                        <div v-for="medication in medications" :key="medication.id" class="rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ medication.name }}</p>
                            <p class="text-sm text-ink-muted">{{ medication.dose_text || '-' }} | {{ medication.route || '-' }} | {{ medication.frequency_text || '-' }} | {{ medication.status }}</p>
                        </div>
                        <p v-if="medications.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'documents'" class="space-y-3">
                        <div v-for="document in documents" :key="document.id" class="rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ document.title }}</p>
                            <p class="text-sm text-ink-muted">{{ document.category }} | {{ document.original_filename }} | {{ document.uploaded_at }}</p>
                            <Link :href="document.download_url" class="mt-2 inline-flex text-sm font-semibold text-brand-700 hover:text-brand-900">
                                {{ t('clinical.chart.download') }}
                            </Link>
                        </div>
                        <p v-if="documents.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'orders'" class="space-y-4">
                        <form v-if="actions.can_order" class="grid gap-3 rounded-md border border-line p-4 sm:grid-cols-[1fr_auto_auto]" @submit.prevent="placeOrder">
                            <select v-model="placeOrderForm.orderable_item_id" class="rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                <option v-for="item in orderableItems" :key="item.id" :value="item.id">{{ item.name }} ({{ item.category }})</option>
                            </select>
                            <select v-model="placeOrderForm.priority" class="rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                <option value="routine">{{ t('clinical.orders.routine') }}</option>
                                <option value="urgent">{{ t('clinical.orders.urgent') }}</option>
                            </select>
                            <button type="submit" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700" :disabled="!placeOrderForm.orderable_item_id">{{ t('clinical.orders.place') }}</button>
                            <input v-model="placeOrderForm.clinical_note" type="text" :placeholder="t('clinical.orders.reason')" class="rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink sm:col-span-3" />
                        </form>

                        <div v-for="order in orders" :key="order.id" class="rounded-md border border-line p-4">
                            <div class="flex items-center justify-between">
                                <p class="font-semibold text-ink">{{ order.item }} <span class="text-xs text-ink-muted">({{ order.category }} · {{ order.priority }})</span></p>
                                <span class="text-sm text-ink-muted">{{ order.status }}</span>
                            </div>
                            <p v-if="order.clinical_note" class="mt-1 text-sm text-ink-muted">{{ order.clinical_note }}</p>
                            <ul v-if="order.results.length" class="mt-2 space-y-1">
                                <li v-for="r in order.results" :key="r.id" class="text-sm text-ink">
                                    <span class="font-mono">{{ r.value ?? (r.has_document ? t('clinical.orders.seeDocument') : '') }}</span>
                                    <span class="ml-2 text-xs text-ink-muted">{{ r.entered_at }}</span>
                                </li>
                            </ul>
                            <p v-if="order.reviewed_at" class="mt-2 text-xs text-brand-700">{{ t('clinical.orders.reviewedAt', { at: order.reviewed_at }) }}</p>
                            <div v-if="actions.can_order" class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                                <template v-if="['ordered', 'collected', 'in_progress'].includes(order.status)">
                                    <input v-model="resultForm.value" type="text" :placeholder="t('clinical.orders.resultValue')" class="rounded-md border border-line bg-surface px-2 py-1" />
                                    <button type="button" class="font-medium text-brand-600 hover:text-brand-700" @click="recordResult(order.id)">{{ t('clinical.orders.recordResult') }}</button>
                                    <button type="button" class="font-medium text-ink-muted hover:text-ink" @click="transitionOrder(order.id, 'cancelled')">{{ t('clinical.orders.cancel') }}</button>
                                </template>
                                <button v-if="order.status === 'resulted'" type="button" class="font-medium text-brand-600 hover:text-brand-700" @click="reviewOrder(order.id)">{{ t('clinical.orders.markReviewed') }}</button>
                            </div>
                        </div>
                        <p v-if="orders.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'care'" class="grid gap-4 lg:grid-cols-3">
                        <div class="space-y-3 rounded-md border border-line p-4 lg:col-span-2">
                            <p class="font-semibold text-ink">{{ t('clinical.chart.carePlans') }}</p>
                            <div v-for="plan in carePlans" :key="plan.id" class="rounded-md border border-line p-3">
                                <p class="font-semibold text-ink">{{ plan.title }}</p>
                                <p class="text-sm text-ink-muted">{{ plan.status }} | {{ plan.started_on }} | {{ plan.ended_on || '-' }}</p>
                                <div class="mt-3 space-y-2">
                                    <p class="text-xs font-semibold uppercase text-ink-subtle">{{ t('clinical.chart.carePlanGoals') }}</p>
                                    <p v-for="goal in plan.goals" :key="goal.id" class="text-sm text-ink-muted">
                                        {{ goal.description }} | {{ goal.status }} | {{ goal.target_date || '-' }}
                                    </p>
                                    <p v-if="plan.goals.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                                </div>
                            </div>
                            <p v-if="carePlans.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                        </div>
                        <div class="space-y-3 rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ t('clinical.chart.referrals') }}</p>
                            <div v-for="referral in referrals" :key="referral.id" class="rounded-md border border-line p-3">
                                <p class="font-semibold text-ink">{{ referral.specialty || referral.direction }}</p>
                                <p class="text-sm text-ink-muted">
                                    {{ referral.direction }} | {{ referral.status }} | {{ referral.to_provider_name || referral.from_provider_name || referral.to_branch_id || '-' }}
                                </p>
                                <p class="mt-2 text-sm text-ink-muted">{{ referral.reason }}</p>
                            </div>
                            <p v-if="referrals.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                        </div>
                        <div class="space-y-3 rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ t('clinical.chart.recalls') }}</p>
                            <div v-for="recall in recalls" :key="recall.id" class="rounded-md border border-line p-3">
                                <p class="font-semibold text-ink">{{ recall.rule_name }}</p>
                                <p class="text-sm text-ink-muted">{{ recall.status }} | {{ recall.due_on }}</p>
                            </div>
                            <p v-if="recalls.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                        </div>
                    </section>
                </Tabs>
            </Card>
        </div>
    </AppLayout>
</template>
