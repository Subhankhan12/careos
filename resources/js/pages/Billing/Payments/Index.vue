<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t, te } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type Payment = {
    id: string;
    patient: string | null;
    method: string;
    amount_minor: number;
    unallocated_minor: number;
    currency: string;
    received_on: string;
    reference: string | null;
    show_url: string;
};

defineProps<{
    payments: Payment[];
    recordUrl: string;
    methods: string[];
    canManage: boolean;
}>();

function money(minor: number, currency: string): string {
    return `${(minor / 100).toFixed(2)} ${currency}`;
}
function methodLabel(method: string): string {
    const key = `billing.method.${method}`;
    return te(key) ? t(key) : method;
}
function formatDate(value: string): string {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d);
    } catch {
        return value;
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.payments.title')" />
        <div class="space-y-5">
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('billing.payments.title') }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ t('billing.payments.subtitle', { count: payments.length }) }}</p>
                </div>
                <Link v-if="canManage" :href="recordUrl" class="btn-glow self-start sm:self-auto">{{ t('billing.payments.record') }}</Link>
            </div>

            <div class="glass-card p-2">
                <div class="overflow-x-auto">
                    <table v-if="payments.length" class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="px-3 py-2 font-semibold">{{ t('billing.payments.received') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.payments.patient') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.payments.method') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.payments.reference') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('billing.payments.amount') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('billing.payments.unallocated') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="payment in payments" :key="payment.id" class="cursor-pointer transition hover:bg-euca-50/50" @click="router.visit(payment.show_url)">
                                <td class="px-3 py-3 text-ink-muted">{{ formatDate(payment.received_on) }}</td>
                                <td class="px-3 py-3 text-ink">{{ payment.patient ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <span class="rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ methodLabel(payment.method) }}</span>
                                </td>
                                <td class="px-3 py-3 font-mono text-ink-muted">{{ payment.reference ?? '—' }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink">{{ money(payment.amount_minor, payment.currency) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums" :class="payment.unallocated_minor > 0 ? 'text-warning' : 'text-ink-muted'">{{ money(payment.unallocated_minor, payment.currency) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-12 text-center text-sm text-ink-muted">{{ t('billing.payments.empty') }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
