<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// eMAR (PHARMACY.G3) — PRESENTATIONAL. The due worklist (active orders) + the MAR (given/held/refused).
// RECORD-NOT-JUDGE: the outcome is the nurse's FACT; late/missed is a raw scheduled-vs-administered time
// comparison (no flag/grade). The alerts area is wired to the safety seam's SafetyResult and is EMPTY today.
const { t, locale } = useI18n();

type Due = { id: string; name: string; dose: string; route: string; frequency: string; prn: boolean; administer_url: string };
type Administration = { id: string; name: string; outcome: string; dose: string; scheduled_at: string | null; administered_at: string; reason: string | null };
type Alert = { code: string; message: string; source: string };

const props = defineProps<{
    patient: { id: string; name: string };
    due: Due[];
    history: Administration[];
    alerts: Alert[];
    outcomes: string[];
    actions: { can_administer: boolean };
}>();

type Form = { outcome: string; dose_amount: string; reason: string };
const forms = reactive<Record<string, Form>>({});

function formFor(id: string): Form {
    if (!forms[id]) {
        forms[id] = { outcome: 'given', dose_amount: '', reason: '' };
    }
    return forms[id];
}

function administer(order: Due): void {
    const f = formFor(order.id);
    router.post(order.administer_url, { outcome: f.outcome, dose_amount: f.dose_amount, reason: f.reason }, {
        preserveScroll: true,
        onSuccess: () => Object.assign(f, { outcome: 'given', dose_amount: '', reason: '' }),
    });
}

function fmtTime(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
}
</script>

<template>
    <AppLayout>
        <Head :title="t('pharmacy.emar.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('pharmacy.emar.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ patient.name }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('pharmacy.emar.subtitle') }}</p>
            </div>

            <!-- Safety alerts — wired to the seam's SafetyResult; EMPTY today (renders nothing without alerts). -->
            <div v-if="alerts.length" class="glass-card border-l-4 border-amber-400 p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('pharmacy.emar.alerts') }}</h2>
                <ul class="mt-3 space-y-2">
                    <li v-for="alert in alerts" :key="alert.code" class="text-sm text-ink">{{ alert.message }} <span class="text-ink-muted">({{ alert.source }})</span></li>
                </ul>
            </div>

            <!-- Due worklist: active orders, each recordable given/held/refused. -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('pharmacy.emar.dueHeading') }}</h2>
                <p v-if="due.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('pharmacy.emar.noneDue') }}</p>
                <ul v-else class="mt-4 space-y-3">
                    <li v-for="order in due" :key="order.id" class="rounded-xl bg-euca-50/60 p-4">
                        <p class="text-sm font-semibold text-ink">
                            {{ order.name }} <span class="text-ink-muted">· {{ order.dose }} · {{ order.route }} · {{ order.frequency }}</span>
                            <span v-if="order.prn" class="ml-1 rounded-full bg-euca-100 px-2 py-0.5 text-xs font-semibold text-euca-800">{{ t('pharmacy.emar.prn') }}</span>
                        </p>
                        <div v-if="actions.can_administer" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                            <select v-model="formFor(order.id).outcome" class="rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                                <option v-for="o in outcomes" :key="o" :value="o">{{ t(`pharmacy.emar.outcomes.${o}`) }}</option>
                            </select>
                            <input v-model="formFor(order.id).dose_amount" :placeholder="t('pharmacy.emar.doseGivenPlaceholder', { dose: order.dose })" class="rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                            <input v-if="formFor(order.id).outcome !== 'given'" v-model="formFor(order.id).reason" :placeholder="t('pharmacy.emar.reasonPlaceholder')" class="flex-1 rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                            <button type="button" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700" @click="administer(order)">{{ t('pharmacy.emar.record') }}</button>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- The MAR: every administration, newest first. Times shown as raw facts (no late/missed flag). -->
            <div class="glass-card p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('pharmacy.emar.marHeading') }}</h2>
                <p v-if="history.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('pharmacy.emar.noneRecorded') }}</p>
                <ul v-else class="mt-3 divide-y divide-euca-100">
                    <li v-for="a in history" :key="a.id" class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
                        <span class="text-sm text-ink">
                            {{ a.name }}
                            <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-semibold" :class="a.outcome === 'given' ? 'bg-euca-100 text-euca-800' : 'bg-white/50 text-ink-muted'">{{ t(`pharmacy.emar.outcomes.${a.outcome}`) }}</span>
                            <span v-if="a.dose.trim()" class="text-ink-muted"> · {{ a.dose }}</span>
                            <span v-if="a.reason" class="text-ink-muted"> · {{ a.reason }}</span>
                        </span>
                        <span class="text-xs text-ink-muted">
                            {{ fmtTime(a.administered_at) }}<template v-if="a.scheduled_at"> · {{ t('pharmacy.emar.scheduled', { at: fmtTime(a.scheduled_at) }) }}</template>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
