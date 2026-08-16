<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

/*
 * AR Account Detail — the drill target from the report's top-overdue table.
 * BILLAR.P7 wired the destination + the account header over engine figures.
 * ARDETAIL.P1 adds the per-account running-balance LEDGER: every row (amount / paid /
 * balance / running balance) is engine-computed by MetricsService::accountLedger and only
 * DISPLAYED here — the view computes NO money (the running balance + the tie come from the
 * engine, never a client sum).
 * ARDETAIL.P4 adds the record-payment action. It POSTs what the OPERATOR typed to the
 * server, which records + allocates through the existing PaymentService: no allocation is
 * computed here, no balance is written here, and the amount fields are plain major→minor
 * input normalisation (the existing Payments/Record.vue idiom). The service's
 * over-allocation guard — not this form — decides what may be allocated.
 */

import { formatSwissMoney } from '@/lib/money';

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
    pdf_url: string;
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

type OpenInvoice = {
    invoice_id: string;
    number: string | null;
    open_balance_minor: number;
};
type PaymentAction = {
    can_record: boolean;
    store_url: string;
    methods: string[];
    open_invoices: OpenInvoice[];
};

type PlanInstallment = {
    id: string;
    sequence: number;
    due_date: string;
    amount_minor: number;
    status: string;
    overdue: boolean;
    paid_on: string | null;
    pay_url: string;
};
type Plan = {
    id: string;
    status: string;
    total_minor: number;
    currency: string;
    installment_count: number;
    start_date: string;
    outstanding_at_creation_minor: number;
    paid_minor: number;
    remaining_minor: number;
    closed_reason: string | null;
    ties: boolean;
    installments: PlanInstallment[];
    cancel_url: string;
};
type PlanAction = {
    can_manage: boolean;
    store_url: string;
    current: Plan | null;
};

type EnforcementEligibility = {
    eligible: boolean;
    reason: string;
    terminal_stage: number | null;
    reached_stage: number;
    outstanding_minor: number;
    already_escalated: boolean;
};
type EnforcementRecord = {
    id: string;
    outstanding_minor: number;
    dunning_stage: number;
    reason: string;
    reference: string | null;
    initiated_on: string;
    initiated_by: string | null;
};
type Enforcement = {
    eligibility: EnforcementEligibility;
    current: EnforcementRecord | null;
    history: { id: string; action: string; reason: string; dunning_stage: number; outstanding_minor: number; initiated_on: string; initiated_by: string | null }[];
    can_escalate: boolean;
    store_url: string;
    withdraw_url: string | null;
};

const props = defineProps<{
    account: { id: string; name: string; mrn: string | null };
    currency: string;
    overdue: Overdue | null;
    ledger: Ledger;
    dunning: Dunning;
    payment: PaymentAction;
    plan: PlanAction;
    enforcement: Enforcement;
    links: { report: string; dunning: string; chart: string };
}>();

// Swiss CHF display format (CHF x'xxx.xx) — display only; the integer-minor figure is unchanged.
function money(minor: number): string {
    return formatSwissMoney(minor, props.currency);
}
// A presentational account-status derived from the EXISTING figures (a display state, not a
// new money figure): in-collection when dunned, overdue when past-due, else current.
const accountStatus = computed(() => {
    if (props.dunning.current_stage > 0) return 'in_collection';
    if ((props.overdue?.total_overdue_minor ?? 0) > 0) return 'overdue';
    return 'current';
});
const initials = computed(() =>
    props.account.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join(''),
);
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
function methodLabel(method: string): string {
    const key = `billing.method.${method}`;
    return te(key) ? t(key) : method;
}

/* ── ARDETAIL.P4 — record payment (the form only collects operator input) ───────────────────
 * toMinor/major are INPUT NORMALISATION between the operator's decimal field and the integer
 * minor units the API speaks — the same helper Payments/Record.vue uses. Nothing here derives
 * a balance, splits a payment across invoices, or decides what may be allocated: the operator
 * types an amount per invoice and the server's PaymentService accepts or refuses it.
 */
