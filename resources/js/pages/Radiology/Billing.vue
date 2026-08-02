<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Radiology billing (RAD.G5) — PRESENTATIONAL. Price the exam, capture the charge (through the EXISTING engine),
// and invoice. NO money math here — the engine owns pricing/line-totals; the pre-invoice figure is a client-side
// estimate (quantity × the snapshotted rate). The fee is a tariff, NOT report-driven (the fence).
const { t, locale } = useI18n();

type ChargeRow = { code: string; description: string | null; quantity: number; unit_price_minor: number; status: string };

const props = defineProps<{
    order: { id: string; patient: string; exam: string | null; code: string | null; modality: string | null; priority: string; order_status: string | null; report_url: string };
    charges: ChargeRow[];
    tariffs: { code: string; name: string | null; unit_price_minor: number }[];
    invoice: { id: string; url: string; total_minor: number } | null;
    actions: { can_bill: boolean; price_exam_url: string; charge_url: string; invoice_url: string };
}>();

const form = reactive({ price_minor: '' });

function money(minor: number): string {
    try {
        return new Intl.NumberFormat(locale.value, { minimumFractionDigits: 2 }).format(minor / 100);
    } catch {
        return (minor / 100).toFixed(2);
    }
}

const estimateMinor = computed<number>(() => props.charges.reduce((sum, c) => sum + c.quantity * c.unit_price_minor, 0));
const isCharged = computed<boolean>(() => props.charges.length > 0);

function priceExam(): void {
    router.post(props.actions.price_exam_url, { price_minor: Number(form.price_minor) }, { preserveScroll: true, onSuccess: () => { form.price_minor = ''; } });
}
function charge(): void {
    router.post(props.actions.charge_url, {}, { preserveScroll: true });
}
function invoice(): void {
    router.post(props.actions.invoice_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('radiology.billing.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('radiology.billing.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ order.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ order.code }} — {{ order.exam }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-semibold text-euca-50">
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ t(`radiology.orders.priorityValue.${order.priority}`) }}</span>
                    <span v-if="order.modality" class="rounded-full bg-white/15 px-3 py-1">{{ order.modality }}</span>
                    <Link :href="order.report_url" class="rounded-full bg-white/15 px-3 py-1 hover:bg-white/25">{{ order.code }} →</Link>
                </div>
            </div>

            <div v-if="actions.can_bill && !isCharged" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.billing.price') }}</h2>
                <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="priceExam">
                    <input v-model="form.price_minor" type="number" min="1" step="1" :placeholder="t('radiology.billing.priceMinor')" class="w-48 rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <button type="submit" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('radiology.billing.priceBtn') }}</button>
                    <button type="button" class="rounded-full border border-euca-300 px-5 py-2 text-sm font-semibold text-euca-700 transition hover:bg-euca-50" @click="charge">{{ t('radiology.billing.chargeBtn') }}</button>
                </form>
                <p class="mt-2 text-xs text-ink-muted">{{ t('radiology.billing.priceHint') }}</p>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.billing.charged') }}</h2>
                <p v-if="!isCharged" class="mt-3 text-sm text-ink-muted">{{ t('radiology.billing.noCharges') }}</p>
                <table v-else class="mt-4 w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                        <tr>
                            <th class="py-1">{{ t('radiology.billing.code') }}</th>
                            <th class="py-1">{{ t('radiology.billing.description') }}</th>
                            <th class="py-1 text-right">{{ t('radiology.billing.qty') }}</th>
                            <th class="py-1 text-right">{{ t('radiology.billing.rate') }}</th>
                            <th class="py-1 text-right">{{ t('radiology.billing.estimate') }}</th>
                            <th class="py-1">{{ t('radiology.billing.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in charges" :key="c.code" class="border-t border-euca-50">
                            <td class="py-1.5 font-mono text-xs">{{ c.code }}</td>
                            <td class="py-1.5">{{ c.description }}</td>
                            <td class="py-1.5 text-right">{{ c.quantity }}</td>
                            <td class="py-1.5 text-right">{{ money(c.unit_price_minor) }}</td>
                            <td class="py-1.5 text-right">{{ money(c.quantity * c.unit_price_minor) }}</td>
                            <td class="py-1.5"><span class="rounded-full bg-ink/5 px-2 py-0.5 text-xs">{{ c.status }}</span></td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="isCharged && !invoice" class="mt-4 flex flex-wrap items-center gap-3">
                    <span class="text-sm text-ink-subtle">{{ t('radiology.billing.estimate') }}: <span class="font-semibold text-ink">{{ money(estimateMinor) }}</span></span>
                    <button v-if="actions.can_bill" type="button" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700" @click="invoice">{{ t('radiology.billing.invoiceBtn') }}</button>
                </div>
                <p v-if="isCharged && !invoice" class="mt-2 text-xs text-ink-muted">{{ t('radiology.billing.inpatientNote') }}</p>
            </div>

            <div v-if="invoice" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.billing.issued') }}</h2>
                <p class="mt-2 text-sm text-ink">{{ t('radiology.billing.total') }}: <span class="font-semibold">{{ money(invoice.total_minor) }}</span></p>
                <Link :href="invoice.url" class="mt-3 inline-block rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('radiology.billing.openInvoice') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
