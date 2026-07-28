<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// ED billing (ED.G6) — PRESENTATIONAL over EdBillingService. Set tenant-authored prices, capture charges
// through the EXISTING engine, and invoice (reconciles-to-the-unit). The line/estimate math here is
// presentational only — the module computes no money (the engine owns pricing/line-totals). The attendance
// fee is a plain tariff, NOT acuity-driven (the fence).
const { t } = useI18n();

type Tariff = { code: string; name: string; unit: string | null; unit_price_minor: number; is_attendance: boolean };
type ChargeRow = { code: string; description: string | null; quantity: number; unit_price_minor: number; status: string };

const props = defineProps<{
    visit: { id: string; patient: string; chief_complaint: string; status: string; disposition: string | null; admitted: boolean; record_url: string };
    charges: ChargeRow[];
    tariffs: Tariff[];
    invoice: { id: string; url: string; total_minor: number } | null;
    actions: {
        can_bill: boolean;
        attendance_code: string;
        price_attendance_url: string;
        price_service_url: string;
        charge_url: string;
        invoice_url: string;
    };
}>();

const attendancePrice = ref('');
const service = reactive({ code: '', name: '', price_minor: '' });
const chargeForm = reactive({ attendance: true, service_codes: [] as string[] });

const serviceTariffs = computed(() => props.tariffs.filter((tf) => !tf.is_attendance));
const hasAttendanceTariff = computed(() => props.tariffs.some((tf) => tf.is_attendance));

// Presentational estimate only (quantity × the snapshotted rate) — the engine owns the authoritative total.
const estimateMinor = computed(() => props.charges.reduce((sum, c) => sum + c.quantity * c.unit_price_minor, 0));

function money(minor: number): string {
    return (minor / 100).toFixed(2);
}

function priceAttendance(): void {
    router.post(props.actions.price_attendance_url, { price_minor: Number(attendancePrice.value) }, { preserveScroll: true });
}
function priceService(): void {
    router.post(props.actions.price_service_url, { code: service.code, name: service.name, price_minor: Number(service.price_minor) }, { preserveScroll: true });
}
function capture(): void {
    router.post(props.actions.charge_url, { attendance: chargeForm.attendance, service_codes: chargeForm.service_codes }, { preserveScroll: true });
}
function issueInvoice(): void {
    router.post(props.actions.invoice_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('ed.billing.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('ed.billing.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ visit.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ visit.chief_complaint }}</p>
                <Link :href="visit.record_url" class="mt-3 inline-block text-xs font-semibold text-euca-100 underline">{{ t('ed.billing.openRecord') }}</Link>
            </div>

            <!-- Tenant-authored pricing (attendance + services) — TariffItems; no licensed pricing. -->
            <div v-if="actions.can_bill" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.billing.pricing') }}</h2>
                <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="priceAttendance">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.billing.attendancePrice') }}</label>
                        <input v-model="attendancePrice" type="number" min="1" class="mt-1 w-40 rounded-lg border border-euca-200 px-3 py-2 text-sm" :placeholder="t('ed.billing.minorUnits')" />
                    </div>
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white">{{ t('ed.billing.setAttendance') }}</button>
                </form>
                <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="priceService">
                    <input v-model="service.code" type="text" maxlength="60" :placeholder="t('ed.billing.serviceCode')" class="w-32 rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <input v-model="service.name" type="text" maxlength="120" :placeholder="t('ed.billing.serviceName')" class="w-48 rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <input v-model="service.price_minor" type="number" min="1" :placeholder="t('ed.billing.minorUnits')" class="w-32 rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white">{{ t('ed.billing.addService') }}</button>
                </form>
                <ul v-if="tariffs.length" class="mt-4 space-y-1 text-sm">
                    <li v-for="tf in tariffs" :key="tf.code" class="flex justify-between text-ink">
                        <span>{{ tf.code }} — {{ tf.name }}</span>
                        <span class="text-ink-subtle">{{ money(tf.unit_price_minor) }}</span>
                    </li>
                </ul>
            </div>

            <!-- Capture charges through the existing engine. -->
            <div v-if="actions.can_bill && !charges.length" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.billing.capture') }}</h2>
                <form class="mt-3 space-y-2" @submit.prevent="capture">
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input v-model="chargeForm.attendance" type="checkbox" :disabled="!hasAttendanceTariff" />
                        {{ t('ed.billing.chargeAttendance') }}
                    </label>
                    <div v-if="serviceTariffs.length">
                        <p class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.billing.chargeServices') }}</p>
                        <label v-for="tf in serviceTariffs" :key="tf.code" class="mt-1 flex items-center gap-2 text-sm text-ink">
                            <input v-model="chargeForm.service_codes" type="checkbox" :value="tf.code" />
                            {{ tf.name }} ({{ money(tf.unit_price_minor) }})
                        </label>
                    </div>
                    <button type="submit" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white">{{ t('ed.billing.captureCharges') }}</button>
                </form>
            </div>

            <!-- Captured charges + the invoice. -->
            <div v-if="charges.length" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.billing.charges') }}</h2>
                <table class="mt-3 w-full text-sm">
                    <tbody>
                        <tr v-for="(c, i) in charges" :key="i" class="border-t border-euca-50 text-ink">
                            <td class="py-1">{{ c.code }}</td>
                            <td>{{ c.description }}</td>
                            <td class="text-right">{{ c.quantity }} × {{ money(c.unit_price_minor) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-right text-sm text-ink-subtle">{{ t('ed.billing.estimate') }}: {{ money(estimateMinor) }}</p>

                <div class="mt-4">
                    <div v-if="invoice">
                        <Link :href="invoice.url" class="text-sm font-semibold text-euca-700 underline">{{ t('ed.billing.invoiced', { total: money(invoice.total_minor) }) }}</Link>
                    </div>
                    <p v-else-if="visit.admitted" class="text-sm text-ink-muted">{{ t('ed.billing.joinsStay') }}</p>
                    <button v-else-if="actions.can_bill" type="button" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white" @click="issueInvoice">{{ t('ed.billing.issueInvoice') }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
