<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type Buckets = { current: number; days_1_30: number; days_31_60: number; days_61_90: number; days_90_plus: number };

const props = defineProps<{
    asOf: string;
    currency: string;
    outstanding_minor: number;
    buckets: Buckets;
    monthToDate: { invoiced_minor: number; collected_minor: number };
    invoicesUrl: string;
}>();

function money(minor: number): string {
    return `${(minor / 100).toFixed(2)} ${props.currency}`;
}
function formatDate(value: string): string {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'long', year: 'numeric' }).format(d);
    } catch {
        return value;
    }
}

// Bucket order + share are pure presentation of the server's factual amounts.
const rows = computed(() => {
    const total = props.outstanding_minor;
    const keys: (keyof Buckets)[] = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];
    return keys.map((key) => ({
        key,
        amount: props.buckets[key],
        share: total > 0 ? (props.buckets[key] / total) * 100 : 0,
        pastDue: key !== 'current',
    }));
});
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.aging.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-end">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('billing.aging.title') }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ t('billing.aging.asOf', { date: formatDate(asOf) }) }}</p>
                </div>
                <Link :href="invoicesUrl" class="self-start rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25 sm:self-auto">{{ t('billing.nav.invoices') }}</Link>
            </div>

            <!-- Top-line facts -->
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.aging.outstanding') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(outstanding_minor) }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.aging.invoicedMtd') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(monthToDate.invoiced_minor) }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.aging.collectedMtd') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(monthToDate.collected_minor) }}</p>
                </div>
            </div>

            <!-- Aging buckets -->
            <div class="glass-card p-2">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="px-3 py-2 font-semibold">{{ t('billing.aging.bucket') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.aging.share') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('billing.aging.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="row in rows" :key="row.key">
                                <td class="px-3 py-3">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2 w-2 rounded-full" :class="row.pastDue ? 'bg-warning' : 'bg-euca-500'"></span>
                                        <span class="font-medium text-ink">{{ t(`billing.aging.buckets.${row.key}`) }}</span>
                                    </span>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-surface-2">
                                            <div class="h-full rounded-full" :class="row.pastDue ? 'bg-warning' : 'bg-euca-500'" :style="{ width: `${Math.min(row.share, 100)}%` }"></div>
                                        </div>
                                        <span class="tabular-nums text-xs text-ink-muted">{{ row.share.toFixed(1) }}%</span>
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink">{{ money(row.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-line font-semibold text-ink">
                                <td class="px-3 py-3">{{ t('billing.aging.total') }}</td>
                                <td class="px-3 py-3"></td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ money(outstanding_minor) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <p class="px-1 text-xs text-ink-subtle">{{ t('billing.aging.footnote') }}</p>
        </div>
    </AppLayout>
</template>
