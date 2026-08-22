<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClinicalRailCard from '@/Components/Clinical/ClinicalRailCard.vue';

/*
 * The recall due list (PC.P7) — a worklist over the existing recall backend.
 *
 * PRESENTATIONAL (P0D.GU). The server sorted the rows by `due_on` and computed the plain day
 * interval; this page formats and displays them. It re-sorts nothing and computes no state.
 *
 * THE ORDER IS A DATE SORT. Rows arrive `due_on` ascending, so the longest overdue is at the top
 * as a consequence of the calendar, not of a score. There is no priority, no urgency band, no
 * "risk of non-attendance" and no needs-review triage — and, deliberately, NO :class or :style
 * keyed to `due_in_days`: an overdue row and a not-yet-due row carry exactly the same chrome, and
 * the words and the date say the rest (D-169). Painting the overdue rows red would be the system
 * ranking patients by a number it derived.
 *
 * NOTHING HERE CONTACTS A PATIENT. The draft action asks the existing recall tool for WORDING,
 * which — at its SUGGEST ceiling — can only be proposed to the approval queue for a human to
 * review and send.
 */

const { t } = useI18n();

const props = defineProps<{
    recalls: Array<{
        id: string;
        status: string;
        due_on: string;
        due_in_days: number;
        rule_id: string;
        rule_name: string | null;
        patient: { id: string; mrn: string; name: string; chart_url: string } | null;
        has_comms_consent: boolean;
        urls: { transition: string; draft: string };
    }>;
    rules: Array<{ id: string; name: string }>;
    filters: { status: string | null; rule_id: string | null; within_days: number | null };
    statuses: string[];
    totals: { shown: number };
    actions: { can_write: boolean; approvals_url: string };
}>();

const draftFor = ref<string | null>(null);
const draftTemplate = ref('');

/** The recorded interval, in words. A calendar fact — it grades nothing. */
function intervalLabel(days: number): string {
    if (days === 0) return t('clinical.recalls.dueToday');
    if (days < 0) return t('clinical.recalls.overdueBy', { days: Math.abs(days) });
    return t('clinical.recalls.dueIn', { days });
}

function statusLabel(status: string): string {
    const key = `clinical.recalls.statuses.${status}`;
    const label = t(key);
    return label === key ? status : label;
}

const withinOptions = ['0', '7', '30', '90'] as const;

function applyFilters(next: Partial<{ status: string | null; rule_id: string | null; within_days: string | null }>): void {
    const params: Record<string, string> = {};
    const status = next.status !== undefined ? next.status : props.filters.status;
    const ruleId = next.rule_id !== undefined ? next.rule_id : props.filters.rule_id;
    const within = next.within_days !== undefined ? next.within_days : (props.filters.within_days === null ? null : String(props.filters.within_days));
    if (status) params.status = status;
    if (ruleId) params.rule_id = ruleId;
    if (within !== null && within !== undefined) params.within_days = within;
    router.get('/clinical/recalls', params, { preserveScroll: true, preserveState: true });
}

// Each posts to the EXISTING service route; no status is written here.
function transition(url: string, status: string): void {
    router.post(url, { status }, { preserveScroll: true });
}

function submitDraft(url: string): void {
    if (draftTemplate.value.trim() === '') return;
    router.post(url, { template: draftTemplate.value }, {
        preserveScroll: true,
        onSuccess: () => {
            draftFor.value = null;
            draftTemplate.value = '';
        },
    });
}

const transitionTargets = computed(() => ['contacted', 'booked', 'completed', 'dismissed']);
</script>

