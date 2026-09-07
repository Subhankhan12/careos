<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import RefusalNotice from '@/Components/RefusalNotice.vue';

// Surgical case billing (SURGERY.G5) — PRESENTATIONAL. Capture the case's charges (procedure + theatre-time +
// consumables/implants) through the EXISTING engine, then issue an invoice that reconciles-to-the-unit.
//
// THIS PAGE COMPUTES NO MONEY AT ALL (QA-FIX.6a, P6-C1). It used to receive the quantity and the unit rate
// and derive both the line amounts and their sum client-side — a SECOND derivation of figures the engine
// has already computed and stored. Every amount below now arrives already summed and already formatted
// from `ChargeSetReader`, the engine-side reader. There is no arithmetic, no division by 100 and no
// currency guess in this file, and a test asserts that literally over every Surgery surface.
//
// NOTHING HERE MAY BE NAMED `invoice` EXCEPT THE PROP. A top-level function called `invoice()` used to
// shadow the `invoice` prop in the template; a function is always truthy, so the capture form's
// `v-if="!invoice"` was permanently false, the issue-invoice button was unreachable, and the total
// rendered as literal "NaN". The action is called `issueInvoice()` for that reason — see P6-C1.
const { t } = useI18n();

type Charge = { code: string; description: string | null; quantity: number; status: string; amount_formatted: string };
type Procedure = { code: string; name: string };

const props = defineProps<{
    surgicalCase: { id: string; patient: string; procedure: string; status: string; case_url: string };
    charges: Charge[];
    procedures: Procedure[];
    // The NET (ex-VAT) Σ of the captured charges, from the engine. Not an invoice total.
    capturedTotal: { minor: number; currency: string; formatted: string };
    invoice: { id: string; url: string; total_formatted: string } | null;
    actions: { can_bill: boolean; charge_url: string; invoice_url: string };
}>();

const billForm = reactive({ procedure_code: '', theatre_minutes: '' });

function charge(): void {
    router.post(props.actions.charge_url, { procedure_code: billForm.procedure_code || null, theatre_minutes: billForm.theatre_minutes ? Number(billForm.theatre_minutes) : null }, { preserveScroll: true });
}
function issueInvoice(): void {
    router.post(props.actions.invoice_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('surgery.billing.title')" />
        <div class="space-y-5">
            <!-- The server's own refusal, shown where it happened (QA-FIX.6c, P6-C3). -->
            <RefusalNotice />
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('surgery.billing.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ surgicalCase.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ surgicalCase.procedure }}</p>
                <Link :href="surgicalCase.case_url" class="mt-3 inline-block text-xs font-semibold text-euca-100 underline">{{ t('surgery.billing.backToCase') }}</Link>
            </div>

            <!-- Capture charges -->
            <form v-if="actions.can_bill && !invoice" class="glass-card space-y-3 p-6" @submit.prevent="charge">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.billing.capture') }}</h2>
                <p class="text-xs text-ink-muted">{{ t('surgery.billing.captureHint') }}</p>
                <div class="flex flex-wrap items-end gap-2">
                    <select v-model="billForm.procedure_code" class="w-56 rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">{{ t('surgery.billing.noProcedure') }}</option>
                        <option v-for="p in procedures" :key="p.code" :value="p.code">{{ p.name }}</option>
                    </select>
                    <input v-model="billForm.theatre_minutes" type="number" min="1" :placeholder="t('surgery.billing.theatreMinutes')" class="w-40 rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.billing.capture') }}</button>
                </div>
            </form>

            <!-- Charges -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.billing.charges') }}</h2>
                <p v-if="charges.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('surgery.billing.noCharges') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-100">
                    <li v-for="(c, i) in charges" :key="i" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ c.description ?? c.code }} <span class="text-ink-muted">· ×{{ c.quantity }}</span></span>
                        <span class="font-semibold text-ink">{{ c.amount_formatted }}</span>
                    </li>
                </ul>
                <!--
                    TWO DIFFERENT FIGURES, LABELLED DIFFERENTLY ON PURPOSE. Pre-invoice this is the net
                    (ex-VAT) Σ of THIS CASE's captured charges — the lines directly above — so it reads
                    "Estimated total". Once issued it is the INVOICE's own total, which is a broader
                    figure: `invoiceCase()` gathers every validated, uninvoiced charge for the patient
                    across the whole service DAY, so it can legitimately exceed the lines above it. It is
                    therefore labelled "Invoice total", not "Total", and the link beside it goes to the
                    invoice itself. Calling both "Total" would assert that the number sums this list.
                -->
                <div v-if="charges.length" class="mt-3 flex items-center justify-between border-t border-euca-200 pt-3">
                    <span class="text-sm font-semibold text-ink">{{ invoice ? t('surgery.billing.total') : t('surgery.billing.estimate') }}</span>
                    <span class="text-lg font-semibold text-ink">{{ invoice ? invoice.total_formatted : capturedTotal.formatted }}</span>
                </div>
                <div class="mt-4">
                    <Link v-if="invoice" :href="invoice.url" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.billing.viewInvoice') }}</Link>
                    <button v-else-if="actions.can_bill && charges.length" type="button" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700" @click="issueInvoice">{{ t('surgery.billing.issueInvoice') }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
