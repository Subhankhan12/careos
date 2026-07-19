<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const { t, te } = useI18n();

const props = defineProps<{
    invoices: Array<{
        id: string;
        number: string;
        issue_date: string | null;
        due_date: string | null;
        currency: string;
        total_minor: number;
        open_balance_minor: number;
        status: string;
        download_url: string;
    }>;
}>();

const activeStatus = ref('all');

const statuses = computed(() => {
    const counts: Record<string, number> = {};
    for (const inv of props.invoices) counts[inv.status] = (counts[inv.status] ?? 0) + 1;
    return Object.entries(counts).map(([key, count]) => ({ key, count }));
});

const filtered = computed(() =>
    activeStatus.value === 'all' ? props.invoices : props.invoices.filter((i) => i.status === activeStatus.value),
);

// Open-balance summary is ALWAYS the full total — filters narrow the list only.
const openBalance = computed(() => props.invoices.reduce((sum, i) => sum + i.open_balance_minor, 0));
const currency = computed(() => props.invoices[0]?.currency ?? '');

function money(minor: number): string {
    return (minor / 100).toFixed(2);
}

function statusLabel(status: string): string {
    const key = `portal.invoices.statuses.${status}`;
    return te(key) ? t(key) : status;
}

function statusDot(status: string): string {
    if (status === 'paid') return 'bg-success';
    if (status === 'partially_paid') return 'bg-info';
    return 'bg-warning';
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.invoices.title')" />

        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('portal.invoices.eyebrow') }}</p>
        <div class="mt-1 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{{ t('portal.invoices.title') }}</h1>
                <p class="mt-1 text-ink-muted">{{ t('portal.invoices.subtitle') }}</p>
            </div>
            <div v-if="invoices.length" class="glass-card px-5 py-3">
                <p class="text-xs font-medium text-ink-muted">{{ t('portal.invoices.openBalance') }}</p>
                <p class="text-xl font-semibold text-ink">{{ money(openBalance) }} {{ currency }}</p>
            </div>
        </div>

        <div v-if="invoices.length" class="mt-6 flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="rounded-full px-3.5 py-1.5 text-sm font-medium transition"
                :class="activeStatus === 'all' ? 'nav-pill-active text-ink' : 'bg-euca-50 text-ink-muted hover:text-ink'"
                @click="activeStatus = 'all'"
            >
                {{ t('portal.invoices.all') }} · {{ invoices.length }}
            </button>
            <button
                v-for="s in statuses"
                :key="s.key"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium transition"
                :class="activeStatus === s.key ? 'nav-pill-active text-ink' : 'bg-euca-50 text-ink-muted hover:text-ink'"
                @click="activeStatus = s.key"
            >
                <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(s.key)"></span>
                {{ statusLabel(s.key) }} · {{ s.count }}
            </button>
        </div>
        <p v-if="invoices.length" class="mt-2 text-xs text-ink-subtle">{{ t('portal.invoices.filterNote') }}</p>

        <div class="glass-card mt-4 p-4 sm:p-6">
            <div class="overflow-x-auto">
                <table v-if="filtered.length" class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs uppercase tracking-wide text-ink-subtle">
                            <th class="py-2 pr-4">{{ t('portal.invoices.number') }}</th>
                            <th class="py-2 pr-4">{{ t('portal.invoices.issued') }}</th>
                            <th class="py-2 pr-4">{{ t('portal.invoices.due') }}</th>
                            <th class="py-2 pr-4">{{ t('portal.invoices.total') }}</th>
                            <th class="py-2 pr-4">{{ t('portal.invoices.open') }}</th>
                            <th class="py-2 pr-4">{{ t('portal.invoices.status') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/70">
                        <tr v-for="invoice in filtered" :key="invoice.id">
                            <td class="py-3 pr-4 font-medium text-ink">{{ invoice.number }}</td>
                            <td class="py-3 pr-4 text-ink-muted">{{ invoice.issue_date ?? '—' }}</td>
                            <td class="py-3 pr-4 text-ink-muted">{{ invoice.due_date ?? '—' }}</td>
                            <td class="py-3 pr-4 text-ink">{{ money(invoice.total_minor) }} {{ invoice.currency }}</td>
                            <td class="py-3 pr-4 text-ink">{{ money(invoice.open_balance_minor) }} {{ invoice.currency }}</td>
                            <td class="py-3 pr-4">
                                <span class="inline-flex items-center gap-1.5 text-ink-muted">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(invoice.status)"></span>
                                    {{ statusLabel(invoice.status) }}
                                </span>
                            </td>
                            <td class="py-3">
                                <a
                                    :href="invoice.download_url"
                                    class="inline-flex items-center gap-1.5 font-semibold text-euca-700 transition hover:text-euca-800"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 4v10m0 0l-3.5-3.5M12 14l3.5-3.5M5 19h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    {{ t('portal.invoices.download') }}
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="py-12 text-center text-ink-muted">{{ t('portal.invoices.empty') }}</p>
            </div>
        </div>

        <p v-if="invoices.length" class="mt-4 text-sm text-ink-subtle">{{ t('portal.invoices.footer') }}</p>
    </PortalLayout>
</template>
