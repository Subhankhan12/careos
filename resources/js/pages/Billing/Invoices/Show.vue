<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t, te } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type Line = {
    id: string;
    code: string | null;
    description: string;
    quantity: number;
    unit_price_minor: number;
    vat_rate_bp: number;
    line_total_minor: number;
    line_vat_minor: number;
};
type Invoice = {
    id: string;
    number: string | null;
    series: string;
    status: string;
    payer_name: string | null;
    payer_type: string;
    patient: { name: string; mrn: string | null; date_of_birth: string | null } | null;
    issue_date: string | null;
    due_date: string | null;
    currency: string;
    subtotal_minor: number;
    vat_total_minor: number;
    total_minor: number;
    open_balance_minor: number;
    has_pdf: boolean;
    lines: Line[];
    credit_notes: { id: string; number: string | null; total_minor: number; show_url: string }[];
};

const props = defineProps<{
    invoice: Invoice;
    actions: { can_manage: boolean; issue_url: string; credit_note_url: string; download_url: string };
}>();

function money(minor: number, currency = props.invoice.currency): string {
    return `${(minor / 100).toFixed(2)} ${currency}`;
}
function vatRate(bp: number): string {
    return `${(bp / 100).toFixed(1)}%`;
}
function formatDate(value: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d);
    } catch {
        return value;
    }
}
function statusLabel(status: string): string {
    const key = `billing.status.${status}`;
    return te(key) ? t(key) : status;
}

const isDraft = computed(() => props.invoice.status === 'draft');
const canCreditNote = computed(() => !['draft', 'cancelled_by_credit_note'].includes(props.invoice.status));
const statusClass = computed(() => {
    return {
        draft: 'bg-warning-soft text-warning',
        issued: 'bg-euca-50 text-euca-800',
        partially_paid: 'bg-euca-100 text-euca-800',
        paid: 'bg-euca-100 text-euca-800',
        cancelled_by_credit_note: 'bg-surface-2 text-ink-muted',
    }[props.invoice.status] ?? 'bg-surface-2 text-ink-muted';
});

// WRITES go through the controller → IssueService only; this form carries no money.
const issueForm = useForm({});
function issue(): void {
    issueForm.post(props.actions.issue_url, { preserveScroll: true });
}

