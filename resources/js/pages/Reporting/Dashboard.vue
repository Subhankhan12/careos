<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t, te } = useI18n();

type Appointments = { total: number; by_status: Record<string, number> };
type NoShows = { no_show: number; scheduled: number; rate: number };
type Aging = { current: number; days_1_30: number; days_31_60: number; days_61_90: number; days_90_plus: number };
type Financial = {
    invoiced_total_minor: number;
    payments_received_total_minor: number;
    outstanding_balance_minor: number;
    aging: Aging;
};
type Summary = {
    range: { from: string; to: string; branch_id: string | null };
    operational: {
        appointments: Appointments;
        no_shows: NoShows;
        checked_in: number;
        visits_completed: number;
        active_patients: number;
    };
    throughput: { encounters: number; signed_notes: number; orders_placed: number };
    financial?: Financial;
};

const props = defineProps<{
    summary: Summary;
    currency: string;
    hasFinancial: boolean;
    filtersUrl: string;
}>();

// Money is integer minor units from the tested service; the view only formats.
function money(minor: number): string {
    return `${(minor / 100).toFixed(2)} ${props.currency}`;
}
// no_shows.rate is a raw ratio returned by the service (a fact, not a judgment);
// rendering it as a percentage is pure formatting — nothing is graded or coloured.
function percent(rate: number): string {
    return `${(rate * 100).toFixed(1)}%`;
}
function apptStatusLabel(status: string): string {
    const key = `reporting.apptStatus.${status}`;
    return te(key) ? t(key) : status;
}
const agingKeys: (keyof Aging)[] = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

const from = ref(props.summary.range.from);
const to = ref(props.summary.range.to);
function apply(): void {
    router.get(props.filtersUrl, { from: from.value, to: to.value }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('reporting.title')" />
        <div class="space-y-5">
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 lg:flex-row lg:items-end">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('reporting.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('reporting.title') }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ t('reporting.range', { from: summary.range.from, to: summary.range.to }) }}</p>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="text-xs font-medium text-euca-200">{{ t('reporting.from') }}
                        <input v-model="from" type="date" class="mt-1 block rounded-xl border border-white/20 bg-white/15 px-3 py-1.5 text-sm text-euca-50 [color-scheme:dark]" />
                    </label>
                    <label class="text-xs font-medium text-euca-200">{{ t('reporting.to') }}
                        <input v-model="to" type="date" class="mt-1 block rounded-xl border border-white/20 bg-white/15 px-3 py-1.5 text-sm text-euca-50 [color-scheme:dark]" />
                    </label>
                    <button type="button" class="rounded-xl bg-white/20 px-4 py-1.5 text-sm font-semibold text-euca-50 transition hover:bg-white/30" @click="apply">{{ t('reporting.apply') }}</button>
                </div>
            </div>

            <!-- OPERATIONAL — counts only -->
            <section class="space-y-3">
                <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.operational') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.appointments') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ summary.operational.appointments.total }}</p>
                    </div>
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.noShows') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ summary.operational.no_shows.no_show }}</p>
                        <p class="text-xs text-ink-muted">{{ t('reporting.ofScheduled', { rate: percent(summary.operational.no_shows.rate), scheduled: summary.operational.no_shows.scheduled }) }}</p>
                    </div>
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.checkedIn') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ summary.operational.checked_in }}</p>
                    </div>
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.activePatients') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ summary.operational.active_patients }}</p>
                    </div>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.byStatus') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span v-for="(count, status) in summary.operational.appointments.by_status" :key="status" class="inline-flex items-center gap-1.5 rounded-full bg-euca-50 px-3 py-1 text-xs text-euca-800">
                            <span class="font-medium">{{ apptStatusLabel(String(status)) }}</span>
                            <span class="tabular-nums font-semibold">{{ count }}</span>
                        </span>
                    </div>
                    <p class="mt-3 text-sm text-ink-muted">{{ t('reporting.visitsCompleted') }}: <span class="font-semibold text-ink tabular-nums">{{ summary.operational.visits_completed }}</span></p>
                </div>
            </section>

            <!-- THROUGHPUT — counts only -->
            <section class="space-y-3">
                <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.throughput') }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.encounters') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ summary.throughput.encounters }}</p>
                    </div>
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.signedNotes') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ summary.throughput.signed_notes }}</p>
                    </div>
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.ordersPlaced') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ summary.throughput.orders_placed }}</p>
                    </div>
                </div>
            </section>

            <!-- FINANCIAL — sums only, present only with billing.view -->
            <section v-if="hasFinancial && summary.financial" class="space-y-3">
                <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.financial') }}</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.invoiced') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ money(summary.financial.invoiced_total_minor) }}</p>
                    </div>
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.received') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ money(summary.financial.payments_received_total_minor) }}</p>
                    </div>
                    <div class="glass-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('reporting.outstanding') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-ink">{{ money(summary.financial.outstanding_balance_minor) }}</p>
                    </div>
                </div>
                <div class="glass-card p-2">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                                <tr class="border-b border-line">
                                    <th class="px-3 py-2 font-semibold">{{ t('reporting.agingBucket') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ t('reporting.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line/70">
                                <tr v-for="key in agingKeys" :key="key">
                                    <td class="px-3 py-2.5 text-ink">{{ t(`billing.aging.buckets.${key}`) }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ money(summary.financial.aging[key]) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <p class="px-1 text-xs text-ink-subtle">{{ t('reporting.footnote') }}</p>
        </div>
    </AppLayout>
</template>
