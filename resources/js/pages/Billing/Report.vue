<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

/*
 * BILLAR.P6 — the Billing & AR management-report grid. This page is PURELY
 * presentational over the P1–P5 MetricsService figures: every money number below is a
 * server (engine) value that the view only FORMATS. Nothing here sums, ratios, buckets,
 * or re-aggregates money. The period switcher re-parameterizes the engine (a server
 * round-trip); it never re-slices data client-side. The only client arithmetic is CSS
 * bar widths — a visual proportion of two already-computed server figures, exactly like
 * the existing Aging page — and null ratios render as "—".
 */

const { t, te } = useI18n();

type Aging = { current: number; days_1_30: number; days_31_60: number; days_61_90: number; days_90_plus: number };
type RollForward = {
    opening_minor: number;
    charges_minor: number;
    collections_minor: number;
    adjustments_minor: number;
    write_offs_minor: number;
    closing_minor: number;
    discrepancy_minor: number;
    ties: boolean;
};
type Dso = { ar_minor: number; credit_sales_minor: number; days: number; dso: number | null };
type Rate = { collections_minor: number; charges_minor: number; contractual_adjustments_minor: number; collectible_minor: number; rate: number | null };
type PayerGroup = { payer_type: string; ar_minor: number; collections_minor: number; charges_minor: number };
type ByPayer = { groups: PayerGroup[]; total_ar_minor: number; total_collections_minor: number; total_charges_minor: number; ties: boolean };
type TrendBucket = { from: string; to: string; label: string; charges_minor: number; collections_minor: number };
type Trend = { bucket: string; buckets: TrendBucket[]; total_charges_minor: number; total_collections_minor: number; partitions: boolean };
type Report = {
    period: { from: string; to: string; bucket: string };
    total_ar_minor: number;
    overdue_minor: number;
    aging: Aging;
    invoiced_minor: number;
    collected_minor: number;
    roll_forward: RollForward;
    dso: Dso;
    collection_rate: Rate;
    by_payer: ByPayer;
    trend: Trend;
};
type OverdueAccount = {
    patient_id: string;
    patient_name: string | null;
    invoice_count: number;
    total_overdue_minor: number;
    max_days_overdue: number;
    max_stage: number;
    oldest_due_date: string;
    ties: boolean;
    detail_url: string;
};
type TopOverdue = {
    accounts: OverdueAccount[];
    account_count: number;
    shown: number;
    grand_total_overdue_minor: number;
    ties: boolean;
};

const props = defineProps<{
    period: string;
    periods: string[];
    compareOn: boolean;
    currency: string;
    report: Report;
    compare: Report | null;
    topOverdue: TopOverdue;
    links: { self: string; aging: string; dunning: string; reporting: string; invoices: string; export: string };
}>();

// Money is integer minor units from the tested service; the view only formats (÷100).
function money(minor: number): string {
    return `${(minor / 100).toFixed(2)} ${props.currency}`;
}
// A service-returned ratio (0..1) rendered as a percentage is pure formatting; null → "—".
function percent(rate: number | null): string {
    return rate === null ? '—' : `${(rate * 100).toFixed(1)}%`;
}
function dsoLabel(value: number | null): string {
    return value === null ? '—' : String(value);
}
// The dunning stage is the engine's real max level; this only LABELS it (0 = no reminder).
function stageLabel(stage: number): string {
    return stage <= 0 ? t('billing.report.topOverdue.stageNone') : t('billing.report.topOverdue.stageLevel', { n: stage });
}
// CSS width only — a visual proportion of two server figures, never a displayed figure.
function widthPct(part: number, whole: number): string {
    return `${whole > 0 ? Math.min((part / whole) * 100, 100) : 0}%`;
}
function payerLabel(type: string): string {
    const key = `billing.report.payerTypes.${type}`;
    return te(key) ? t(key) : type;
}

const agingKeys: (keyof Aging)[] = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

// Roll-forward lines in bridge order (opening → closing); signs are labels, not math.
const rollLines = computed(() => {
    const r = props.report.roll_forward;
    return [
        { key: 'opening', amount: r.opening_minor, sign: '' },
        { key: 'charges', amount: r.charges_minor, sign: '+' },
        { key: 'collections', amount: r.collections_minor, sign: '−' },
        { key: 'adjustments', amount: r.adjustments_minor, sign: '−' },
        { key: 'writeOffs', amount: r.write_offs_minor, sign: '−' },
    ];
});

// Trend chart scale — Math.max for bar HEIGHTS only (visual), never a shown number.
const trendMax = computed(() => {
    const vals = props.report.trend.buckets.flatMap((b) => [b.charges_minor, b.collections_minor]);
    return Math.max(1, ...vals);
});

