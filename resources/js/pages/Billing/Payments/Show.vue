<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t, te } = useI18n();
const page = usePage();
// A record-then-allocate that the service rejects redirects HERE with a flashed
// 'allocate' error; surface it (the payment is recorded, the allocation was not).
const flashedAllocateError = computed(() => (page.props.errors as Record<string, string> | undefined)?.allocate ?? null);

type Allocation = {
    id: string;
    invoice_number: string | null;
    invoice_url: string | null;
    amount_minor: number;
    is_reversal: boolean;
    reversed: boolean;
    reason: string | null;
    allocated_on: string;
};
type Refund = { id: string; amount_minor: number; reason: string; refunded_on: string };
type OpenInvoice = { id: string; number: string | null; open_balance_minor: number; currency: string };
type Payment = {
    id: string;
    patient: string | null;
    method: string;
    amount_minor: number;
    unallocated_minor: number;
    currency: string;
    received_on: string;
    reference: string | null;
    payer_reference: string | null;
};

const props = defineProps<{
    payment: Payment;
    allocations: Allocation[];
    refunds: Refund[];
    openInvoices: OpenInvoice[];
    actions: { can_manage: boolean; allocate_url: string; reverse_url: string; paymentsUrl: string };
}>();

function money(minor: number, currency = props.payment.currency): string {
    return `${(minor / 100).toFixed(2)} ${currency}`;
}
function methodLabel(method: string): string {
    const key = `billing.method.${method}`;
    return te(key) ? t(key) : method;
}

const allocateForm = useForm({ invoice_id: props.openInvoices[0]?.id ?? '', amount: '' });
function submitAllocate(): void {
    allocateForm
        .transform((d) => ({
            invoice_id: d.invoice_id,
            amount_minor: Number.isFinite(Number.parseFloat(d.amount)) ? Math.round(Number.parseFloat(d.amount) * 100) : 0,
        }))
        .post(props.actions.allocate_url, { preserveScroll: true });
}

