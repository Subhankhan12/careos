<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t, te } = useI18n();

type Charge = {
    id: string;
    code: string;
    description: string;
    service_date: string;
    quantity: number;
    unit_price_minor: number;
    vat_rate_bp: number;
    line_total_minor: number;
    currency: string;
};
type Candidate = { id: string; name: string; mrn: string | null; charge_count: number; create_url: string };

const props = defineProps<{
    patient: { id: string; name: string; mrn: string | null } | null;
    charges: Charge[];
    payerTypes: string[];
    candidates: Candidate[];
    storeUrl: string;
    invoicesUrl: string;
}>();

function money(minor: number, currency = 'EUR'): string {
    return `${(minor / 100).toFixed(2)} ${currency}`;
}
function payerLabel(type: string): string {
    const key = `billing.payerType.${type}`;
    return te(key) ? t(key) : type;
}

const form = useForm({
    patient_id: props.patient?.id ?? '',
    charge_ids: props.charges.map((c) => c.id),
    payer_type: props.payerTypes[0] ?? 'self_pay',
    due_in_days: 30,
});

const hasSelection = computed(() => form.charge_ids.length > 0);

function submit(): void {
    form.post(props.storeUrl);
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.newInvoice.title')" />
        <div class="mx-auto max-w-3xl space-y-5">
            <nav class="flex items-center gap-2 text-sm text-ink-muted">
                <Link :href="invoicesUrl" class="hover:text-ink">{{ t('billing.invoices.title') }}</Link>
                <span aria-hidden="true">/</span>
                <span class="font-medium text-ink">{{ t('billing.newInvoice.title') }}</span>
            </nav>

            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('billing.newInvoice.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('billing.newInvoice.subtitle') }}</p>
            </div>

            <!-- Pick a patient with invoiceable charges. -->
            <div v-if="!patient" class="glass-card p-2">
                <div v-if="candidates.length" class="divide-y divide-line/70">
                    <Link v-for="c in candidates" :key="c.id" :href="c.create_url" class="flex items-center justify-between px-4 py-3 transition hover:bg-euca-50/50">
                        <span>
                            <span class="font-medium text-ink">{{ c.name }}</span>
                            <span v-if="c.mrn" class="ml-2 font-mono text-xs text-ink-muted">{{ c.mrn }}</span>
                        </span>
                        <span class="rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ t('billing.newInvoice.chargeCount', { count: c.charge_count }) }}</span>
                    </Link>
                </div>
                <p v-else class="px-4 py-12 text-center text-sm text-ink-muted">{{ t('billing.newInvoice.noneToInvoice') }}</p>
            </div>

            <!-- Assemble + issue for the chosen patient. -->
            <form v-else class="glass-card space-y-5 p-6" @submit.prevent="submit">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.newInvoice.forPatient') }}</p>
                    <p class="mt-1 font-medium text-ink">{{ patient.name }}<span v-if="patient.mrn" class="ml-2 font-mono text-xs text-ink-muted">{{ patient.mrn }}</span></p>
                </div>

                <div v-if="charges.length" class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="py-2 pr-3 font-semibold"></th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.lines.code') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ t('billing.lines.description') }}</th>
                                <th class="px-3 py-2 text-right font-semibold">{{ t('billing.lines.qty') }}</th>
                                <th class="py-2 pl-3 text-right font-semibold">{{ t('billing.lines.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/70">
                            <tr v-for="charge in charges" :key="charge.id">
                                <td class="py-3 pr-3"><input v-model="form.charge_ids" type="checkbox" :value="charge.id" class="h-4 w-4 rounded border-line text-euca-600 focus:ring-euca-200" /></td>
                                <td class="px-3 py-3 font-mono text-ink-muted">{{ charge.code }}</td>
                                <td class="px-3 py-3 text-ink">{{ charge.description }}<span class="ml-2 text-xs text-ink-subtle">{{ charge.service_date }}</span></td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink-muted">{{ charge.quantity }}</td>
                                <td class="py-3 pl-3 text-right tabular-nums text-ink">{{ money(charge.line_total_minor, charge.currency) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 px-1 text-xs text-ink-subtle">{{ t('billing.newInvoice.totalNote') }}</p>
                </div>
                <p v-else class="py-8 text-center text-sm text-ink-muted">{{ t('billing.newInvoice.noneToInvoice') }}</p>

                <div v-if="charges.length" class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.newInvoice.payer') }}</span>
                        <select v-model="form.payer_type" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200">
                            <option v-for="p in payerTypes" :key="p" :value="p">{{ payerLabel(p) }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.newInvoice.dueInDays') }}</span>
                        <input v-model.number="form.due_in_days" type="number" min="0" max="365" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink tabular-nums focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                    </label>
                </div>

                <p v-if="form.errors.charge_ids" class="text-xs text-danger">{{ form.errors.charge_ids }}</p>

                <div v-if="charges.length" class="flex justify-end gap-2">
                    <Link :href="invoicesUrl" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink-muted transition hover:bg-surface-2">{{ t('billing.actions.cancel') }}</Link>
                    <button type="submit" class="btn-glow" :disabled="form.processing || !hasSelection">{{ t('billing.newInvoice.issue') }}</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