function switchPeriod(next: string): void {
    if (next === props.period) return;
    router.get(props.links.self, { period: next, compare: props.compareOn ? 1 : 0 }, { preserveScroll: true, preserveState: false });
}
function toggleCompare(): void {
    router.get(props.links.self, { period: props.period, compare: props.compareOn ? 0 : 1 }, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.report.title')" />
        <div class="space-y-5">
            <!-- Header band: period switcher + compare + export -->
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 lg:flex-row lg:items-end">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.report.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('billing.report.title') }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ t('billing.report.rangeLabel', { from: report.period.from, to: report.period.to }) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-1 rounded-full bg-white/10 p-1">
                        <button
                            v-for="p in periods"
                            :key="p"
                            type="button"
                            class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                            :class="p === period ? 'bg-white/90 text-euca-900' : 'text-euca-100 hover:bg-white/15'"
                            @click="switchPeriod(p)"
                        >
                            {{ t(`billing.report.periods.${p}`) }}
                        </button>
                    </div>
                    <button
                        type="button"
                        class="rounded-xl px-3 py-1.5 text-xs font-semibold transition"
                        :class="compareOn ? 'bg-white/90 text-euca-900' : 'bg-white/10 text-euca-100 hover:bg-white/20'"
                        @click="toggleCompare"
                    >
                        {{ t('billing.report.compare') }}
                    </button>
                    <a :href="links.export" class="rounded-xl bg-white/15 px-3 py-1.5 text-xs font-semibold text-euca-50 transition hover:bg-white/25">{{ t('billing.report.exportCsv') }}</a>
                </div>
            </div>

            <!-- Headline band — engine figures -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="euca-tile-dark p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-euca-200">{{ t('billing.report.headline.totalAr') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-euca-50">{{ money(report.total_ar_minor) }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.headline.current') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(report.aging.current) }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.headline.overdue') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(report.overdue_minor) }}</p>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-surface-2">
                        <div class="h-full rounded-full bg-warning" :style="{ width: widthPct(report.overdue_minor, report.total_ar_minor) }"></div>
                    </div>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.headline.inCollection') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(report.aging.days_90_plus) }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.headline.dso') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink tabular-nums">{{ dsoLabel(report.dso.dso) }}</p>
                    <p class="text-xs text-ink-muted">{{ t('billing.report.cards.dsoDays', { days: report.dso.days }) }}</p>
                </div>
            </div>

            <!-- Compare band — two periods fetched from the engine, both displayed (no computed delta) -->
            <div v-if="compare" class="glass-card p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.comparePrev', { from: compare.period.from, to: compare.period.to }) }}</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-4">
                    <div>
                        <p class="text-xs text-ink-subtle">{{ t('billing.report.rollForward.charges') }}</p>
                        <p class="text-sm font-semibold text-ink tabular-nums">{{ money(report.roll_forward.charges_minor) }}</p>
                        <p class="text-xs text-ink-muted tabular-nums">{{ money(compare.roll_forward.charges_minor) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-subtle">{{ t('billing.report.rollForward.collections') }}</p>
                        <p class="text-sm font-semibold text-ink tabular-nums">{{ money(report.roll_forward.collections_minor) }}</p>
                        <p class="text-xs text-ink-muted tabular-nums">{{ money(compare.roll_forward.collections_minor) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-subtle">{{ t('billing.report.health.netRate') }}</p>
                        <p class="text-sm font-semibold text-ink tabular-nums">{{ percent(report.collection_rate.rate) }}</p>
                        <p class="text-xs text-ink-muted tabular-nums">{{ percent(compare.collection_rate.rate) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-subtle">{{ t('billing.report.headline.dso') }}</p>
                        <p class="text-sm font-semibold text-ink tabular-nums">{{ dsoLabel(report.dso.dso) }}</p>
                        <p class="text-xs text-ink-muted tabular-nums">{{ dsoLabel(compare.dso.dso) }}</p>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.cards.collected') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(report.collection_rate.collections_minor) }}</p>
                    <p class="mt-1 text-xs text-ink-muted">{{ t('billing.report.cards.collectible', { amount: money(report.collection_rate.collectible_minor) }) }}</p>
                    <!--
                        STATE THE BASIS (QA-FIX.3b, P3-H1). This is the APPLIED basis and the aging
                        page's figure is the RECEIVED one; both were labelled "Collected". The
                        wording tracks MetricsService::netCollectionsMinor() — "net payment
                        allocations applied in a period (reversals net out) — the collections that
                        reduce AR".
                    -->
                    <p class="mt-1 text-xs text-ink-muted">{{ t('billing.report.cards.collectedBasis') }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.cards.netRate') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink tabular-nums">{{ percent(report.collection_rate.rate) }}</p>
                    <p class="mt-1 text-xs text-ink-muted">{{ t('billing.report.cards.chargesLess', { charges: money(report.collection_rate.charges_minor), adj: money(report.collection_rate.contractual_adjustments_minor) }) }}</p>
                </div>
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.cards.periodInvoiced') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ money(report.invoiced_minor) }}</p>
                    <!--
                        The RECEIVED basis, shown here beside the period's invoicing. Same engine
                        method the aging page uses (paymentsReceivedTotalMinor), so the two agree —
                        and it is deliberately NOT the "Collected (period)" card above, which is the
                        applied basis (QA-FIX.3b, P3-H1).
                    -->
                    <p class="mt-1 text-xs text-ink-muted">{{ t('billing.report.cards.periodCollected', { amount: money(report.collected_minor) }) }}</p>
                    <p class="mt-1 text-xs text-ink-muted">{{ t('billing.report.cards.periodCollectedBasis') }}</p>
                </div>
            </div>

            <!-- AR roll-forward + Aging -->
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="glass-card p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.rollForward.title') }}</h2>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="report.roll_forward.ties ? 'bg-euca-50 text-euca-800' : 'bg-warning/15 text-warning'"
                        >{{ report.roll_forward.ties ? t('billing.report.rollForward.tiesOk') : t('billing.report.rollForward.tiesOff', { delta: money(report.roll_forward.discrepancy_minor) }) }}</span>
                    </div>
                    <table class="mt-3 w-full text-left text-sm">
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="line in rollLines" :key="line.key">
                                <td class="py-2 text-ink">
                                    <span class="mr-1 inline-block w-3 text-ink-subtle">{{ line.sign }}</span>{{ t(`billing.report.rollForward.${line.key}`) }}
                                </td>
                                <td class="py-2 text-right tabular-nums text-ink">{{ money(line.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-line font-semibold text-ink">
                                <td class="py-2.5">{{ t('billing.report.rollForward.closing') }}</td>
                                <td class="py-2.5 text-right tabular-nums">{{ money(report.roll_forward.closing_minor) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="glass-card p-5">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.aging.title') }}</h2>
                    <table class="mt-3 w-full text-left text-sm">
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="key in agingKeys" :key="key">
                                <td class="py-2">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="h-2 w-2 rounded-full" :class="key === 'current' ? 'bg-euca-500' : 'bg-warning'"></span>
                                        <span class="text-ink">{{ t(`billing.aging.buckets.${key}`) }}</span>
                                    </span>
                                </td>
                                <td class="py-2">
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-surface-2">
                                        <div class="h-full rounded-full" :class="key === 'current' ? 'bg-euca-500' : 'bg-warning'" :style="{ width: widthPct(report.aging[key], report.total_ar_minor) }"></div>
                                    </div>
                                </td>
                                <td class="py-2 text-right tabular-nums text-ink">{{ money(report.aging[key]) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-line font-semibold text-ink">
                                <td class="py-2.5">{{ t('billing.aging.total') }}</td>
                                <td class="py-2.5"></td>
                                <td class="py-2.5 text-right tabular-nums">{{ money(report.total_ar_minor) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- By-payer split -->
            <div class="glass-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.byPayer.title') }}</h2>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="report.by_payer.ties ? 'bg-euca-50 text-euca-800' : 'bg-warning/15 text-warning'"
                    >{{ report.by_payer.ties ? t('billing.report.byPayer.tiesOk') : t('billing.report.byPayer.tiesOff') }}</span>
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="px-2 py-2 font-semibold">{{ t('billing.report.byPayer.payer') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.report.byPayer.ar') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.report.byPayer.collections') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.report.byPayer.charges') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="g in report.by_payer.groups" :key="g.payer_type">
                                <td class="px-2 py-2.5 text-ink">{{ payerLabel(g.payer_type) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-ink">{{ money(g.ar_minor) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-ink">{{ money(g.collections_minor) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-ink">{{ money(g.charges_minor) }}</td>
                            </tr>
                            <tr v-if="report.by_payer.groups.length === 0">
                                <td colspan="4" class="px-2 py-4 text-center text-ink-muted">{{ t('billing.report.byPayer.empty') }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-line font-semibold text-ink">
                                <td class="px-2 py-2.5">{{ t('billing.report.byPayer.total') }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums">{{ money(report.by_payer.total_ar_minor) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums">{{ money(report.by_payer.total_collections_minor) }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums">{{ money(report.by_payer.total_charges_minor) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Charged-vs-collected trend -->
            <div class="glass-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.trend.title') }}</h2>
                    <div class="flex items-center gap-3 text-xs text-ink-muted">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-euca-500"></span>{{ t('billing.report.trend.charges') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-euca-300"></span>{{ t('billing.report.trend.collections') }}</span>
                    </div>
                </div>
                <div v-if="report.trend.buckets.length" class="mt-4 flex items-end gap-3 overflow-x-auto pb-2">
                    <div v-for="b in report.trend.buckets" :key="b.label" class="flex min-w-[3rem] flex-1 flex-col items-center gap-2">
                        <div class="flex h-32 w-full items-end justify-center gap-1">
                            <div class="w-1/2 rounded-t bg-euca-500" :style="{ height: widthPct(b.charges_minor, trendMax) }" :title="money(b.charges_minor)"></div>
                            <div class="w-1/2 rounded-t bg-euca-300" :style="{ height: widthPct(b.collections_minor, trendMax) }" :title="money(b.collections_minor)"></div>
                        </div>
                        <span class="text-[0.65rem] text-ink-subtle">{{ b.label }}</span>
                    </div>
                </div>
                <p v-else class="mt-3 text-sm text-ink-muted">{{ t('billing.report.trend.empty') }}</p>
                <p class="mt-2 text-xs text-ink-subtle">
                    {{ t('billing.report.trend.totals', { charges: money(report.trend.total_charges_minor), collections: money(report.trend.total_collections_minor) }) }}
                </p>
            </div>

            <!-- Top-overdue accounts — engine ages/balances + the REAL dunning stage; each row
                 drills to the account's AR detail. The Vue computes no money; ordering + the
                 rollup are the engine's. -->
            <div class="glass-card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.report.topOverdue.title') }}</h2>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="topOverdue.ties ? 'bg-euca-50 text-euca-800' : 'bg-warning/15 text-warning'"
                    >{{ topOverdue.ties ? t('billing.report.topOverdue.tiesOk', { total: money(topOverdue.grand_total_overdue_minor) }) : t('billing.report.topOverdue.tiesOff') }}</span>
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="px-2 py-2 font-semibold">{{ t('billing.report.topOverdue.account') }}</th>
                                <th class="px-2 py-2 font-semibold">{{ t('billing.report.topOverdue.invoices') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.report.topOverdue.age') }}</th>
                                <th class="px-2 py-2 font-semibold">{{ t('billing.report.topOverdue.stage') }}</th>
                                <th class="px-2 py-2 text-right font-semibold">{{ t('billing.report.topOverdue.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="a in topOverdue.accounts" :key="a.patient_id" class="cursor-pointer transition hover:bg-euca-50/50" @click="router.visit(a.detail_url)">
                                <td class="px-2 py-2.5">
                                    <Link :href="a.detail_url" class="font-medium text-ink hover:text-euca-700" @click.stop>{{ a.patient_name ?? t('billing.report.topOverdue.unknownAccount') }}</Link>
                                </td>
                                <td class="px-2 py-2.5 tabular-nums text-ink-muted">{{ a.invoice_count }}</td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-ink">{{ t('billing.report.topOverdue.days', { n: a.max_days_overdue }) }}</td>
                                <td class="px-2 py-2.5">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full" :class="a.max_stage > 0 ? 'bg-warning' : 'bg-line'"></span>
                                        <span class="text-ink">{{ stageLabel(a.max_stage) }}</span>
                                    </span>
                                </td>
                                <td class="px-2 py-2.5 text-right tabular-nums text-ink">{{ money(a.total_overdue_minor) }}</td>
                            </tr>
                            <tr v-if="topOverdue.accounts.length === 0">
                                <td colspan="5" class="px-2 py-4 text-center text-ink-muted">{{ t('billing.report.topOverdue.empty') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="topOverdue.account_count > topOverdue.shown" class="mt-2 text-xs text-ink-subtle">
                    {{ t('billing.report.topOverdue.truncated', { shown: topOverdue.shown, total: topOverdue.account_count }) }}
                </p>
            </div>

            <!-- Drill-downs into the live surfaces this grid consolidates -->
            <div class="flex flex-wrap gap-2">
                <Link :href="links.aging" class="rounded-xl bg-surface-2 px-3 py-1.5 text-xs font-semibold text-ink-muted transition hover:text-ink">{{ t('billing.report.links.aging') }}</Link>
                <Link :href="links.dunning" class="rounded-xl bg-surface-2 px-3 py-1.5 text-xs font-semibold text-ink-muted transition hover:text-ink">{{ t('billing.report.links.dunning') }}</Link>
                <Link :href="links.invoices" class="rounded-xl bg-surface-2 px-3 py-1.5 text-xs font-semibold text-ink-muted transition hover:text-ink">{{ t('billing.report.links.invoices') }}</Link>
                <Link :href="links.reporting" class="rounded-xl bg-surface-2 px-3 py-1.5 text-xs font-semibold text-ink-muted transition hover:text-ink">{{ t('billing.report.links.reporting') }}</Link>
            </div>

            <p class="px-1 text-xs text-ink-subtle">{{ t('billing.report.footnote') }}</p>
        </div>
    </AppLayout>
</template>