<template>
    <AppLayout>
        <Head :title="t('clinical.recalls.title')" />
        <div class="space-y-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('clinical.recalls.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('clinical.recalls.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('clinical.recalls.summary', { count: totals.shown }) }}</p>
            </div>

            <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <div class="space-y-4">
                    <!-- Filters over REAL recorded attributes only: status, the tenant's own recall
                         rules, and a plain calendar window. There is no priority filter, because
                         there is no priority. -->
                    <div class="glass-card space-y-3 p-5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('clinical.recalls.status') }}</span>
                            <button
                                type="button"
                                class="rounded-full border px-3 py-1 text-sm font-medium transition"
                                :class="filters.status === null ? 'border-euca-600 bg-euca-50 text-euca-800' : 'border-line bg-surface text-ink-muted hover:bg-surface-2'"
                                @click="applyFilters({ status: null })"
                            >{{ t('clinical.recalls.allStatuses') }}</button>
                            <button
                                v-for="status in statuses"
                                :key="status"
                                type="button"
                                class="rounded-full border px-3 py-1 text-sm font-medium transition"
                                :class="filters.status === status ? 'border-euca-600 bg-euca-50 text-euca-800' : 'border-line bg-surface text-ink-muted hover:bg-surface-2'"
                                @click="applyFilters({ status })"
                            >{{ statusLabel(status) }}</button>
                        </div>

                        <div v-if="rules.length" class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('clinical.recalls.rule') }}</span>
                            <button
                                type="button"
                                class="rounded-full border px-3 py-1 text-sm font-medium transition"
                                :class="filters.rule_id === null ? 'border-euca-600 bg-euca-50 text-euca-800' : 'border-line bg-surface text-ink-muted hover:bg-surface-2'"
                                @click="applyFilters({ rule_id: null })"
                            >{{ t('clinical.recalls.allRules') }}</button>
                            <button
                                v-for="rule in rules"
                                :key="rule.id"
                                type="button"
                                class="rounded-full border px-3 py-1 text-sm font-medium transition"
                                :class="filters.rule_id === rule.id ? 'border-euca-600 bg-euca-50 text-euca-800' : 'border-line bg-surface text-ink-muted hover:bg-surface-2'"
                                @click="applyFilters({ rule_id: rule.id })"
                            >{{ rule.name }}</button>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('clinical.recalls.due') }}</span>
                            <button
                                type="button"
                                class="rounded-full border px-3 py-1 text-sm font-medium transition"
                                :class="filters.within_days === null ? 'border-euca-600 bg-euca-50 text-euca-800' : 'border-line bg-surface text-ink-muted hover:bg-surface-2'"
                                @click="applyFilters({ within_days: null })"
                            >{{ t('clinical.recalls.anyDate') }}</button>
                            <button
                                v-for="option in withinOptions"
                                :key="option"
                                type="button"
                                class="rounded-full border px-3 py-1 text-sm font-medium transition"
                                :class="String(filters.within_days) === option ? 'border-euca-600 bg-euca-50 text-euca-800' : 'border-line bg-surface text-ink-muted hover:bg-surface-2'"
                                @click="applyFilters({ within_days: option })"
                            >{{ option === '0' ? t('clinical.recalls.dueNow') : t('clinical.recalls.withinDays', { days: option }) }}</button>
                        </div>

                        <p class="border-t border-line pt-3 text-xs text-ink-subtle">{{ t('clinical.recalls.orderNote') }}</p>
                    </div>

                    <!-- The worklist. Every row carries IDENTICAL chrome, however overdue it is. -->
                    <div v-if="recalls.length" class="glass-card divide-y divide-line p-0">
                        <div v-for="recall in recalls" :key="recall.id" class="space-y-2 p-5">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <Link
                                        v-if="recall.patient"
                                        :href="recall.patient.chart_url"
                                        class="font-semibold text-ink transition hover:text-euca-800"
                                    >{{ recall.patient.name }}</Link>
                                    <span v-else class="font-semibold text-ink">{{ t('clinical.recalls.unknownPatient') }}</span>
                                    <p v-if="recall.patient" class="mt-0.5 font-mono text-xs text-ink-muted">{{ recall.patient.mrn }}</p>
                                </div>
                                <span class="rounded-full bg-surface-2 px-2.5 py-1 text-xs font-semibold text-ink-muted">
                                    {{ statusLabel(recall.status) }}
                                </span>
                            </div>

                            <p class="flex flex-wrap items-center gap-x-2 text-sm text-ink-muted">
                                <span class="font-medium text-ink">{{ recall.rule_name ?? t('clinical.recalls.noRule') }}</span>
                                <span>·</span>
                                <span>{{ recall.due_on }}</span>
                                <span>·</span>
                                <!-- The plain interval. Same classes whether overdue or not. -->
                                <span>{{ intervalLabel(recall.due_in_days) }}</span>
                            </p>

                            <p v-if="!recall.has_comms_consent" class="text-xs text-ink-subtle">{{ t('clinical.recalls.noConsent') }}</p>

                            <div v-if="actions.can_write" class="flex flex-wrap items-center gap-2 pt-1">
                                <button
                                    v-for="target in transitionTargets"
                                    :key="target"
                                    type="button"
                                    class="rounded-xl border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink transition hover:bg-surface-2"
                                    @click="transition(recall.urls.transition, target)"
                                >{{ t('clinical.recalls.mark', { status: statusLabel(target) }) }}</button>

                                <button
                                    type="button"
                                    class="rounded-xl border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink transition hover:bg-surface-2"
                                    @click="draftFor = draftFor === recall.id ? null : recall.id; draftTemplate = ''"
                                >{{ t('clinical.recalls.draftMessage') }}</button>
                            </div>

                            <!-- The clinician writes the wording. The agent renders it and the
                                 result waits in the approval queue for a human to send. -->
                            <div v-if="draftFor === recall.id" class="space-y-2 rounded-xl border border-line bg-surface-2 p-3">
                                <label class="block text-xs">
                                    <span class="mb-1 block font-semibold text-ink">{{ t('clinical.recalls.templateLabel') }}</span>
                                    <textarea
                                        v-model="draftTemplate"
                                        rows="3"
                                        class="block w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink"
                                        :placeholder="t('clinical.recalls.templatePlaceholder')"
                                    ></textarea>
                                </label>
                                <p class="text-xs text-ink-subtle">{{ t('clinical.recalls.draftNote') }}</p>
                                <button
                                    type="button"
                                    :disabled="!draftTemplate.trim()"
                                    class="rounded-xl border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink transition hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="submitDraft(recall.urls.draft)"
                                >{{ t('clinical.recalls.requestDraft') }}</button>
                            </div>
                        </div>
                    </div>
                    <div v-else class="glass-card p-6 text-sm text-ink-muted">{{ t('clinical.recalls.empty') }}</div>
                </div>

                <div class="space-y-4">
                    <ClinicalRailCard :title="t('clinical.recalls.scopeTitle')">
                        <div class="space-y-2 text-xs text-ink-subtle">
                            <p>{{ t('clinical.recalls.scopeOrder') }}</p>
                            <p>{{ t('clinical.recalls.scopeNoAutoSend') }}</p>
                            <p>{{ t('clinical.recalls.scopeNoTriage') }}</p>
                            <p>{{ t('clinical.recalls.scopeHuman') }}</p>
                            <p>
                                <Link :href="actions.approvals_url" class="font-semibold text-euca-700 transition hover:text-euca-800">
                                    {{ t('clinical.recalls.openApprovals') }} →
                                </Link>
                            </p>
                        </div>
                    </ClinicalRailCard>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
