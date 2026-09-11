<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import RefusalNotice from '@/Components/RefusalNotice.vue';

// Radiology billing (RAD.G5) — PRESENTATIONAL. Price the exam, capture the charge (through the EXISTING
// engine), and invoice. The fee is a tariff, NOT report-driven (the fence).
//
// THIS PAGE COMPUTES NO MONEY AT ALL (QA-FIX.8a, P8-C1 — the QA-FIX.6a / D-208 remedy applied to
// Radiology). It used to receive the quantity and the unit rate and derive both the line amounts and their
// sum client-side — a SECOND derivation of figures the engine has already computed and stored. Every amount
// below now arrives already summed and already formatted from `ChargeSetReader`, the engine-side reader,
// WITH its currency. There is no arithmetic, no division by 100 and no currency guess in this file.
//
// NOTHING HERE MAY BE NAMED `invoice` EXCEPT THE PROP. A top-level `function invoice()` used to shadow the
// `invoice` prop in the template; a function is always truthy, so `v-if="invoice"` was permanently TRUE
// (the page announced "Issued invoice · Total: NaN" on orders never charged) while
// `v-if="isCharged && !invoice"` was permanently FALSE — so the issue-invoice button never rendered and an
// outpatient imaging invoice could not be issued at all. The action is called `issueInvoice()` for that reason.
const { t } = useI18n();

// Each line carries the ENGINE's own stored amount, already formatted — never a quantity × rate the page
// recomputed. `rate_formatted` is the tariff snapshot beside it, formatted by the same reader.
type ChargeRow = {
    code: string;
    description: string | null;
    quantity: number;
    status: string;
    amount_formatted: string;
    rate_formatted: string;
};

const props = defineProps<{
    order: { id: string; patient: string; exam: string | null; code: string | null; modality: string | null; priority: string; order_status: string | null; report_url: string };
    charges: ChargeRow[];
    // The NET (ex-VAT) Σ of the captured charges, from the engine. Not an invoice total.
    capturedTotal: { minor: number; currency: string; formatted: string };
    invoice: { id: string; url: string; total_formatted: string } | null;
    actions: { can_bill: boolean; price_exam_url: string; charge_url: string; invoice_url: string };
}>();

const form = reactive({ price_minor: '' });

const isCharged = computed<boolean>(() => props.charges.length > 0);

function priceExam(): void {
    router.post(props.actions.price_exam_url, { price_minor: Number(form.price_minor) }, { preserveScroll: true, onSuccess: () => { form.price_minor = ''; } });
}
function charge(): void {
    router.post(props.actions.charge_url, {}, { preserveScroll: true });
}
function issueInvoice(): void {
    router.post(props.actions.invoice_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('radiology.billing.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <!-- The server's own refusal, shown where it happened (QA-FIX.11a; D-210, D-213). -->
            <RefusalNotice />

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
                            <th class="py-1 text-right">{{ t('radiology.billing.amount') }}</th>
                            <th class="py-1">{{ t('radiology.billing.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in charges" :key="c.code" class="border-t border-euca-50">
                            <td class="py-1.5 font-mono text-xs">{{ c.code }}</td>
                            <td class="py-1.5">{{ c.description }}</td>
                            <td class="py-1.5 text-right">{{ c.quantity }}</td>
                            <td class="py-1.5 text-right">{{ c.rate_formatted }}</td>
                            <td class="py-1.5 text-right">{{ c.amount_formatted }}</td>
                            <td class="py-1.5"><span class="rounded-full bg-ink/5 px-2 py-0.5 text-xs">{{ c.status }}</span></td>
                        </tr>
                    </tbody>
                </table>

                <!--
                    TWO DIFFERENT FIGURES, LABELLED DIFFERENTLY ON PURPOSE (the QA-FIX.6a reasoning).
                    Pre-invoice this is the net (ex-VAT) Σ of THIS order's captured lines — the rows
                    directly above — so it reads "Estimated total". Once issued it is the INVOICE's own
                    total, a broader figure: `invoiceOrder()` gathers every validated, uninvoiced charge for
                    the patient across the whole service DAY, and the engine adds VAT at issue, so it can
                    legitimately exceed the lines above it. Calling both "Total" would assert that the
                    number sums this list. Plausible and wrong is worse than NaN.
                -->
                <div v-if="isCharged" class="mt-4 flex items-center justify-between border-t border-euca-200 pt-3">
                    <span class="text-sm font-semibold text-ink">{{ invoice ? t('radiology.billing.total') : t('radiology.billing.estimate') }}</span>
                    <span class="text-lg font-semibold text-ink">{{ invoice ? invoice.total_formatted : capturedTotal.formatted }}</span>
                </div>
                <div v-if="isCharged" class="mt-4">
                    <Link v-if="invoice" :href="invoice.url" class="inline-block rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('radiology.billing.openInvoice') }}</Link>
                    <button v-else-if="actions.can_bill" type="button" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700" @click="issueInvoice">{{ t('radiology.billing.invoiceBtn') }}</button>
                </div>
                <p v-if="isCharged && !invoice" class="mt-2 text-xs text-ink-muted">{{ t('radiology.billing.inpatientNote') }}</p>
            </div>
        </div>
    </AppLayout>
</template>
