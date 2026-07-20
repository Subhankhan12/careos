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
