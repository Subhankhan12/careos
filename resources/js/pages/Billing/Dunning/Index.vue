<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateOnly } from '@/lib/date';

const { t, te } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type Row = {
    id: string;
    number: string | null;
    patient: string | null;
    due_date: string | null;
    open_balance_minor: number;
    currency: string;
    dunning_paused: boolean;
    current_level: number;
    last_event: { level: number; status: string; on: string } | null;
    show_url: string;
};

const props = defineProps<{
    rows: Row[];
    counters: { overdue: number; no_reminder: number };
    actions: { can_manage: boolean; run_url: string };
}>();

function money(minor: number, currency: string): string {
    return `${(minor / 100).toFixed(2)} ${currency}`;
}
function formatDate(value: string | null): string {
    // Date-only → local-midnight parse so the day never shifts by timezone (M-2).
    return formatDateOnly(value, locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' });
}
function eventStatusLabel(status: string): string {
    const key = `billing.dunning.eventStatus.${status}`;
    return te(key) ? t(key) : status;
}

const runForm = useForm({});
function run(): void {
    runForm.post(props.actions.run_url, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.dunning.title')" />
        <div class="space-y-5">
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('billing.dunning.title') }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ t('billing.dunning.subtitle', { count: counters.overdue }) }}</p>
                </div>
                <button v-if="actions.can_manage" type="button" class="btn-glow self-start sm:self-auto" :disabled="runForm.processing || rows.length === 0" @click="run">
                    {{ t('billing.dunning.run') }}
                </button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="glass-card p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.dunning.overdue') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ counters.overdue }}</p>
                </div>
                <div class="rounded-2xl border border-warning/30 bg-warning-soft p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-warning">{{ t('billing.dunning.noReminder') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-ink">{{ counters.no_reminder }}</p>
                </div>
            </div>

            <div class="glass-card p-2">
                <div class="overflow-x-auto">
                    <table v-if="rows.length" class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="px-3 py-2 font-semibold">{{ t('billing.dunning.invoice') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.dunning.patient') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.dunning.due') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('billing.dunning.balance') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.dunning.level') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.dunning.lastReminder') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="row in rows" :key="row.id" class="cursor-pointer transition hover:bg-euca-50/50" @click="router.visit(row.show_url)">
                                <td class="px-3 py-3 font-mono font-medium text-ink">
                                    {{ row.number ?? '—' }}
                                    <span v-if="row.dunning_paused" class="ml-2 rounded-full bg-surface-2 px-2 py-0.5 text-xs font-semibold text-ink-muted">{{ t('billing.dunning.paused') }}</span>
                                </td>
                                <td class="px-3 py-3 text-ink">{{ row.patient ?? '—' }}</td>
                                <td class="px-3 py-3 text-ink-muted">{{ formatDate(row.due_date) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink">{{ money(row.open_balance_minor, row.currency) }}</td>
                                <td class="px-3 py-3">
                                    <span v-if="row.current_level > 0" class="rounded-full bg-euca-100 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ t('billing.dunning.levelN', { n: row.current_level }) }}</span>
                                    <span v-else class="text-xs text-ink-subtle">{{ t('billing.dunning.none') }}</span>
                                </td>
                                <td class="px-3 py-3 text-ink-muted">
                                    <template v-if="row.last_event">{{ formatDate(row.last_event.on) }} · {{ eventStatusLabel(row.last_event.status) }}</template>
                                    <template v-else>—</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-12 text-center text-sm text-ink-muted">{{ t('billing.dunning.empty') }}</p>
                </div>
            </div>
            <p class="px-1 text-xs text-ink-subtle">{{ t('billing.dunning.footnote') }}</p>
        </div>
    </AppLayout>
</template>
