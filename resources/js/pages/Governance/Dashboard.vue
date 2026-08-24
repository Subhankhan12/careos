<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';

const { t } = useI18n();
const page = usePage();

const props = defineProps<{
    chain: { ok: boolean; count: number | null; brokenAt: string | null; reason: string | null; lastCheckedAt: string | null };
    reconciliation: { period: string | null; passed: boolean | null; ranAt: string | null; alarm: Record<string, unknown> | null };
    ai: { windowDays: number; total: number; byOutcome: Record<string, number>; costMinor: number; currency: string };
    queue: { pending: number; url: string };
    kill: { disabledFeatures: string[] };
    activity: Array<{ id: string; occurredAt: string; action: string; actorType: string; resourceType: string | null }>;
    security: Array<{ id: string; occurredAt: string; action: string; actorType: string; resourceType: string | null }>;
    verifyUrl: string;
    /*
     * GOV.P1 — the windowed governance metrics (G1), computed SERVER-side. Every figure is a real
     * count of real records or an honest null. There is deliberately no confidence score, no
     * breach counter, no KB-gap ranking and no trend verdict here: the records that would source
     * them do not exist, and a governance screen inventing one would be the exact failure this
     * screen is meant to detect (GOVERNANCE-AI-BATCH-DIFF.md §4.4).
     */
    metrics: {
        from: string;
        to: string;
        byStatus: Record<string, number>;
        byAgent: Array<{ key: string; name: string; total: number; byStatus: Record<string, number>; approvedAsIsPct: number | null }>;
        /** REGISTERED tools only — the ten governed tools are the whole set (D-170). */
        byTool: Array<{ key: string; name: string; category: string; ceiling: string; total: number }>;
        unregisteredTools: number;
        ledgerTotal: number;
        ledgerByOutcome: Record<string, number>;
        fenceRefused: number;
        pendingNow: number;
    };
    windowLedger: Array<{ id: string; agentLabel: string; tool: string; outcome: string; reason: string | null; occurredAt: string | null; system: boolean }>;
    /*
     * GOV.P2 — the REAL "waiting on a person" states. Each category is a queryable state with a
     * real setter and a real clearer; a category this viewer may not see comes back visible:false
     * with a zero, never another category's data. No urgency, no SLA, no priority — items are
     * ordered oldest-first, which is a date sort over a recorded timestamp, not a ranking (D-169).
     */
    needsHuman: {
        categories: Array<{
            key: string;
            visible: boolean;
            count: number;
            actionUrl: string;
            items: Array<{ id: string; waitingSince: string | null; agent?: string; tool?: string; subject?: string | null; reason?: string | null }>;
        }>;
        total: number;
        /** Real states that cannot be produced today — stated, not hidden. */
        unproducible: string[];
        /** Real human-blocking work that lives on another screen, named so an empty panel is not read as a global all-clear. */
        elsewhere: string[];
    };
    range: string;
    ranges: string[];
    fenceInvariants: string[];
    agentsUrl: string;
    dashboardUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// Facts only: display the stored integer-minor cost as a currency amount; the view formats,
// it never computes a figure of its own.
const aiCost = computed(() => `${props.ai.currency} ${(props.ai.costMinor / 100).toFixed(2)}`);
const outcomes = computed(() => Object.entries(props.ai.byOutcome));

// These are full timestamps (not date-only values), so plain locale formatting is correct —
// the date-only local-midnight concern (D-091) does not apply here.
function dateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

function verifyNow(): void {
    router.post(props.verifyUrl, {}, { preserveScroll: true });
}

/*
 * The range picker RE-REQUESTS the page: the server recomputes the window from the real records.
 * It is not a client-side filter over what was already loaded — that could only ever narrow the
 * fetched rows, and would disagree with the database the moment a window exceeded the page size.
 */
function selectRange(range: string): void {
    router.get(props.dashboardUrl, { range }, { preserveScroll: true, preserveState: false });
}

// The REAL agent-action statuses. Nothing else may appear: "escalated" is not one of them (the
// real hand-off is clinician_attention on a thread), and inventing it would put a fifth slice on
// a chart that the database cannot produce.
const STATUSES = ['pending', 'executed', 'rejected', 'fence_refused'] as const;

const windowTotal = computed(() => Object.values(props.metrics.byStatus).reduce((sum, n) => sum + n, 0));
const activeAgents = computed(() => props.metrics.byAgent.filter((a) => a.total > 0));

/** A recorded percentage, or the honest em-dash when nothing has resolved (the AGENT.P5 rule). */
function pct(value: number | null): string {
    return value === null ? '—' : `${value}%`;
}
</script>

<template>
    <AppLayout>
        <Head :title="t('governance.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('governance.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('governance.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('governance.subtitle') }}</p>
            </div>

            <p
                v-if="flash === 'chain_ok' || flash === 'chain_broken'"
                class="rounded-2xl border p-4 text-sm"
                :class="flash === 'chain_ok' ? 'border-success/30 bg-success-soft text-success' : 'border-danger/30 bg-danger-soft text-danger'"
            >
                {{ t(`governance.flash.${flash}`) }}
            </p>

            <!-- Headline posture tiles. -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard :label="t('governance.chain.title')" :value="chain.ok ? t('governance.chain.valid') : t('governance.chain.broken')" :hint="chain.count !== null ? t('governance.chain.count', { count: chain.count }) : undefined" />
                <StatCard
                    :label="t('governance.reconcile.title')"
                    :value="reconciliation.passed === null ? t('governance.reconcile.never') : reconciliation.passed ? t('governance.reconcile.passes') : t('governance.reconcile.fails')"
                    :hint="reconciliation.period ?? undefined"
                />
                <StatCard :label="t('governance.queue.title')" :value="String(queue.pending)" :hint="t('governance.queue.pendingHint')" />
                <StatCard :label="t('governance.ai.title')" :value="String(ai.total)" :hint="t('governance.ai.window', { days: ai.windowDays })" />
            </div>

            <!-- Audit-chain integrity: shows the existing verification; 'verify now' RE-RUNS it (writes nothing). -->
            <Card :title="t('governance.chain.cardTitle')" :subtitle="t('governance.chain.cardSubtitle')">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="space-y-1 text-sm">
                        <p class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="chain.ok ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
                            >
                                {{ chain.ok ? t('governance.chain.valid') : t('governance.chain.broken') }}
                            </span>
                            <span class="text-ink-muted">{{ chain.count !== null ? t('governance.chain.count', { count: chain.count }) : '' }}</span>
                        </p>
                        <p v-if="!chain.ok && chain.reason" class="text-danger">{{ chain.reason }} <span v-if="chain.brokenAt" class="font-mono text-xs">({{ chain.brokenAt }})</span></p>
                        <p class="text-ink-subtle">{{ t('governance.chain.lastChecked') }}: {{ chain.lastCheckedAt ? dateTime(chain.lastCheckedAt) : t('governance.chain.never') }}</p>
                    </div>
                    <button type="button" class="btn-glow rounded-xl px-4 py-2 text-sm font-semibold" @click="verifyNow">{{ t('governance.chain.verifyNow') }}</button>
                </div>
            </Card>

            <!-- Reconciliation status (the D-068 monitor) + any persisted alarm. -->
            <Card :title="t('governance.reconcile.cardTitle')" :subtitle="t('governance.reconcile.cardSubtitle')">
                <div class="space-y-1 text-sm">
                    <p class="flex items-center gap-2">
                        <span
                            v-if="reconciliation.passed !== null"
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="reconciliation.passed ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
                        >
                            {{ reconciliation.passed ? t('governance.reconcile.passes') : t('governance.reconcile.fails') }}
                        </span>
                        <span class="text-ink-muted">{{ reconciliation.period ? t('governance.reconcile.period', { period: reconciliation.period }) : t('governance.reconcile.never') }}</span>
                    </p>
                    <p v-if="reconciliation.ranAt" class="text-ink-subtle">{{ t('governance.reconcile.ranAt') }}: {{ dateTime(reconciliation.ranAt) }}</p>
                    <p v-if="reconciliation.alarm" class="rounded-xl border border-danger/30 bg-danger-soft p-3 text-danger">{{ t('governance.reconcile.alarm') }}</p>
                </div>
            </Card>

            <!-- AI usage: facts (counts + cost). -->
            <Card :title="t('governance.ai.cardTitle')" :subtitle="t('governance.ai.cardSubtitle', { days: ai.windowDays })">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <table v-if="outcomes.length" class="w-full text-left text-sm">
                            <thead class="text-ink-muted">
                                <tr class="border-b border-line">
                                    <th class="py-2 pr-4 font-medium">{{ t('governance.ai.outcome') }}</th>
                                    <th class="py-2 font-medium">{{ t('governance.ai.count') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="[outcome, count] in outcomes" :key="outcome" class="border-b border-line/60">
                                    <td class="py-2 pr-4 font-mono text-ink">{{ outcome }}</td>
                                    <td class="py-2 text-ink-muted">{{ count }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-sm text-ink-muted">{{ t('governance.ai.none') }}</p>
                    </div>
                    <div class="space-y-3">
                        <div class="rounded-2xl bg-euca-50 p-4">
                            <p class="text-xs font-medium text-ink-muted">{{ t('governance.ai.total') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-ink">{{ ai.total }}</p>
                        </div>
                        <div class="rounded-2xl bg-euca-50 p-4">
                            <p class="text-xs font-medium text-ink-muted">{{ t('governance.ai.cost') }}</p>
                            <p class="mt-1 text-2xl font-semibold text-ink">{{ aiCost }}</p>
                        </div>
                        <Link :href="queue.url" class="inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('governance.queue.view', { count: queue.pending }) }}</Link>
                    </div>
                </div>
            </Card>

            <!-- ── GOV.P2 · what is waiting on a person ───────────────────────────────────────
                 The REAL version of the slice the wireframe invented. Each category is a queryable
                 state with a real setter and clearer; each links to where a human actually acts. -->
            <Card :title="t('governance.needsHuman.title')" :subtitle="t('governance.needsHuman.subtitle')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-for="category in needsHuman.categories" :key="category.key">
                        <StatCard
                            :label="t(`governance.needsHuman.category.${category.key}`)"
                            :value="category.visible ? String(category.count) : '—'"
                            :hint="category.visible ? t(`governance.needsHuman.hint.${category.key}`) : t('governance.needsHuman.noPermission')"
                        />
                        <ul v-if="category.visible && category.items.length" class="mt-2 space-y-1.5">
                            <!-- Oldest first — a date sort over a recorded timestamp, never a priority. -->
                            <li v-for="item in category.items" :key="item.id" class="flex items-baseline justify-between gap-3 text-xs">
                                <span class="min-w-0 truncate text-ink-muted">{{ item.subject || item.tool || item.agent }}</span>
                                <span class="flex-none text-ink-subtle">{{ dateTime(item.waitingSince) }}</span>
                            </li>
                        </ul>
                        <Link v-if="category.visible" :href="category.actionUrl" class="mt-2 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">
                            {{ t(`governance.needsHuman.act.${category.key}`) }}
                        </Link>
                    </div>
                </div>

                <p v-if="needsHuman.total === 0" class="mt-5 rounded-xl bg-surface-2 p-4 text-sm text-ink-muted">
                    {{ t('governance.needsHuman.empty') }}
                </p>

                <!-- THE BOUNDARY, stated. An empty panel above means nothing in AGENT GOVERNANCE is
                     waiting — it is not a claim about the clinical worklists, which have their own
                     screens and owners. Saying so is what stops an honest empty state from reading
                     as a false all-clear. -->
                <div class="mt-5 border-t border-line pt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('governance.needsHuman.scope.title') }}</p>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('governance.needsHuman.scope.body') }}</p>
                    <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-subtle">
                        <li v-for="key in needsHuman.elsewhere" :key="key">{{ t(`governance.needsHuman.elsewhere.${key}`) }}</li>
                    </ul>
                    <p v-for="key in needsHuman.unproducible" :key="key" class="mt-2 text-xs text-ink-subtle">
                        {{ t(`governance.needsHuman.unproducible.${key}`) }}
                    </p>
                </div>
            </Card>
            <!-- ── GOV.P1 · the governance window ─────────────────────────────────────────────
                 Everything below is a real count over [from, to], recomputed on the SERVER when the
                 range changes. -->
            <Card :title="t('governance.window.title')" :subtitle="t('governance.window.subtitle', { from: metrics.from, to: metrics.to })">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        v-for="r in ranges"
                        :key="r"
                        type="button"
                        class="rounded-full px-3 py-1 text-xs font-medium transition"
                        :class="r === range ? 'nav-pill-active text-ink' : 'bg-surface-2 text-ink-muted hover:text-ink'"
                        @click="selectRange(r)"
                    >
                        {{ t(`governance.window.range.${r}`) }}
                    </button>
                </div>

                <!-- Outcome breakdown — the REAL statuses only. -->
                <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        v-for="status in STATUSES"
                        :key="status"
                        :label="t(`governance.window.status.${status}`)"
                        :value="String(metrics.byStatus[status] ?? 0)"
                        :hint="status === 'pending' ? t('governance.window.pendingHint') : undefined"
                    />
                </div>

                <p v-if="windowTotal === 0" class="mt-5 text-sm text-ink-muted">{{ t('governance.window.empty') }}</p>

                <!-- Per-agent activity. Agents with nothing in the window still appear, at zero:
                     an absent row would read as "no such agent" rather than "nothing happened". -->
                <div class="mt-6">
                    <h3 class="text-sm font-semibold text-ink">{{ t('governance.window.byAgent') }}</h3>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-sm">
                            <thead>
                                <tr class="border-b border-line text-xs uppercase tracking-wide text-ink-subtle">
                                    <th class="py-2 pr-3 font-medium">{{ t('governance.window.col.agent') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ t('governance.window.col.actions') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ t('governance.window.col.refused') }}</th>
                                    <th class="py-2 font-medium">{{ t('governance.window.col.approvedAsIs') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="agent in metrics.byAgent" :key="agent.key" class="border-b border-line/50">
                                    <td class="py-2 pr-3 text-ink">{{ agent.name }}</td>
                                    <td class="py-2 pr-3 text-ink-muted">{{ agent.total }}</td>
                                    <td class="py-2 pr-3 text-ink-muted">{{ agent.byStatus.fence_refused ?? 0 }}</td>
                                    <!-- "—" when nothing has resolved: honestly absent, never a fabricated 0 or 100. -->
                                    <td class="py-2 text-ink-muted">{{ pct(agent.approvedAsIsPct) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-ink-subtle">{{ t('governance.window.approvedAsIsNote') }}</p>
                </div>

                <!-- Per-tool activity — the REAL registry only, each row stating the tool's own cap. -->
                <div class="mt-6">
                    <h3 class="text-sm font-semibold text-ink">{{ t('governance.window.byTool') }}</h3>
                    <p v-if="!metrics.byTool.length" class="mt-2 text-sm text-ink-muted">{{ t('governance.window.noTools') }}</p>
                    <ul v-else class="mt-3 space-y-2">
                        <li v-for="tool in metrics.byTool" :key="tool.key" class="flex flex-wrap items-center justify-between gap-2 border-b border-line/50 pb-2 text-sm">
                            <span class="min-w-0">
                                <span class="text-ink">{{ tool.name }}</span>
                                <span class="ml-1.5 font-mono text-[11px] text-ink-subtle">{{ tool.key }}</span>
                            </span>
                            <span class="flex items-center gap-2">
                                <!-- The tool's REAL ceiling, so the screen states the cap rather than
                                     implying autonomy the resolver would refuse. -->
                                <span class="rounded-full bg-surface-2 px-2 py-0.5 text-[11px] font-medium text-ink-muted">{{ t('governance.window.ceiling', { level: tool.ceiling }) }}</span>
                                <span class="text-ink-muted">{{ tool.total }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-if="metrics.unregisteredTools > 0" class="mt-2 text-xs text-ink-subtle">
                        {{ t('governance.window.unregistered', { count: metrics.unregisteredTools }) }}
                    </p>
                </div>

                <!-- The fence. A COUNT of recorded refusals — not a score, and not a "breach" tally:
                     nothing records a breach, so a zero there would be unfalsifiable. -->
                <div class="mt-6 rounded-2xl border border-line bg-surface-2 p-4">
                    <p class="text-sm font-semibold text-ink">{{ t('governance.window.fence.title') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-ink">{{ metrics.fenceRefused }}</p>
                    <p class="mt-1 text-xs text-ink-muted">{{ t('governance.window.fence.note') }}</p>
                </div>
            </Card>

            <!-- The action ledger for the window — the SAME read-only view of the append-only
                 ai_interactions table the agent pages render, from the same presenter. -->
            <Card :title="t('governance.window.ledger.title')" :subtitle="t('governance.window.ledger.subtitle')">
                <p v-if="!windowLedger.length" class="text-sm text-ink-muted">{{ t('governance.window.ledger.empty') }}</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase tracking-wide text-ink-subtle">
                                <th class="py-2 pr-3 font-medium">{{ t('governance.window.ledger.when') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ t('governance.window.ledger.agent') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ t('governance.window.ledger.tool') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ t('governance.window.ledger.outcome') }}</th>
                                <th class="py-2 font-medium">{{ t('governance.window.ledger.detail') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in windowLedger" :key="row.id" class="border-b border-line/50 align-top">
                                <td class="whitespace-nowrap py-2 pr-3 text-ink-muted">{{ dateTime(row.occurredAt) }}</td>
                                <td class="py-2 pr-3 text-ink">{{ row.agentLabel }}</td>
                                <td class="py-2 pr-3 font-mono text-[11px] text-ink-subtle">{{ row.tool }}</td>
                                <td class="py-2 pr-3 text-ink-muted">{{ row.outcome }}</td>
                                <td class="py-2 text-xs text-ink-muted">{{ row.reason || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <!-- The fence vault — the SAME code-enforced invariants the agent page shows (AGENT.P3),
                 display-only. There is no toggle here, or anywhere. -->
            <Card :title="t('governance.vault.title')" :subtitle="t('governance.vault.subtitle')">
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li v-for="key in fenceInvariants" :key="key" class="flex items-start gap-2 text-sm">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-euca-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M5 12.5l4 4 10-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span class="text-ink">{{ t(`agentConfig.fence.invariants.${key}.title`) }}</span>
                    </li>
                </ul>
                <Link :href="agentsUrl" class="mt-4 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('governance.vault.manage') }}</Link>
            </Card>

            <!-- What this screen deliberately does NOT show, and why. Saying the gap is the honest
                 alternative to quietly omitting it — a reader who expected these numbers from the
                 wireframe learns that they have no source, rather than assuming a bug. -->
            <Card :title="t('governance.omitted.title')" :subtitle="t('governance.omitted.subtitle')">
                <ul class="space-y-1.5 text-sm text-ink-muted">
                    <li v-for="key in ['confidence', 'breaches', 'kbGaps', 'escalated']" :key="key" class="flex items-start gap-2">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-ink-subtle" />
                        <span>{{ t(`governance.omitted.${key}`) }}</span>
                    </li>
                </ul>
            </Card>
            <!-- Kill-switch state + security-relevant events. -->
            <div class="grid gap-6 lg:grid-cols-2">
                <Card :title="t('governance.kill.title')" :subtitle="t('governance.kill.subtitle')">
                    <p v-if="!kill.disabledFeatures.length" class="text-sm text-ink-muted">{{ t('governance.kill.allEnabled') }}</p>
                    <ul v-else class="space-y-1.5 text-sm">
                        <li v-for="feature in kill.disabledFeatures" :key="feature" class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-full bg-warning-soft px-2.5 py-0.5 text-xs font-semibold text-warning">{{ t('governance.kill.disabled') }}</span>
                            <span class="font-mono text-ink">{{ feature }}</span>
                        </li>
                    </ul>
                </Card>

                <Card :title="t('governance.security.title')" :subtitle="t('governance.security.subtitle')">
                    <p v-if="!security.length" class="text-sm text-ink-muted">{{ t('governance.security.none') }}</p>
                    <ul v-else class="space-y-2 text-sm">
                        <li v-for="event in security" :key="event.id" class="flex items-center justify-between gap-3 border-b border-line/60 pb-2">
                            <span class="font-mono text-ink">{{ event.action }}</span>
                            <span class="text-ink-subtle">{{ dateTime(event.occurredAt) }}</span>
                        </li>
                    </ul>
                </Card>
            </div>

            <!-- Recent audit activity — DISPLAYED, never editable (append-only chain). -->
            <Card :title="t('governance.activity.title')" :subtitle="t('governance.activity.note')">
                <table v-if="activity.length" class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('governance.activity.action') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('governance.activity.actor') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('governance.activity.resource') }}</th>
                            <th class="py-2 font-medium">{{ t('governance.activity.when') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="event in activity" :key="event.id" class="border-b border-line/60">
                            <td class="py-2 pr-4 font-mono text-ink">{{ event.action }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ event.actorType }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ event.resourceType ?? '—' }}</td>
                            <td class="py-2 text-ink-subtle">{{ dateTime(event.occurredAt) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-sm text-ink-muted">{{ t('governance.activity.empty') }}</p>
            </Card>
        </div>
    </AppLayout>
</template>
