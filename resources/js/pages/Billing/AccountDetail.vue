<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

/*
 * AR Account Detail — the drill target from the report's top-overdue table.
 * BILLAR.P7 wired the destination + the account header over engine figures.
 * ARDETAIL.P1 adds the per-account running-balance LEDGER: every row (amount / paid /
 * balance / running balance) is engine-computed by MetricsService::accountLedger and only
 * DISPLAYED here — the view computes NO money (the running balance + the tie come from the
 * engine, never a client sum).
 */

const { t, te } = useI18n();

type Overdue = {
    total_overdue_minor: number;
    invoice_count: number;
    max_days_overdue: number;
    max_stage: number;
    ties: boolean;
};
type LedgerRow = {
    invoice_id: string;
    number: string | null;
    issue_date: string;
    due_date: string | null;
    amount_minor: number;
    paid_minor: number;
    balance_minor: number;
    status: string;
    days_overdue: number;
    running_balance_minor: number;
};
type Ledger = {
    account_id: string;
    as_of: string;
    rows: LedgerRow[];
    invoice_count: number;
    total_amount_minor: number;
    total_paid_minor: number;
    account_outstanding_minor: number;
    ties: boolean;
};
type DunningEventRow = {
    invoice_id: string;
    invoice_number: string | null;
    level: number;
    triggered_on: string;
    status: string;
    fee_minor: number;
};
type Dunning = {
    account_id: string;
    as_of: string;
    events: DunningEventRow[];
    current_stage: number;
    reminder_count: number;
    fees_minor: number;
    fees_tie: boolean;
};

const props = defineProps<{
    account: { id: string; name: string; mrn: string | null };
    currency: string;
    overdue: Overdue | null;
    ledger: Ledger;
    dunning: Dunning;
    links: { report: string; dunning: string };
}>();