const reverseTarget = ref<string | null>(null);
const reverseForm = useForm({ allocation_id: '', reason: '' });
function openReverse(id: string): void {
    reverseTarget.value = id;
    reverseForm.reset();
    reverseForm.allocation_id = id;
}
function submitReverse(): void {
    reverseForm.post(props.actions.reverse_url, {
        preserveScroll: true,
        onSuccess: () => {
            reverseTarget.value = null;
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('billing.payments.title')" />
        <div class="space-y-5">
            <nav class="flex items-center gap-2 text-sm text-ink-muted">
                <Link :href="actions.paymentsUrl" class="hover:text-ink">{{ t('billing.payments.title') }}</Link>
                <span aria-hidden="true">/</span>
                <span class="font-medium text-ink">{{ money(payment.amount_minor) }}</span>
            </nav>

            <div class="euca-tile-dark flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold tracking-tight text-euca-50">{{ money(payment.amount_minor) }}</h1>
                        <span class="rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-semibold text-euca-50">{{ methodLabel(payment.method) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-euca-200">
                        <span v-if="payment.patient">{{ payment.patient }} · </span>{{ t('billing.payments.received') }} {{ payment.received_on }}
                        <span v-if="payment.reference"> · {{ payment.reference }}</span>
                    </p>
                </div>
                <div class="text-left lg:text-right">
                    <p class="text-xs font-semibold uppercase tracking-wide text-euca-200">{{ t('billing.payments.unallocated') }}</p>
                    <p class="text-2xl font-semibold text-euca-50">{{ money(payment.unallocated_minor) }}</p>
                </div>
            </div>

            <div v-if="flashedAllocateError" class="rounded-2xl border border-danger/30 bg-danger-soft p-4 text-sm text-danger">
                {{ t('billing.payments.allocationRejected') }} {{ flashedAllocateError }}
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <div class="glass-card space-y-4 p-6 lg:col-span-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.payments.allocations') }}</p>
                    <div v-if="allocations.length" class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                                <tr class="border-b border-line">
                                    <th class="py-2 pr-3 font-semibold">{{ t('billing.payments.invoice') }}</th>
                                    <th class="px-3 py-2 font-semibold">{{ t('billing.payments.date') }}</th>
                                    <th class="px-3 py-2 text-right font-semibold">{{ t('billing.payments.amount') }}</th>
                                    <th class="py-2 pl-3 font-semibold"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line/70">
                                <tr v-for="a in allocations" :key="a.id" :class="a.is_reversal ? 'bg-danger-soft/40' : ''">
                                    <td class="py-3 pr-3">
                                        <Link v-if="a.invoice_url" :href="a.invoice_url" class="font-mono font-medium text-euca-700 hover:underline">{{ a.invoice_number ?? '—' }}</Link>
                                        <span v-else class="font-mono text-ink-muted">{{ a.invoice_number ?? '—' }}</span>
                                        <span v-if="a.is_reversal" class="ml-2 rounded-full bg-danger-soft px-2 py-0.5 text-xs font-semibold text-danger">{{ t('billing.payments.reversal') }}</span>
                                        <span v-else-if="a.reversed" class="ml-2 rounded-full bg-surface-2 px-2 py-0.5 text-xs font-semibold text-ink-muted">{{ t('billing.payments.reversedTag') }}</span>
                                        <p v-if="a.reason" class="mt-0.5 text-xs text-ink-muted">{{ a.reason }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-ink-muted">{{ a.allocated_on }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums" :class="a.amount_minor < 0 ? 'text-danger' : 'text-ink'">{{ money(a.amount_minor) }}</td>
                                    <td class="py-3 pl-3 text-right">
                                        <button v-if="actions.can_manage && !a.is_reversal && !a.reversed" type="button" class="text-xs font-semibold text-danger hover:underline" @click="openReverse(a.id)">{{ t('billing.payments.reverse') }}</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="py-6 text-center text-sm text-ink-muted">{{ t('billing.payments.noAllocations') }}</p>

                    <!-- Reverse: reason required; the reversal itself is appended by PaymentService. -->
                    <form v-if="reverseTarget" class="space-y-2 rounded-2xl border border-danger/30 bg-danger-soft/50 p-4" @submit.prevent="submitReverse">
                        <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="rev-reason">{{ t('billing.payments.reverseReason') }}</label>
                        <textarea id="rev-reason" v-model="reverseForm.reason" rows="2" maxlength="500" required class="w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200"></textarea>
                        <p v-if="reverseForm.errors.reason" class="text-xs text-danger">{{ reverseForm.errors.reason }}</p>
                        <p v-if="reverseForm.errors.reverse" class="text-xs text-danger">{{ reverseForm.errors.reverse }}</p>
                        <div class="flex gap-2">
                            <button type="submit" class="rounded-xl bg-danger px-4 py-2 text-sm font-semibold text-white transition hover:bg-danger/90 disabled:opacity-60" :disabled="reverseForm.processing || reverseForm.reason.trim().length === 0">{{ t('billing.payments.reverseConfirm') }}</button>
                            <button type="button" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink-muted" @click="reverseTarget = null">{{ t('billing.actions.cancel') }}</button>
                        </div>
                    </form>

                    <div v-if="refunds.length" class="border-t border-line pt-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.payments.refunds') }}</p>
                        <div v-for="r in refunds" :key="r.id" class="mt-2 flex items-center justify-between text-sm">
                            <span class="text-ink-muted">{{ r.refunded_on }} · {{ r.reason }}</span>
                            <span class="tabular-nums text-ink">{{ money(r.amount_minor) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Allocate remainder against an open invoice. -->
                <div v-if="actions.can_manage && payment.unallocated_minor > 0 && openInvoices.length" class="glass-card space-y-3 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('billing.payments.allocateRemainder') }}</p>
                    <form class="space-y-3" @submit.prevent="submitAllocate">
                        <label class="block">
                            <span class="text-xs text-ink-muted">{{ t('billing.payments.invoice') }}</span>
                            <select v-model="allocateForm.invoice_id" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200">
                                <option v-for="inv in openInvoices" :key="inv.id" :value="inv.id">{{ inv.number ?? '—' }} · {{ money(inv.open_balance_minor, inv.currency) }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs text-ink-muted">{{ t('billing.payments.amount') }} ({{ payment.currency }})</span>
                            <input v-model="allocateForm.amount" type="number" step="0.01" min="0" required inputmode="decimal" class="mt-1 w-full rounded-xl border border-line bg-white/70 px-3 py-2 text-sm text-ink tabular-nums focus:border-euca-400 focus:outline-none focus:ring-2 focus:ring-euca-200" />
                        </label>
                        <p v-if="allocateForm.errors.allocate" class="text-xs text-danger">{{ allocateForm.errors.allocate }}</p>
                        <p v-if="allocateForm.errors.amount_minor" class="text-xs text-danger">{{ allocateForm.errors.amount_minor }}</p>
                        <button type="submit" class="btn-glow w-full justify-center" :disabled="allocateForm.processing">{{ t('billing.payments.allocateConfirm') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
