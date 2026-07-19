<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateOnly } from '@/lib/date';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type CreditNote = {
    id: string;
    number: string;
    patient: string | null;
    payer_name: string | null;
    against_invoice: string | null;
    issue_date: string | null;
    currency: string;
    total_minor: number;
    show_url: string;
};

const props = defineProps<{
    creditNotes: CreditNote[];
    invoicesUrl: string;
}>();

function money(minor: number, currency: string): string {
    return `${(minor / 100).toFixed(2)} ${currency}`;
}
function formatDate(value: string | null): string {
    // Date-only → local-midnight parse so the day never shifts by timezone (M-2).
    return formatDateOnly(value, locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.creditNotes.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('billing.creditNotes.title') }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ t('billing.creditNotes.subtitle', { count: creditNotes.length }, creditNotes.length) }}</p>
                </div>
                <Link :href="invoicesUrl" class="self-start rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25 sm:self-auto">{{ t('billing.nav.invoices') }}</Link>
            </div>

            <!-- Table -->
            <div class="glass-card p-2">
                <div class="overflow-x-auto">
                    <table v-if="creditNotes.length" class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="px-3 py-2 font-semibold">{{ t('billing.creditNotes.number') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.creditNotes.patient') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.creditNotes.against') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.creditNotes.date') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('billing.creditNotes.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="cn in creditNotes" :key="cn.id" class="cursor-pointer transition hover:bg-euca-50/50" @click="router.visit(cn.show_url)">
                                <td class="px-3 py-3 font-mono font-medium text-ink">{{ cn.number }}</td>
                                <td class="px-3 py-3 text-ink">{{ cn.patient ?? cn.payer_name ?? '—' }}</td>
                                <td class="px-3 py-3 font-mono text-ink-muted">{{ cn.against_invoice ?? '—' }}</td>
                                <td class="px-3 py-3 text-ink-muted">{{ formatDate(cn.issue_date) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink">{{ money(cn.total_minor, cn.currency) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-12 text-center text-sm text-ink-muted">{{ t('billing.creditNotes.empty') }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