function money(minor: number): string {
    return `${(minor / 100).toFixed(2)} ${props.currency}`;
}
function stageLabel(stage: number): string {
    return stage <= 0 ? t('billing.accountDetail.stageNone') : t('billing.accountDetail.stageLevel', { n: stage });
}
function statusLabel(status: string): string {
    const key = `billing.accountDetail.status.${status}`;
    return te(key) ? t(key) : status;
}
function ageLabel(days: number): string {
    return days <= 0 ? t('billing.accountDetail.notDue') : t('billing.accountDetail.days', { n: days });
}
// The dunning level/status come straight from the persisted state machine; these only LABEL them.
function dunningLevelLabel(level: number): string {
    return t('billing.accountDetail.dunning.level', { n: level });
}
function dunningStatusLabel(status: string): string {
    const key = `billing.accountDetail.dunning.eventStatus.${status}`;
    return te(key) ? t(key) : status;
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

            <!-- Per-account running-balance ledger — every figure (incl. the running balance)
                 is engine-computed by MetricsService::accountLedger; the Vue only displays it. -->
            <div class="glass-card p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.ledgerTitle') }}</h2>
                        <span class="text-xs text-ink-subtle">{{ t('billing.accountDetail.ledgerSubtitle') }}</span>
                    </div>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="ledger.ties ? 'bg-euca-50 text-euca-800' : 'bg-warning/15 text-warning'"
                    >{{ ledger.ties ? t('billing.accountDetail.tiesOk', { total: money(ledger.account_outstanding_minor) }) : t('billing.accountDetail.tiesOff') }}</span>
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="px-2 py-2 font-semibold">{{ t('billing.accountDetail.col.invoice') }}</th>
                                <th class="px-2 py-2 font-semibold">{{ t('billing.accountDetail.col.date') }}</th>
                                <th class="px-2 py-2 font-semibold">{{ t('billing.accountDetail.col.status') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.accountDetail.col.age') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.accountDetail.col.amount') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.accountDetail.col.paid') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.accountDetail.col.balance') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.accountDetail.col.running') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="row in ledger.rows" :key="row.invoice_id">
                                <td class="px-2 py-2.5 font-mono text-xs text-ink">{{ row.number ?? '—' }}</td>
                                <td class="px-2 py-2.5 tabular-nums text-ink-muted">{{ row.issue_date }}</td>
                                <td class="px-2 py-2.5">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full" :class="row.days_overdue > 0 ? 'bg-warning' : 'bg-euca-500'"></span>
                                        <span class="text-ink">{{ statusLabel(row.status) }}</span>
                                    </span>
                                </td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-ink-muted">{{ ageLabel(row.days_overdue) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-ink">{{ money(row.amount_minor) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-euca-700">{{ money(row.paid_minor) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-ink">{{ money(row.balance_minor) }}</td>
                                <td class="px-2 py-2.5 text-right font-semibold tabular-nums text-ink">{{ money(row.running_balance_minor) }}</td>
                            </tr>
                            <tr v-if="ledger.rows.length === 0">
                                <td colspan="8" class="px-2 py-6 text-center text-ink-muted">{{ t('billing.accountDetail.ledgerEmpty') }}</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="ledger.rows.length">
                            <tr class="border-t-2 border-line font-semibold text-ink">
                                <td class="px-2 py-2.5" colspan="4">{{ t('billing.accountDetail.balanceDue') }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums">{{ money(ledger.total_amount_minor) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-euca-700">{{ money(ledger.total_paid_minor) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums">{{ money(ledger.account_outstanding_minor) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums">{{ money(ledger.account_outstanding_minor) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Dunning timeline — a READ-ONLY display of the real state machine (append-only
                 dunning_events). The stage is the persisted max level; the fees are the real
                 captured charges. No dunning action here (send-reminder / escalation are later gates). -->
            <div class="glass-card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.dunning.title') }}</h2>
                        <span class="text-xs text-ink-subtle">{{ t('billing.accountDetail.dunning.subtitle') }}</span>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="dunning.current_stage > 0 ? 'bg-warning/15 text-warning' : 'bg-euca-50 text-euca-800'"
                    >
                        <span class="h-2 w-2 rounded-full" :class="dunning.current_stage > 0 ? 'bg-warning' : 'bg-euca-500'"></span>
                        {{ stageLabel(dunning.current_stage) }}
                    </span>
                </div>

                <!-- Account dunning figures (all real: reminder count + Σ the captured fee charges) -->
                <div class="mt-3 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl bg-surface-2 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.dunning.stage') }}</p>
                        <p class="mt-1 text-lg font-semibold text-ink">{{ stageLabel(dunning.current_stage) }}</p>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.dunning.reminders') }}</p>
                        <p class="mt-1 text-lg font-semibold text-ink tabular-nums">{{ dunning.reminder_count }}</p>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.dunning.fees') }}</p>
                        <p class="mt-1 text-lg font-semibold text-ink tabular-nums">{{ money(dunning.fees_minor) }}</p>
                    </div>
                </div>

                <!-- The timeline of the real events -->
                <ol v-if="dunning.events.length" class="mt-4 space-y-0">
                    <li v-for="(ev, i) in dunning.events" :key="ev.invoice_id + '-' + ev.level" class="flex gap-3">
                        <div class="flex flex-col items-center">
                            <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full" :class="ev.level > 0 ? 'bg-warning' : 'bg-euca-500'"></span>
                            <span v-if="i < dunning.events.length - 1" class="w-px flex-1 bg-line"></span>
                        </div>
                        <div class="flex flex-1 flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 pb-4">
                            <div>
                                <span class="text-sm font-medium text-ink">{{ dunningLevelLabel(ev.level) }}</span>
                                <span v-if="ev.invoice_number" class="ml-2 font-mono text-xs text-ink-subtle">{{ ev.invoice_number }}</span>
                                <span class="ml-2 text-xs text-ink-muted">· {{ dunningStatusLabel(ev.status) }}</span>
                            </div>
                            <div class="flex items-baseline gap-3">
                                <span v-if="ev.fee_minor > 0" class="text-xs tabular-nums text-ink-muted">{{ t('billing.accountDetail.dunning.fee', { amount: money(ev.fee_minor) }) }}</span>
                                <span class="tabular-nums text-xs text-ink-subtle">{{ ev.triggered_on }}</span>
                            </div>
                        </div>
                    </li>
                </ol>
                <p v-else class="mt-4 text-sm text-ink-muted">{{ t('billing.accountDetail.dunning.empty') }}</p>
            </div>
        </div>
    </AppLayout>
</template>