const showCreditForm = ref(false);
const creditForm = useForm({ reason: '' });
function submitCreditNote(): void {
    creditForm.post(props.actions.credit_note_url, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="invoice.number ?? t('billing.status.draft')" />
        <div class="space-y-5">
            <!-- Breadcrumb -->
            <nav class="flex items-center gap-2 text-sm text-ink-muted">
                <Link href="/billing/invoices" class="hover:text-ink">{{ t('billing.invoices.title') }}</Link>
                <span aria-hidden="true">/</span>
                <span class="font-medium text-ink">{{ invoice.number ?? t('billing.status.draft') }}</span>
            </nav>

            <!-- Header tile -->
            <div class="euca-tile-dark flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight text-euca-50">{{ invoice.number ?? t('billing.invoices.draftInvoice') }}</h1>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusClass">{{ statusLabel(invoice.status) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-euca-200">{{ invoice.payer_name ?? '—' }} · {{ t('billing.invoices.issued') }} {{ formatDate(invoice.issue_date) }} · {{ t('billing.invoices.due') }} {{ formatDate(invoice.due_date) }}</p>
                </div>
                <div class="text-left lg:text-right">
                    <p class="text-xs font-semibold uppercase tracking-wide text-euca-200">{{ t('billing.invoices.balanceDue') }}</p>
                    <p class="text-2xl font-semibold text-euca-50">{{ money(invoice.open_balance_minor) }}</p>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <!-- Invoice document -->
                <div class="glass-card space-y-6 p-6 lg:col-span-2">
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.invoices.billedTo') }}</p>
                            <p class="mt-1 font-medium text-ink">{{ invoice.payer_name ?? '—' }}</p>
                            <p class="text-sm text-ink-muted">{{ te(`billing.payerType.${invoice.payer_type}`) ? t(`billing.payerType.${invoice.payer_type}`) : invoice.payer_type }}</p>
                        </div>
                        <div v-if="invoice.patient">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.invoices.forPatient') }}</p>
                            <p class="mt-1 font-medium text-ink">{{ invoice.patient.name }}</p>
                            <p class="text-sm text-ink-muted">{{ invoice.patient.mrn ?? '—' }}</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                                <tr class="border-b border-line">
                                    <th class="py-2 pr-3 font-semibold">{{ t('billing.lines.code') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ t('billing.lines.description') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ t('billing.lines.qty') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ t('billing.lines.unit') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ t('billing.lines.vat') }}</th>
                                    <th class="py-2 pl-3 text-right font-semibold">{{ t('billing.lines.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line/70">
                                <tr v-for="line in invoice.lines" :key="line.id">
                                    <td class="py-3 pr-3 font-mono text-ink-muted">{{ line.code ?? '—' }}</td>
                                    <td class="px-3 py-3 text-ink">{{ line.description }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-ink-muted">{{ line.quantity }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-ink-muted">{{ money(line.unit_price_minor) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-ink-muted">{{ vatRate(line.vat_rate_bp) }}</td>
                                    <td class="py-3 pl-3 text-right tabular-nums text-ink">{{ money(line.line_total_minor) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals: values are precomputed by the billing engine; the view only lays them out. -->
                    <div class="ml-auto w-full max-w-xs space-y-1.5 text-sm">
                        <div class="flex justify-between text-ink-muted">
                            <span>{{ t('billing.totals.subtotal') }}</span>
                            <span class="tabular-nums">{{ money(invoice.subtotal_minor) }}</span>
                        </div>
                        <div class="flex justify-between text-ink-muted">
                            <span>{{ t('billing.totals.vat') }}</span>
                            <span class="tabular-nums">{{ money(invoice.vat_total_minor) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-line pt-1.5 text-base font-semibold text-ink">
                            <span>{{ t('billing.totals.total') }}</span>
                            <span class="tabular-nums">{{ money(invoice.total_minor) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Actions rail -->
                <div class="space-y-4">
                    <div v-if="actions.can_manage" class="glass-card space-y-3 p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.actions.title') }}</p>

                        <button v-if="isDraft" type="button" class="btn-glow w-full justify-center" :disabled="issueForm.processing" @click="issue">
                            {{ t('billing.actions.issue') }}
                        </button>

                        <a v-if="invoice.has_pdf" :href="actions.download_url" class="flex w-full items-center justify-center rounded-xl border border-line bg-white/60 px-4 py-2 text-sm font-semibold text-ink transition hover:bg-white">
                            {{ t('billing.actions.download') }}
                        </a>

                        <template v-if="canCreditNote">
                            <button v-if="!showCreditForm" type="button" class="flex w-full items-center justify-center rounded-xl border border-danger/30 bg-danger-soft px-4 py-2 text-sm font-semibold text-danger transition hover:bg-danger-soft/70" @click="showCreditForm = true">
                                {{ t('billing.actions.creditNote') }}
                            </button>
                            <form v-else class="space-y-2" @submit.prevent="submitCreditNote">
                                <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="cn-reason">{{ t('billing.actions.creditReason') }}</label>
                                <textarea id="cn-reason" v-model="creditForm.reason" rows="3" maxlength="500" required class="w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" :placeholder="t('billing.actions.creditReasonPlaceholder')"></textarea>
                                <p v-if="creditForm.errors.reason" class="text-xs text-danger">{{ creditForm.errors.reason }}</p>
                                <div class="flex gap-2">
                                    <button type="submit" class="flex-1 rounded-xl bg-danger px-4 py-2 text-sm font-semibold text-white transition hover:bg-danger/90 disabled:opacity-60" :disabled="creditForm.processing || creditForm.reason.trim().length === 0">{{ t('billing.actions.creditConfirm') }}</button>
                                    <button type="button" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink-muted transition hover:bg-surface-2" @click="showCreditForm = false; creditForm.reset()">{{ t('billing.actions.cancel') }}</button>
                                </div>
                            </form>
                        </template>
                    </div>
                    <p v-else class="glass-card p-5 text-sm text-ink-muted">{{ t('billing.actions.viewOnly') }}</p>

                    <!-- Linked credit notes -->
                    <div v-if="invoice.credit_notes.length" class="glass-card space-y-2 p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.creditNotes.linked') }}</p>
                        <Link v-for="cn in invoice.credit_notes" :key="cn.id" :href="cn.show_url" class="flex items-center justify-between rounded-xl border border-line bg-white/50 px-3 py-2 text-sm transition hover:bg-white">
                            <span class="font-mono text-ink">{{ cn.number ?? '—' }}</span>
                            <span class="tabular-nums text-ink-muted">{{ money(cn.total_minor) }}</span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
