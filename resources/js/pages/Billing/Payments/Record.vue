<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t, te } = useI18n();

type TargetInvoice = {
    id: string;
    number: string | null;
    currency: string;
    open_balance_minor: number;
    patient: string | null;
};

const props = defineProps<{
    methods: string[];
    invoice: TargetInvoice | null;
    patientId: string | null;
    storeUrl: string;
    paymentsUrl: string;
}>();

const currency = computed(() => props.invoice?.currency ?? 'EUR');

function money(minor: number): string {
    return `${(minor / 100).toFixed(2)} ${currency.value}`;
}
function methodLabel(method: string): string {
    const key = `billing.method.${method}`;
    return te(key) ? t(key) : method;
}
// Minor units for a major-unit text input — input normalisation only; the service
// validates the integer and owns every downstream money calculation.
function toMinor(value: string): number {
    const n = Number.parseFloat(value);
    return Number.isFinite(n) ? Math.round(n * 100) : 0;
}

const today = new Date().toISOString().slice(0, 10);
const allocate = ref(props.invoice !== null);

const form = useForm({
    amount: props.invoice ? (props.invoice.open_balance_minor / 100).toFixed(2) : '',
    method: props.methods[0] ?? 'bank_transfer',
    received_on: today,
    reference: '',
    allocate_amount: props.invoice ? (props.invoice.open_balance_minor / 100).toFixed(2) : '',
});

function submit(): void {
    form
        .transform((data) => ({
            amount_minor: toMinor(data.amount),
            method: data.method,
            received_on: data.received_on,
            reference: data.reference || null,
            patient_id: props.patientId,
            invoice_id: props.invoice && allocate.value ? props.invoice.id : null,
            allocate_amount_minor: props.invoice && allocate.value ? toMinor(data.allocate_amount) : null,
        }))
        .post(props.storeUrl);
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.payments.record')" />
        <div class="mx-auto max-w-2xl space-y-5">
            <nav class="flex items-center gap-2 text-sm text-ink-muted">
                <Link :href="paymentsUrl" class="hover:text-ink">{{ t('billing.payments.title') }}</Link>
                <span aria-hidden="true">/</span>
                <span class="font-medium text-ink">{{ t('billing.payments.record') }}</span>
            </nav>

            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('billing.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('billing.payments.record') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('billing.payments.recordHint') }}</p>
            </div>

            <form class="glass-card space-y-5 p-6" @submit.prevent="submit">
                <div v-if="invoice" class="rounded-2xl border border-euca-200 bg-euca-50/60 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.payments.forInvoice') }}</p>
                    <div class="mt-1 flex items-center justify-between">
                        <span class="font-mono font-medium text-ink">{{ invoice.number ?? '—' }}<span v-if="invoice.patient" class="ml-2 font-sans text-ink-muted">· {{ invoice.patient }}</span></span>
                        <span class="text-sm text-ink-muted">{{ t('billing.payments.openBalance') }} <span class="font-semibold text-ink tabular-nums">{{ money(invoice.open_balance_minor) }}</span></span>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.payments.amount') }} ({{ currency }})</span>
                        <input v-model="form.amount" type="number" step="0.01" min="0" required inputmode="decimal" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink tabular-nums focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                        <span v-if="form.errors.amount_minor" class="text-xs text-danger">{{ form.errors.amount_minor }}</span>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.payments.method') }}</span>
                        <select v-model="form.method" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200">
                            <option v-for="m in methods" :key="m" :value="m">{{ methodLabel(m) }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.payments.received') }}</span>
                        <input v-model="form.received_on" type="date" required class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.payments.reference') }}</span>
                        <input v-model="form.reference" type="text" maxlength="255" :placeholder="t('billing.payments.referencePlaceholder')" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                    </label>
                </div>

                <div v-if="invoice" class="rounded-2xl border border-line bg-white/50 p-4">
                    <label class="flex items-center gap-2 text-sm font-medium text-ink">
                        <input v-model="allocate" type="checkbox" class="h-4 w-4 rounded border-line text-euca-600 focus:ring-euca-200" />
                        {{ t('billing.payments.allocateNow') }}
                    </label>
                    <label v-if="allocate" class="mt-3 block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.payments.allocateAmount') }} ({{ currency }})</span>
                        <input v-model="form.allocate_amount" type="number" step="0.01" min="0" inputmode="decimal" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink tabular-nums focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                        <span v-if="form.errors.allocate" class="text-xs text-danger">{{ form.errors.allocate }}</span>
                        <span v-if="form.errors.allocate_amount_minor" class="text-xs text-danger">{{ form.errors.allocate_amount_minor }}</span>
                    </label>
                </div>

                <p class="text-xs text-ink-subtle">{{ t('billing.payments.noPsp') }}</p>

                <div class="flex justify-end gap-2">
                    <Link :href="paymentsUrl" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink-muted transition hover:bg-surface-2">{{ t('billing.actions.cancel') }}</Link>
                    <button type="submit" class="btn-glow" :disabled="form.processing">{{ t('billing.payments.recordConfirm') }}</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
