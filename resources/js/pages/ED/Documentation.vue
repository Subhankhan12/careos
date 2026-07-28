<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// ED clinical documentation (ED.G4) — the visit's clinical record: its treatment encounters (reused Clinical
// Encounters + the sign-and-lock note editor), the RAW vitals recorded during the visit, and its orders.
// PRESENTATIONAL over the REUSED Clinical module (P0D.GU): all logic lives in EdDocumentationService + the
// Clinical services; this only renders + dispatches. Starting an encounter redirects into the EXISTING note
// editor. ELECTRIC FENCE: vitals are raw values over time — no bands/flags/scores, no computed acuity/
// severity/deterioration; the recorded triage acuity is G2's nurse-ASSIGNED value (shown on the triage page).
const { t, locale } = useI18n();

type Note = { id: string; status: string; edit_url: string };
type EncounterRow = { id: string; practitioner: string | null; started_at: string | null; encounter_status: string | null; note: Note | null };
type VitalPoint = { recorded_at: string; value: string | number };
type OrderRow = { id: string; code: string | null; name: string | null; priority: string; status: string; ordered_at: string; result_count: number };

const props = defineProps<{
    visit: { id: string; patient: string; status: string; chief_complaint: string; triage_url: string };
    encounters: EncounterRow[];
    vitals: { metrics: Record<string, VitalPoint[]> };
    orders: OrderRow[];
    actions: {
        can_chart: boolean;
        can_order: boolean;
        start_encounter_url: string;
        record_vital_url: string;
        place_order_url: string;
        clinicians: Array<{ id: string; name: string }>;
        orderable_items: Array<{ id: string; code: string | null; name: string | null }>;
    };
}>();

const METRICS = ['systolic', 'diastolic', 'heart_rate', 'temperature_c', 'spo2', 'weight_g', 'height_mm'] as const;
const presentMetrics = computed(() => METRICS.filter((m) => (props.vitals.metrics[m]?.length ?? 0) > 0));

const panel = ref<'encounter' | 'vital' | 'order' | null>(null);
const encounterForm = reactive({ practitioner_id: props.actions.clinicians[0]?.id ?? '', reason: '' });
const vital = reactive({ systolic: '', diastolic: '', heart_rate: '', temperature_c: '', spo2: '' });
const order = reactive({ orderable_item_id: '', priority: 'routine', clinical_note: '' });