function toMinor(value: string): number {
    const n = Number.parseFloat(value);
    return Number.isFinite(n) ? Math.round(n * 100) : 0;
}
function major(minor: number): string {
    return (minor / 100).toFixed(2);
}
// Local calendar date (not the UTC slice of an ISO string, which shifts a day behind UTC).
function todayLocal(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

type AllocationLine = { invoice_id: string; number: string | null; open_balance_minor: number; amount: string; apply: boolean };

function buildLines(): AllocationLine[] {
    return props.payment.open_invoices.map((inv) => ({
        invoice_id: inv.invoice_id,
        number: inv.number,
        open_balance_minor: inv.open_balance_minor,
        // Pre-filled with the ENGINE's open balance, fully editable — a convenience default,
        // never a computed split (the server re-checks it against the live open balance).
        amount: major(inv.open_balance_minor),
        apply: true,
    }));
}

const showRecord = ref(false);
const lines = ref<AllocationLine[]>(buildLines());
watch(
    () => props.payment.open_invoices,
    () => {
        lines.value = buildLines();
    },
);

const form = useForm({
    amount: '',
    method: props.payment.methods[0] ?? 'bank_transfer',
    received_on: todayLocal(),
    reference: '',
});

/* ── ARDETAIL.P5 — payment plan. The page NEVER splits a schedule: it posts the operator's agreed
 * total / count / start date and the ENGINE partitions the total exactly (and refuses a total above
 * the account's real outstanding). Everything shown below is a server figure.
 */
const showPlanForm = ref(false);
const planForm = useForm({
    // Defaulted to the engine's own outstanding figure — a convenience default, not a computation.
    amount: major(props.ledger.account_outstanding_minor),
    installment_count: '3',
    start_date: todayLocal(),
});
const installmentForm = useForm({ method: props.payment.methods[0] ?? 'bank_transfer', received_on: todayLocal(), reference: '' });
const cancelForm = useForm({ reason: '' });
const payingId = ref<string | null>(null);

const planStatusIsOpen = computed(() => props.plan.current?.status === 'active');

function submitPlan(): void {
    planForm.transform((data) => ({
        total_minor: toMinor(data.amount),
        installment_count: Number.parseInt(data.installment_count, 10) || 0,
        start_date: data.start_date,
    })).post(props.plan.store_url, {
        preserveScroll: true,
        onSuccess: () => {
            showPlanForm.value = false;
        },
    });
}

function payInstallment(installment: PlanInstallment): void {
    payingId.value = installment.id;
    installmentForm.post(installment.pay_url, {
        preserveScroll: true,
        onFinish: () => {
            payingId.value = null;
        },
    });
}

function cancelPlan(): void {
    if (!props.plan.current) return;
    cancelForm.post(props.plan.current.cancel_url, { preserveScroll: true });
}

function planStatusLabel(status: string): string {
    const key = `billing.accountDetail.plan.statuses.${status}`;
    return te(key) ? t(key) : status;
}

/* ── ARDETAIL.P6 — Betreibung (debt enforcement). The page INITIATES NOTHING: it posts the
 * operator's explicitly confirmed action to the `billing.escalate`-gated route, and the server
 * re-checks eligibility. The agent has no path to this at all — the copy below states only what
 * the code actually enforces.
 */
const showEnforceForm = ref(false);
const enforceForm = useForm({ confirmed: false, reason: '', reference: '' });
const withdrawForm = useForm({ reason: '' });

function submitEnforcement(): void {
    enforceForm.post(props.enforcement.store_url, {
        preserveScroll: true,
        onSuccess: () => {
            showEnforceForm.value = false;
            enforceForm.reset();
        },
    });
}
function submitWithdrawal(): void {
    if (!props.enforcement.withdraw_url) return;
    withdrawForm.post(props.enforcement.withdraw_url, { preserveScroll: true });
}
function eligibilityLabel(e: EnforcementEligibility): string {
    const key = `billing.accountDetail.enforcement.reasons.${e.reason}`;
    return te(key) ? t(key) : e.reason;
}

function submitPayment(): void {
    form.transform((data) => ({
        amount_minor: toMinor(data.amount),
        method: data.method,
        received_on: data.received_on,
        reference: data.reference || null,
        allocations: lines.value
            .filter((line) => line.apply && toMinor(line.amount) > 0)
            .map((line) => ({ invoice_id: line.invoice_id, amount_minor: toMinor(line.amount) })),
    })).post(props.payment.store_url, {
        preserveScroll: true,
        onSuccess: () => {
            showRecord.value = false;
            form.amount = '';
            form.reference = '';
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.accountDetail.title', { name: account.name })" />
        <div class="space-y-5">
            <!-- Account hero — the balance-due headline over the EXISTING P1/P2 figures (no new figure). -->
            <div class="euca-tile-dark p-6">
                <div class="flex items-start justify-between gap-3">
                    <Link :href="links.report" class="inline-flex items-center gap-1.5 text-xs font-semibold text-euca-200 transition hover:text-euca-50">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="m14 6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        {{ t('billing.accountDetail.backToReport') }}
                    </Link>
                    <Link :href="links.chart" class="inline-flex items-center gap-1.5 rounded-xl bg-white/15 px-3 py-1.5 text-xs font-semibold text-euca-50 transition hover:bg-white/25">{{ t('billing.accountDetail.openChart') }}</Link>
                </div>

                <div class="mt-3 flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                    <div class="flex items-center gap-4">
                        <span class="flex h-14 w-14 flex-none items-center justify-center rounded-full border border-white/25 bg-white/10 text-lg font-semibold text-euca-50">{{ initials }}</span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.accountDetail.eyebrow') }}</p>
                            <h1 class="mt-0.5 text-2xl font-semibold tracking-tight text-euca-50">{{ account.name }}</h1>
                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                <span v-if="account.mrn" class="rounded-md bg-white/10 px-2 py-0.5 font-mono text-xs text-euca-100">{{ account.mrn }}</span>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="accountStatus === 'current' ? 'bg-euca-50 text-euca-800' : 'bg-warning/20 text-warning'"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full" :class="accountStatus === 'current' ? 'bg-euca-500' : 'bg-warning'"></span>
                                    {{ t(`billing.accountDetail.accountStatus.${accountStatus}`) }}
                                </span>
                                <span v-if="dunning.current_stage > 0" class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-semibold text-euca-100">
                                    {{ stageLabel(dunning.current_stage) }}
                                </span>
                            </div>
                            <p v-if="overdue" class="mt-2 text-xs text-euca-200">{{ t('billing.accountDetail.oldestLine', { days: overdue.max_days_overdue }) }}</p>
                        </div>
                    </div>
                    <div class="flex-none text-left sm:text-right">
                        <p class="text-xs font-semibold uppercase tracking-wide text-euca-200">{{ t('billing.accountDetail.balanceDue') }}</p>
                        <p class="mt-1 text-4xl font-semibold leading-none tabular-nums text-euca-50">{{ money(ledger.account_outstanding_minor) }}</p>
                        <p v-if="overdue" class="mt-1.5 text-xs text-euca-200">{{ t('billing.accountDetail.overdueOf', { amount: money(overdue.total_overdue_minor) }) }}</p>
                    </div>
                </div>
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
                                <td class="px-2 py-2.5">
                                    <a v-if="row.number" :href="row.pdf_url" class="inline-flex items-center gap-1 font-mono text-xs text-euca-700 transition hover:text-euca-900" :title="t('billing.accountDetail.invoicePdf')">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M7 3h7l4 4v14H7z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /><path d="M14 3v4h4" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /></svg>
                                        {{ row.number }}
                                    </a>
                                    <span v-else class="font-mono text-xs text-ink-subtle">—</span>
                                </td>
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

            <!-- Record payment — the account's first consequential write. The form posts what the
                 OPERATOR typed; the server records + allocates through PaymentService, whose
                 over-allocation guard is the authority. Reflect-only: hidden without billing.manage,
                 but the server Gate is what refuses. -->
            <div v-if="payment.can_record" class="glass-card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.record.title') }}</h2>
                        <span class="text-xs text-ink-subtle">{{ t('billing.accountDetail.record.subtitle') }}</span>
                    </div>
                    <button type="button" class="btn-glow" @click="showRecord = !showRecord">
                        {{ showRecord ? t('billing.actions.cancel') : t('billing.accountDetail.record.open') }}
                    </button>
                </div>

                <p v-if="form.errors.record_payment" class="mt-3 rounded-xl bg-danger/10 px-3 py-2 text-sm text-danger">{{ form.errors.record_payment }}</p>

                <form v-if="showRecord" class="mt-4 space-y-5" @submit.prevent="submitPayment">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.record.amount', { currency }) }}</span>
                            <input v-model="form.amount" type="number" step="0.01" min="0" required inputmode="decimal" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink tabular-nums focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                            <span v-if="form.errors.amount_minor" class="text-xs text-danger">{{ form.errors.amount_minor }}</span>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.record.method') }}</span>
                            <select v-model="form.method" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200">
                                <option v-for="m in payment.methods" :key="m" :value="m">{{ methodLabel(m) }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.record.received') }}</span>
                            <input v-model="form.received_on" type="date" required class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.record.reference') }}</span>
                            <input v-model="form.reference" type="text" maxlength="255" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                        </label>
                    </div>

                    <div v-if="lines.length" class="rounded-2xl border border-line bg-white/50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.record.allocateTo') }}</p>
                        <div v-for="line in lines" :key="line.invoice_id" class="mt-3 flex flex-wrap items-center gap-3">
                            <label class="flex flex-1 items-center gap-2 text-sm text-ink">
                                <input v-model="line.apply" type="checkbox" class="h-4 w-4 rounded border-line text-euca-600 focus:ring-euca-200" />
                                <span class="font-mono text-xs">{{ line.number ?? '—' }}</span>
                                <span class="text-ink-muted">{{ t('billing.accountDetail.record.openBalance', { amount: money(line.open_balance_minor) }) }}</span>
                            </label>
                            <input v-model="line.amount" type="number" step="0.01" min="0" :disabled="!line.apply" :aria-label="t('billing.accountDetail.record.allocationAmount', { invoice: line.number ?? '—' })" class="w-36 rounded-xl border border-line bg-white/70 px-3 py-2 text-right text-sm text-ink tabular-nums focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200 disabled:opacity-50" />
                        </div>
                    </div>
                    <p v-else class="text-sm text-ink-muted">{{ t('billing.accountDetail.record.noOpenInvoices') }}</p>

                    <p class="text-xs text-ink-subtle">{{ t('billing.accountDetail.record.guardHint') }}</p>

                    <div class="flex justify-end">
                        <button type="submit" class="btn-glow" :disabled="form.processing">{{ t('billing.accountDetail.record.confirm') }}</button>
                    </div>
                </form>
            </div>

            <!-- Payment plan — the schedule is ENGINE-computed (an exact partition of a total that
                 can never exceed the account's real outstanding). The page displays it and posts
                 operator input; it splits nothing and moves no money. Settling an installment goes
                 through the same guarded PaymentService path as record-payment. -->
            <div v-if="plan.can_manage || plan.current" class="glass-card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.plan.title') }}</h2>
                        <span class="text-xs text-ink-subtle">{{ t('billing.accountDetail.plan.subtitle') }}</span>
                    </div>
                    <button v-if="plan.can_manage && !planStatusIsOpen" type="button" class="btn-glow" @click="showPlanForm = !showPlanForm">
                        {{ showPlanForm ? t('billing.actions.cancel') : t('billing.accountDetail.plan.open') }}
                    </button>
                </div>

                <p v-if="planForm.errors.payment_plan || installmentForm.errors.payment_plan || cancelForm.errors.payment_plan" class="mt-3 rounded-xl bg-danger/10 px-3 py-2 text-sm text-danger">
                    {{ planForm.errors.payment_plan || installmentForm.errors.payment_plan || cancelForm.errors.payment_plan }}
                </p>

                <!-- Create -->
                <form v-if="showPlanForm && !planStatusIsOpen" class="mt-4 space-y-4" @submit.prevent="submitPlan">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.plan.amount', { currency }) }}</span>
                            <input v-model="planForm.amount" type="number" step="0.01" min="0" required inputmode="decimal" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink tabular-nums focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                            <span v-if="planForm.errors.total_minor" class="text-xs text-danger">{{ planForm.errors.total_minor }}</span>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.plan.installments') }}</span>
                            <input v-model="planForm.installment_count" type="number" step="1" min="1" max="60" required class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink tabular-nums focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                            <span v-if="planForm.errors.installment_count" class="text-xs text-danger">{{ planForm.errors.installment_count }}</span>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.plan.startDate') }}</span>
                            <input v-model="planForm.start_date" type="date" required class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                        </label>
                    </div>
                    <p class="text-xs text-ink-subtle">{{ t('billing.accountDetail.plan.tieHint', { outstanding: money(ledger.account_outstanding_minor) }) }}</p>
                    <div class="flex justify-end">
                        <button type="submit" class="btn-glow" :disabled="planForm.processing">{{ t('billing.accountDetail.plan.confirm') }}</button>
                    </div>
                </form>

                <!-- The plan + its schedule -->
                <div v-if="plan.current" class="mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="planStatusIsOpen ? 'bg-euca-50 text-euca-800' : 'bg-surface-2 text-ink-muted'"
                        >
                            <span class="h-2 w-2 rounded-full" :class="planStatusIsOpen ? 'bg-euca-500' : 'bg-ink-subtle'"></span>
                            {{ planStatusLabel(plan.current.status) }}
                        </span>
                        <span v-if="plan.current.ties" class="rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">
                            {{ t('billing.accountDetail.plan.tiesOk', { total: money(plan.current.total_minor) }) }}
                        </span>
                        <span v-else class="rounded-full bg-warning/15 px-2.5 py-0.5 text-xs font-semibold text-warning">{{ t('billing.accountDetail.plan.tiesOff') }}</span>
                    </div>

                    <div class="mt-3 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl bg-surface-2 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.plan.total') }}</p>
                            <p class="mt-1 text-lg font-semibold text-ink tabular-nums">{{ money(plan.current.total_minor) }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-2 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.plan.paid') }}</p>
                            <p class="mt-1 text-lg font-semibold text-euca-700 tabular-nums">{{ money(plan.current.paid_minor) }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-2 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.plan.remaining') }}</p>
                            <p class="mt-1 text-lg font-semibold text-ink tabular-nums">{{ money(plan.current.remaining_minor) }}</p>
                        </div>
                    </div>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                                <tr class="border-b border-line">
                                    <th class="px-2 py-2 font-semibold">{{ t('billing.accountDetail.plan.col.number') }}</th>
                                    <th class="px-2 py-2 font-semibold">{{ t('billing.accountDetail.plan.col.due') }}</th>
                                    <th class="px-2 py-2 text-right font-semibold">{{ t('billing.accountDetail.plan.col.amount') }}</th>
                                    <th class="px-2 py-2 font-semibold">{{ t('billing.accountDetail.plan.col.status') }}</th>
                                    <th v-if="plan.can_manage && planStatusIsOpen" class="px-2 py-2 text-right font-semibold">{{ t('billing.accountDetail.plan.col.action') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line/70">
                                <tr v-for="i in plan.current.installments" :key="i.id">
                                    <td class="px-2 py-2.5 tabular-nums text-ink-muted">{{ i.sequence }}</td>
                                    <td class="px-2 py-2.5 tabular-nums text-ink-muted">{{ i.due_date }}</td>
                                    <td class="px-2 py-2.5 text-right tabular-nums text-ink">{{ money(i.amount_minor) }}</td>
                                    <td class="px-2 py-2.5">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-2 w-2 rounded-full" :class="i.status === 'paid' ? 'bg-euca-500' : i.overdue ? 'bg-warning' : 'bg-ink-subtle'"></span>
                                            <span class="text-ink">{{ i.status === 'paid' ? t('billing.accountDetail.plan.paidOn', { date: i.paid_on }) : i.overdue ? t('billing.accountDetail.plan.overdue') : t('billing.accountDetail.plan.pending') }}</span>
                                        </span>
                                    </td>
                                    <td v-if="plan.can_manage && planStatusIsOpen" class="px-2 py-2.5 text-right">
                                        <button
                                            v-if="i.status !== 'paid'"
                                            type="button"
                                            class="rounded-xl border border-line px-3 py-1.5 text-xs font-semibold text-ink transition hover:bg-surface-2 disabled:opacity-50"
                                            :disabled="installmentForm.processing && payingId === i.id"
                                            @click="payInstallment(i)"
                                        >{{ t('billing.accountDetail.plan.markPaid') }}</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="plan.current.closed_reason" class="mt-3 text-xs text-ink-muted">{{ t('billing.accountDetail.plan.closedReason', { reason: plan.current.closed_reason }) }}</p>

                    <form v-if="plan.can_manage && planStatusIsOpen" class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="cancelPlan">
                        <label class="min-w-[16rem] flex-1">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.plan.cancelReason') }}</span>
                            <input v-model="cancelForm.reason" type="text" maxlength="500" required class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                            <span v-if="cancelForm.errors.reason" class="text-xs text-danger">{{ cancelForm.errors.reason }}</span>
                        </label>
                        <button type="submit" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink-muted transition hover:bg-surface-2" :disabled="cancelForm.processing">{{ t('billing.accountDetail.plan.cancelPlan') }}</button>
                    </form>
                </div>
                <p v-else-if="!showPlanForm" class="mt-3 text-sm text-ink-muted">{{ t('billing.accountDetail.plan.empty') }}</p>
            </div>

            <!-- Betreibung / debt enforcement — a LEGAL proceeding. Operator-only (the dedicated
                 billing.escalate), explicit confirmation + reason, allowed only once the real dunning
                 machine is exhausted, append-only + audited. The agent has NO path here: the copy
                 below states exactly what the code enforces. -->
            <div v-if="enforcement.can_escalate || enforcement.current" class="glass-card border border-warning/30 p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.enforcement.title') }}</h2>
                        <span class="text-xs text-ink-subtle">{{ t('billing.accountDetail.enforcement.subtitle') }}</span>
                    </div>
                    <span
                        v-if="enforcement.current"
                        class="inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-2.5 py-0.5 text-xs font-semibold text-warning"
                    >
                        <span class="h-2 w-2 rounded-full bg-warning"></span>
                        {{ t('billing.accountDetail.enforcement.inEnforcement') }}
                    </span>
                </div>

                <p v-if="enforceForm.errors.enforcement || withdrawForm.errors.enforcement" class="mt-3 rounded-xl bg-danger/10 px-3 py-2 text-sm text-danger">
                    {{ enforceForm.errors.enforcement || withdrawForm.errors.enforcement }}
                </p>

                <!-- The governance statement — true of the code, not decoration. -->
                <p class="mt-3 rounded-xl bg-surface-2 px-3 py-2 text-xs text-ink-muted">{{ t('billing.accountDetail.enforcement.governance') }}</p>

                <!-- The live escalation -->
                <div v-if="enforcement.current" class="mt-4 rounded-2xl border border-line bg-white/50 p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="text-sm font-semibold text-ink">{{ t('billing.accountDetail.enforcement.initiatedBy', { who: enforcement.current.initiated_by ?? '—', date: enforcement.current.initiated_on }) }}</span>
                        <span class="text-xs tabular-nums text-ink-muted">{{ t('billing.accountDetail.enforcement.atStage', { stage: enforcement.current.dunning_stage, amount: money(enforcement.current.outstanding_minor) }) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-ink-muted">{{ enforcement.current.reason }}</p>
                    <p v-if="enforcement.current.reference" class="mt-1 font-mono text-xs text-ink-subtle">{{ enforcement.current.reference }}</p>

                    <form v-if="enforcement.can_escalate && enforcement.withdraw_url" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="submitWithdrawal">
                        <label class="min-w-[16rem] flex-1">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.enforcement.withdrawReason') }}</span>
                            <input v-model="withdrawForm.reason" type="text" maxlength="1000" required class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                            <span v-if="withdrawForm.errors.reason" class="text-xs text-danger">{{ withdrawForm.errors.reason }}</span>
                        </label>
                        <button type="submit" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink-muted transition hover:bg-surface-2" :disabled="withdrawForm.processing">{{ t('billing.accountDetail.enforcement.withdraw') }}</button>
                    </form>
                </div>

                <!-- Eligibility + the operator-confirmed action -->
                <div v-else class="mt-4">
                    <p class="text-sm" :class="enforcement.eligibility.eligible ? 'text-ink' : 'text-ink-muted'">
                        {{ eligibilityLabel(enforcement.eligibility) }}
                        <span v-if="enforcement.eligibility.terminal_stage !== null" class="text-xs text-ink-subtle">
                            ({{ t('billing.accountDetail.enforcement.stageEvidence', { reached: enforcement.eligibility.reached_stage, terminal: enforcement.eligibility.terminal_stage }) }})
                        </span>
                    </p>

                    <button
                        v-if="enforcement.can_escalate && enforcement.eligibility.eligible && !showEnforceForm"
                        type="button"
                        class="mt-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-2 text-sm font-semibold text-warning transition hover:bg-warning/20"
                        @click="showEnforceForm = true"
                    >{{ t('billing.accountDetail.enforcement.open') }}</button>

                    <form v-if="showEnforceForm" class="mt-4 space-y-4" @submit.prevent="submitEnforcement">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.enforcement.reason') }}</span>
                            <textarea v-model="enforceForm.reason" rows="2" maxlength="1000" required class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200"></textarea>
                            <span v-if="enforceForm.errors.reason" class="text-xs text-danger">{{ enforceForm.errors.reason }}</span>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.accountDetail.enforcement.reference') }}</span>
                            <input v-model="enforceForm.reference" type="text" maxlength="255" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                        </label>
                        <label class="flex items-start gap-2 rounded-xl border border-warning/30 bg-warning/5 p-3 text-sm text-ink">
                            <input v-model="enforceForm.confirmed" type="checkbox" required class="mt-0.5 h-4 w-4 rounded border-line text-euca-600 focus:ring-euca-200" />
                            <span>{{ t('billing.accountDetail.enforcement.confirm') }}</span>
                        </label>
                        <span v-if="enforceForm.errors.confirmed" class="text-xs text-danger">{{ enforceForm.errors.confirmed }}</span>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink-muted transition hover:bg-surface-2" @click="showEnforceForm = false">{{ t('billing.actions.cancel') }}</button>
                            <button type="submit" class="rounded-xl border border-warning/40 bg-warning/10 px-4 py-2 text-sm font-semibold text-warning transition hover:bg-warning/20" :disabled="enforceForm.processing || !enforceForm.confirmed">{{ t('billing.accountDetail.enforcement.submit') }}</button>
                        </div>
                    </form>
                </div>

                <!-- Full provenance: every act, append-only -->
                <ul v-if="enforcement.history.length > 1" class="mt-4 space-y-1 border-t border-line pt-3">
                    <li v-for="h in enforcement.history" :key="h.id" class="flex flex-wrap items-baseline justify-between gap-2 text-xs text-ink-muted">
                        <span>{{ t(`billing.accountDetail.enforcement.actions.${h.action}`) }} · {{ h.initiated_by ?? '—' }} · {{ h.initiated_on }}</span>
                        <span class="text-ink-subtle">{{ h.reason }}</span>
                    </li>
                </ul>
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
