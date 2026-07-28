<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Surgical case billing (SURGERY.G5) — PRESENTATIONAL. Capture the case's charges (procedure + theatre-time +
// consumables/implants) through the EXISTING engine, then issue an invoice that reconciles-to-the-unit. The
// ENGINE owns every authoritative figure (the issued invoice's total); pre-invoice, this page shows a
// CLIENT-SIDE estimate from quantity × the snapshotted rate — the module itself computes no money (the fence).
const { t, locale } = useI18n();

type Charge = { code: string; description: string | null; quantity: number; unit_price_minor: number; status: string };
type Procedure = { code: string; name: string };

const props = defineProps<{
    surgicalCase: { id: string; patient: string; procedure: string; status: string; case_url: string };
    charges: Charge[];
    procedures: Procedure[];
    invoice: { id: string; url: string; total_minor: number } | null;
    actions: { can_bill: boolean; charge_url: string; invoice_url: string };
}>();

const billForm = reactive({ procedure_code: '', theatre_minutes: '' });

// A client-side estimate (quantity × rate) shown until the engine issues the authoritative invoice total.
const estimateMinor = computed<number>(() => props.charges.reduce((sum, c) => sum + c.quantity * c.unit_price_minor, 0));

function lineMinor(c: Charge): number {
    return c.quantity * c.unit_price_minor;
}
function charge(): void {
    router.post(props.actions.charge_url, { procedure_code: billForm.procedure_code || null, theatre_minutes: billForm.theatre_minutes ? Number(billForm.theatre_minutes) : null }, { preserveScroll: true });
}
function invoice(): void {
    router.post(props.actions.invoice_url, {}, { preserveScroll: true });
}
function money(minor: number): string {
    return (minor / 100).toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('surgery.billing.title')" />
        <div class="space-y-5">
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
                        <span class="font-semibold text-ink">{{ money(lineMinor(c)) }}</span>
                    </li>
                </ul>
                <div v-if="charges.length" class="mt-3 flex items-center justify-between border-t border-euca-200 pt-3">
                    <span class="text-sm font-semibold text-ink">{{ invoice ? t('surgery.billing.total') : t('surgery.billing.estimate') }}</span>
                    <span class="text-lg font-semibold text-ink">{{ money(invoice ? invoice.total_minor : estimateMinor) }}</span>
                </div>
                <div class="mt-4">
                    <Link v-if="invoice" :href="invoice.url" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.billing.viewInvoice') }}</Link>
                    <button v-else-if="actions.can_bill && charges.length" type="button" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700" @click="invoice">{{ t('surgery.billing.issueInvoice') }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
