<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

/*
 * BILLAR.P7 — AR Account Detail (the drill target from the report's top-overdue table).
 * This gate wires the destination + a minimal account header over ENGINE figures (the
 * account's overdue total / age / stage from MetricsService::topOverdueAccounts). The
 * full per-invoice ledger is the NEXT gate; the view computes no money.
 */

const { t } = useI18n();

type Overdue = {
    total_overdue_minor: number;
    invoice_count: number;
    max_days_overdue: number;
    max_stage: number;
    ties: boolean;
};

const props = defineProps<{
    account: { id: string; name: string; mrn: string | null };
    currency: string;
    overdue: Overdue | null;
    links: { report: string; dunning: string };
}>();

function money(minor: number): string {
    return `${(minor / 100).toFixed(2)} ${props.currency}`;
}
function stageLabel(stage: number): string {
    return stage <= 0 ? t('billing.accountDetail.stageNone') : t('billing.accountDetail.stageLevel', { n: stage });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.accountDetail.title', { name: account.name })" />
        <div class="space-y-5">
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-end">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.accountDetail.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ account.name }}</h1>
                    <p v-if="account.mrn" class="mt-1 text-sm text-euca-200">{{ t('billing.accountDetail.mrn', { mrn: account.mrn }) }}</p>
                </div>
                <Link :href="links.report" class="self-start rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25 sm:self-auto">{{ t('billing.accountDetail.backToReport') }}</Link>
            </div>

            <!-- Engine figures for this account (from topOverdueAccounts) -->
            <div v-if="overdue" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.totalOverdue') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(overdue.total_overdue_minor) }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.overdueInvoices') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink tabular-nums">{{ overdue.invoice_count }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.oldest') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink tabular-nums">{{ t('billing.accountDetail.days', { n: overdue.max_days_overdue }) }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.stage') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ stageLabel(overdue.max_stage) }}</p>
                </div>
            </div>
            <div v-else class="glass-card p-5">
                <p class="text-sm text-ink-muted">{{ t('billing.accountDetail.noOverdue') }}</p>
            </div>

            <!-- The full per-invoice AR ledger is the next gate. -->
            <div class="glass-card border border-dashed border-line p-6 text-center">
                <p class="text-sm font-medium text-ink">{{ t('billing.accountDetail.ledgerSoonTitle') }}</p>
                <p class="mt-1 text-xs text-ink-subtle">{{ t('billing.accountDetail.ledgerSoonBody') }}</p>
            </div>
        </div>
    </AppLayout>
</template>