function fmt(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function startEncounter(): void {
    router.post(props.actions.start_encounter_url, { ...encounterForm }, { preserveScroll: true });
}
function submitVital(): void {
    router.post(props.actions.record_vital_url, { ...vital }, { preserveScroll: true, onSuccess: () => { panel.value = null; } });
}
function submitOrder(): void {
    router.post(props.actions.place_order_url, { ...order }, { preserveScroll: true, onSuccess: () => { panel.value = null; } });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('ed.record.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('ed.record.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ visit.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ visit.chief_complaint }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <span class="inline-block rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-euca-50">{{ t(`ed.status.${visit.status}`) }}</span>
                    <Link :href="visit.triage_url" class="text-xs font-semibold text-euca-100 underline">{{ t('ed.record.openTriage') }}</Link>
                </div>
            </div>

            <!-- Treatment encounters — reused Clinical Encounters + the sign-and-lock note editor. -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.record.encounters') }}</h2>
                    <button v-if="actions.can_chart" type="button" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700" @click="panel = panel === 'encounter' ? null : 'encounter'">{{ t('ed.record.startEncounter') }}</button>
                </div>
                <form v-if="panel === 'encounter'" class="mt-4 space-y-3" @submit.prevent="startEncounter">
                    <select v-model="encounterForm.practitioner_id" class="w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                        <option v-for="c in actions.clinicians" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <input v-model="encounterForm.reason" type="text" maxlength="500" :placeholder="t('ed.record.reason')" class="w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm" />
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white">{{ t('ed.record.openEditor') }}</button>
                </form>
                <p v-if="!encounters.length" class="mt-3 text-sm text-ink-muted">{{ t('ed.record.noEncounters') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-50">
                    <li v-for="e in encounters" :key="e.id" class="flex items-center justify-between gap-3 py-3">
                        <div>
                            <p class="text-sm font-semibold text-ink">{{ e.practitioner ?? '—' }}</p>
                            <p class="text-xs text-ink-subtle">{{ fmt(e.started_at) }} · {{ e.encounter_status }}</p>
                        </div>
                        <Link v-if="e.note" :href="e.note.edit_url" class="rounded-full bg-surface-2 px-3 py-1 text-xs font-semibold text-ink">{{ t(`ed.record.note.${e.note.status}`) }}</Link>
                    </li>
                </ul>
            </div>

            <!-- RAW vitals over the visit — no bands/flags/scores (the electric fence). -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.record.vitals') }}</h2>
                    <button v-if="actions.can_chart" type="button" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700" @click="panel = panel === 'vital' ? null : 'vital'">{{ t('ed.record.recordVital') }}</button>
                </div>
                <form v-if="panel === 'vital'" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5" @submit.prevent="submitVital">
                    <input v-model="vital.systolic" type="number" :placeholder="t('ed.triage.systolic')" class="rounded border border-euca-200 px-2 py-1 text-sm" />
                    <input v-model="vital.diastolic" type="number" :placeholder="t('ed.triage.diastolic')" class="rounded border border-euca-200 px-2 py-1 text-sm" />
                    <input v-model="vital.heart_rate" type="number" :placeholder="t('ed.triage.heartRate')" class="rounded border border-euca-200 px-2 py-1 text-sm" />
                    <input v-model="vital.temperature_c" type="number" step="0.1" :placeholder="t('ed.triage.temperature')" class="rounded border border-euca-200 px-2 py-1 text-sm" />
                    <input v-model="vital.spo2" type="number" :placeholder="t('ed.triage.spo2')" class="rounded border border-euca-200 px-2 py-1 text-sm" />
                    <button type="submit" class="col-span-2 rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white sm:col-span-5">{{ t('ed.triage.save') }}</button>
                </form>
                <p v-if="!presentMetrics.length" class="mt-3 text-sm text-ink-muted">{{ t('ed.record.noVitals') }}</p>
                <div v-else class="mt-4 space-y-2">
                    <div v-for="metric in presentMetrics" :key="metric" class="flex items-baseline gap-3">
                        <span class="w-28 text-xs uppercase tracking-wide text-ink-subtle">{{ t(`ed.record.metric.${metric}`) }}</span>
                        <div class="flex flex-wrap gap-1 text-sm text-ink">
                            <span v-for="(point, i) in vitals.metrics[metric]" :key="i" class="rounded-md bg-surface-2 px-2 py-0.5">{{ point.value }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Orders — reused structured Order + append-only OrderResult. -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.record.orders') }}</h2>
                    <button v-if="actions.can_order" type="button" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700" @click="panel = panel === 'order' ? null : 'order'">{{ t('ed.record.placeOrder') }}</button>
                </div>
                <form v-if="panel === 'order'" class="mt-4 space-y-3" @submit.prevent="submitOrder">
                    <select v-model="order.orderable_item_id" class="w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                        <option value="" disabled>{{ t('ed.record.selectOrder') }}</option>
                        <option v-for="item in actions.orderable_items" :key="item.id" :value="item.id">{{ item.code }} — {{ item.name }}</option>
                    </select>
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white">{{ t('ed.record.placeOrder') }}</button>
                </form>
                <p v-if="!orders.length" class="mt-3 text-sm text-ink-muted">{{ t('ed.record.noOrders') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-50">
                    <li v-for="o in orders" :key="o.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="text-ink">{{ o.code }} — {{ o.name }}</span>
                        <span class="text-xs text-ink-subtle">{{ o.priority }} · {{ o.status }} · {{ fmt(o.ordered_at) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
