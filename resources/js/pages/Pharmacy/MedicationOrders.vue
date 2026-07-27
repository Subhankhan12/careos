<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Medication orders (PHARMACY.G2) — PRESENTATIONAL. Clinician-authored dose/route/frequency/PRN; active
// orders + history + hold/discontinue. The alerts area is wired to the safety seam's SafetyResult and is
// EMPTY today (the null-object asserts nothing) — ready for a future certified partner. Record-not-judge:
// nothing here computes a dose, suggests a med, ranks alternatives, or judges safety.
const { t } = useI18n();

type Order = {
    id: string;
    code: string | null;
    name: string | null;
    dose: string;
    route: string;
    frequency: string;
    prn: boolean;
    prn_reason: string | null;
    note: string | null;
    status: string;
    status_reason: string | null;
    starts_at: string;
    stops_at: string | null;
    transition_url: string;
};

type FormularyOption = { id: string; code: string; name: string; strength: string | null };
type Alert = { code: string; message: string; source: string };

const props = defineProps<{
    patient: { id: string; name: string };
    active: Order[];
    history: Order[];
    alerts: Alert[];
    formulary: FormularyOption[];
    routes: string[];
    actions: { can_prescribe: boolean; store_url: string };
}>();

const blank = { formulary_item_id: '', dose_amount: '', dose_unit: '', route: '', frequency: '', prn: false, prn_reason: '', note: '', stops_at: '' };
const form = reactive({ ...blank });

const discontinuedHistory = computed(() => props.history.filter((o) => o.status !== 'active'));

function submit(): void {
    router.post(props.actions.store_url, { ...form }, {
        preserveScroll: true,
        onSuccess: () => Object.assign(form, blank),
    });
}

function transition(order: Order, status: string): void {
    router.post(order.transition_url, { status }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('pharmacy.medications.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('pharmacy.medications.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ patient.name }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('pharmacy.medications.subtitle') }}</p>
            </div>

            <!-- Safety alerts — wired to the seam's SafetyResult; EMPTY today (renders nothing without alerts). -->
            <div v-if="alerts.length" class="glass-card border-l-4 border-amber-400 p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('pharmacy.medications.alerts') }}</h2>
                <ul class="mt-3 space-y-2">
                    <li v-for="alert in alerts" :key="alert.code" class="text-sm text-ink">
                        {{ alert.message }} <span class="text-ink-muted">({{ alert.source }})</span>
                    </li>
                </ul>
            </div>

            <!-- Place a medication order -->
            <form v-if="actions.can_prescribe" class="glass-card grid gap-3 p-6 sm:grid-cols-2 xl:grid-cols-3" @submit.prevent="submit">
                <div class="sm:col-span-2 xl:col-span-3">
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="m-item">{{ t('pharmacy.medications.medication') }}</label>
                    <select id="m-item" v-model="form.formulary_item_id" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">—</option>
                        <option v-for="item in formulary" :key="item.id" :value="item.id">{{ item.name }}<template v-if="item.strength"> · {{ item.strength }}</template></option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="m-dose">{{ t('pharmacy.medications.dose') }}</label>
                    <input id="m-dose" v-model="form.dose_amount" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" :placeholder="t('pharmacy.medications.dosePlaceholder')" />
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="m-unit">{{ t('pharmacy.medications.unit') }}</label>
                    <input id="m-unit" v-model="form.dose_unit" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" :placeholder="t('pharmacy.medications.unitPlaceholder')" />
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="m-route">{{ t('pharmacy.medications.route') }}</label>
                    <select id="m-route" v-model="form.route" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">—</option>
                        <option v-for="r in routes" :key="r" :value="r">{{ r }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="m-freq">{{ t('pharmacy.medications.frequency') }}</label>
                    <input id="m-freq" v-model="form.frequency" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" :placeholder="t('pharmacy.medications.frequencyPlaceholder')" />
                </div>
                <div class="flex items-end gap-2">
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input v-model="form.prn" type="checkbox" class="rounded border-euca-300" />
                        {{ t('pharmacy.medications.prn') }}
                    </label>
                </div>
                <div v-if="form.prn">
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="m-prn">{{ t('pharmacy.medications.prnReason') }}</label>
                    <input id="m-prn" v-model="form.prn_reason" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                </div>
                <div class="sm:col-span-2 xl:col-span-3">
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="m-note">{{ t('pharmacy.medications.note') }}</label>
                    <input id="m-note" v-model="form.note" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                </div>
                <div class="sm:col-span-2 xl:col-span-3">
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">
                        {{ t('pharmacy.medications.order') }}
                    </button>
                </div>
            </form>

            <!-- Active medication orders -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('pharmacy.medications.activeHeading') }}</h2>
                <p v-if="active.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('pharmacy.medications.noneActive') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-100">
                    <li v-for="order in active" :key="order.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">
                                {{ order.name }} <span class="text-ink-muted">· {{ order.dose }} · {{ order.route }} · {{ order.frequency }}</span>
                                <span v-if="order.prn" class="ml-1 rounded-full bg-euca-100 px-2 py-0.5 text-xs font-semibold text-euca-800">{{ t('pharmacy.medications.prn') }}</span>
                            </p>
                            <p v-if="order.note" class="text-xs text-ink-muted">{{ order.note }}</p>
                        </div>
                        <div v-if="actions.can_prescribe" class="flex shrink-0 items-center gap-2">
                            <button type="button" class="rounded-full bg-white/50 px-3 py-1 text-xs font-semibold text-ink transition hover:bg-white/70" @click="transition(order, 'held')">{{ t('pharmacy.medications.hold') }}</button>
                            <button type="button" class="rounded-full bg-white/50 px-3 py-1 text-xs font-semibold text-ink transition hover:bg-white/70" @click="transition(order, 'discontinued')">{{ t('pharmacy.medications.discontinue') }}</button>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- History (held / discontinued / completed) -->
            <div v-if="discontinuedHistory.length" class="glass-card p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('pharmacy.medications.historyHeading') }}</h2>
                <ul class="mt-3 divide-y divide-euca-100">
                    <li v-for="order in discontinuedHistory" :key="order.id" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ order.name }} <span class="text-ink-muted">· {{ order.dose }} · {{ order.route }}</span></span>
                        <span class="flex items-center gap-2">
                            <span class="rounded-full bg-white/40 px-2.5 py-0.5 text-xs font-semibold text-ink-muted">{{ t(`pharmacy.medications.status.${order.status}`) }}</span>
                            <button v-if="actions.can_prescribe && order.status === 'held'" type="button" class="rounded-full bg-white/50 px-3 py-1 text-xs font-semibold text-ink transition hover:bg-white/70" @click="transition(order, 'active')">{{ t('pharmacy.medications.resume') }}</button>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
