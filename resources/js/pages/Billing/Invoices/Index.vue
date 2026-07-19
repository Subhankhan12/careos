<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateOnly } from '@/lib/date';

const { t, te } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type Invoice = {
    id: string;
    number: string | null;
    status: string;
    payer_name: string | null;
    payer_type: string;
    patient: string | null;
    issue_date: string | null;
    due_date: string | null;
    currency: string;
    total_minor: number;
    open_balance_minor: number;
    show_url: string;
};

const props = defineProps<{
    filters: { status: string | null };
    invoices: Invoice[];
    counters: { outstanding_minor: number; overdue_minor: number; drafts: number; paid: number; currency: string };
    agingUrl: string;
    creditNotesUrl: string;
    paymentsUrl: string;
    dunningUrl: string;
    newInvoiceUrl: string;
    canManage: boolean;
}>();

// Money is integer minor units from the server; the view only formats it.
function money(minor: number, currency: string): string {
    return `${(minor / 100).toFixed(2)} ${currency}`;
}
function formatDate(value: string | null): string {
    // Date-only → local-midnight parse so the day never shifts by timezone (M-2).
    return formatDateOnly(value, locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' });
}
function statusLabel(status: string): string {
    const key = `billing.status.${status}`;
    return te(key) ? t(key) : status;
}
// The row shows the invoice's real lifecycle status (balance.status) only. "Overdue"
// is an aging-derived reporting figure, never recomputed here — it lives on the AR
// page and the counter above, both sourced from the tested MetricsService.
function statusClass(status: string): string {
    return {
        draft: 'bg-warning-soft text-warning',
        issued: 'bg-euca-50 text-euca-800',
        partially_paid: 'bg-euca-100 text-euca-800',
        paid: 'bg-euca-100 text-euca-800',
        cancelled_by_credit_note: 'bg-surface-2 text-ink-muted',
    }[status] ?? 'bg-surface-2 text-ink-muted';
}

const statusFilters = ['draft', 'issued', 'paid'] as const;
function setStatus(status: string | null): void {
    router.get('/billing/invoices', status ? { status } : {}, { preserveState: true, replace: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.invoices.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('billing.invoices.title') }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ t('billing.invoices.subtitle', { count: invoices.length }) }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link v-if="canManage" :href="newInvoiceUrl" class="btn-glow">{{ t('billing.nav.newInvoice') }}</Link>
                    <Link :href="paymentsUrl" class="rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25">{{ t('billing.nav.payments') }}</Link>
                    <Link :href="dunningUrl" class="rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25">{{ t('billing.nav.dunning') }}</Link>
                    <Link :href="agingUrl" class="rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25">{{ t('billing.nav.aging') }}</Link>
                    <Link :href="creditNotesUrl" class="rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25">{{ t('billing.nav.creditNotes') }}</Link>
                </div>
            </div>

            <!-- Counters -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.counters.outstanding') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(counters.outstanding_minor, counters.currency) }}</p>
                </div>
                <div class="rounded-2xl border border-danger/25 bg-danger-soft p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-danger">{{ t('billing.counters.overdue') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(counters.overdue_minor, counters.currency) }}</p>
                </div>
                <div class="rounded-2xl border border-warning/30 bg-warning-soft p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-warning">{{ t('billing.counters.drafts') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ counters.drafts }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.counters.paid') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ counters.paid }}</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-1 rounded-full bg-euca-50/70 p-1">
                <button type="button" class="rounded-full px-3.5 py-1.5 text-sm font-medium transition" :class="!filters.status ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setStatus(null)">{{ t('billing.invoices.all') }}</button>
                <button v-for="s in statusFilters" :key="s" type="button" class="rounded-full px-3.5 py-1.5 text-sm font-medium transition" :class="filters.status === s ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setStatus(s)">{{ statusLabel(s) }}</button>
            </div>

            <!-- Table -->
            <div class="glass-card overflow-hidden p-2">
                <div class="overflow-x-auto">
                    <table v-if="invoices.length" class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="px-3 py-2 font-semibold">{{ t('billing.invoices.number') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.invoices.patient') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.invoices.issued') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.invoices.due') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('billing.invoices.amount') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.invoices.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="invoice in invoices" :key="invoice.id" class="cursor-pointer transition hover:bg-euca-50/50" @click="router.visit(invoice.show_url)">
                                <td class="px-3 py-3 font-mono font-medium text-ink">{{ invoice.number ?? t('billing.status.draft') }}</td>
                                <td class="px-3 py-3 text-ink">{{ invoice.patient ?? invoice.payer_name ?? '—' }}</td>
                                <td class="px-3 py-3 text-ink-muted">{{ formatDate(invoice.issue_date) }}</td>
                                <td class="px-3 py-3 text-ink-muted">{{ formatDate(invoice.due_date) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink">{{ money(invoice.total_minor, invoice.currency) }}</td>
                                <td class="px-3 py-3">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusClass(invoice.status)">
                                        {{ statusLabel(invoice.status) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-12 text-center text-sm text-ink-muted">{{ t('billing.invoices.empty') }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
