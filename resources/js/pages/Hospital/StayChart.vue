<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Bedside charting for an inpatient stay (HOSPITAL.G4) — PRESENTATIONAL over the REUSED Clinical
// module (P0D.GU): the stay's ward rounds (reused Encounters, each linking to the EXISTING
// sign-and-lock note editor), the RAW vitals recorded over the stay, and its orders. Starting a
// round redirects into the existing note editor. ELECTRIC FENCE: vitals are raw values over time
// — no bands/flags/scores, no computed acuity/early-warning — the existing vitals discipline.
const { t, locale } = useI18n();

type NoteRef = { id: string; status: string; edit_url: string };
type Round = { id: string; practitioner: string | null; started_at: string | null; encounter_status: string | null; note: NoteRef | null };
type OrderRow = { id: string; code: string | null; name: string | null; priority: string; status: string; ordered_at: string | null; result_count: number };
type VitalPoint = { recorded_at: string; value: unknown; source: string };

const props = defineProps<{
    stay: { id: string; patient: string; status: string; bed: string | null; ward: string | null };
    rounds: Round[];
    vitals: { metrics: Record<string, VitalPoint[]> };
    orders: OrderRow[];
    actions: {
        can_chart: boolean;
        can_order: boolean;
        start_round_url: string;
        record_vital_url: string;
        place_order_url: string;
        orderable_items: Array<{ id: string; code: string | null; name: string | null }>;
    };
}>();

const METRICS = ['systolic', 'diastolic', 'heart_rate', 'temperature_c', 'spo2', 'weight_g', 'height_mm'] as const;
const presentMetrics = computed(() => METRICS.filter((m) => (props.vitals.metrics[m]?.length ?? 0) > 0));

const panel = ref<'vital' | 'order' | null>(null);
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

function startRound(): void {
    router.post(props.actions.start_round_url, {}, { preserveScroll: true });
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
        <Head :title="t('hospital.chart.title')" />
        <div class="space-y-5">
            <!-- Header -->
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('hospital.chart.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ stay.patient }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ stay.ward ?? '—' }} · {{ stay.bed ?? '—' }}</p>
                </div>
                <button v-if="actions.can_chart" type="button" class="btn-glow self-start rounded-xl px-4 py-2 text-sm font-semibold" @click="startRound">
                    {{ t('hospital.chart.startRound') }}
                </button>
            </div>

            <!-- Ward rounds (reused Encounters + the existing note editor) -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('hospital.chart.rounds') }}</h2>
                <p v-if="rounds.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('hospital.chart.noRounds') }}</p>
                <ul v-else class="mt-3 divide-y divide-line/70">
                    <li v-for="round in rounds" :key="round.id" class="flex items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-ink">{{ round.practitioner ?? '—' }}</p>
                            <p class="text-xs text-ink-muted">{{ fmt(round.started_at) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="round.note" class="rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">
                                {{ t(`hospital.chart.noteStatus.${round.note.status}`) }}
                            </span>
                            <Link v-if="round.note" :href="round.note.edit_url" class="text-sm font-medium text-euca-700 transition hover:text-euca-800">
                                {{ t('hospital.chart.openNote') }} →
                            </Link>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Vitals over the stay — RAW values, no bands/scores (electric fence) -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('hospital.chart.vitals') }}</h2>
                    <button v-if="actions.can_chart" type="button" class="text-sm font-medium text-euca-700 hover:text-euca-800" @click="panel = panel === 'vital' ? null : 'vital'">
                        {{ t('hospital.chart.recordVital') }}
                    </button>
                </div>

                <div v-if="panel === 'vital'" class="mt-3 grid gap-2 rounded-xl bg-euca-50/60 p-3 sm:grid-cols-3 lg:grid-cols-6">
                    <input v-model="vital.systolic" type="number" :placeholder="t('hospital.chart.systolic')" class="rounded-lg border border-line bg-surface px-2 py-1.5 text-xs" />
                    <input v-model="vital.diastolic" type="number" :placeholder="t('hospital.chart.diastolic')" class="rounded-lg border border-line bg-surface px-2 py-1.5 text-xs" />
                    <input v-model="vital.heart_rate" type="number" :placeholder="t('hospital.chart.heartRate')" class="rounded-lg border border-line bg-surface px-2 py-1.5 text-xs" />
                    <input v-model="vital.temperature_c" type="number" step="0.1" :placeholder="t('hospital.chart.temperature')" class="rounded-lg border border-line bg-surface px-2 py-1.5 text-xs" />
                    <input v-model="vital.spo2" type="number" :placeholder="t('hospital.chart.spo2')" class="rounded-lg border border-line bg-surface px-2 py-1.5 text-xs" />
                    <button type="button" class="btn-glow rounded-lg px-3 py-1.5 text-xs font-semibold" @click="submitVital">{{ t('hospital.chart.save') }}</button>
                </div>

                <p v-if="presentMetrics.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('hospital.chart.noVitals') }}</p>
                <div v-else class="mt-3 space-y-2">
                    <div v-for="metric in presentMetrics" :key="metric" class="flex items-baseline gap-3">
                        <span class="w-28 shrink-0 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t(`hospital.chart.metric.${metric}`) }}</span>
                        <span class="flex flex-wrap gap-2 text-sm tabular-nums text-ink">
                            <span v-for="(point, i) in vitals.metrics[metric]" :key="i" class="rounded-md bg-surface-2 px-2 py-0.5">{{ point.value }}</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Orders (reused structured orders) -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('hospital.chart.orders') }}</h2>
                    <button v-if="actions.can_order" type="button" class="text-sm font-medium text-euca-700 hover:text-euca-800" @click="panel = panel === 'order' ? null : 'order'">
                        {{ t('hospital.chart.placeOrder') }}
                    </button>
                </div>

                <div v-if="panel === 'order'" class="mt-3 flex flex-wrap gap-2 rounded-xl bg-euca-50/60 p-3">
                    <select v-model="order.orderable_item_id" class="grow rounded-lg border border-line bg-surface px-2 py-1.5 text-xs">
                        <option value="">{{ t('hospital.chart.selectItem') }}</option>
                        <option v-for="item in actions.orderable_items" :key="item.id" :value="item.id">{{ item.code }} — {{ item.name }}</option>
                    </select>
                    <button type="button" class="btn-glow rounded-lg px-3 py-1.5 text-xs font-semibold" @click="submitOrder">{{ t('hospital.chart.save') }}</button>
                </div>

                <p v-if="orders.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('hospital.chart.noOrders') }}</p>
                <ul v-else class="mt-3 divide-y divide-line/70">
                    <li v-for="o in orders" :key="o.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0"><span class="font-mono text-ink">{{ o.code }}</span> <span class="text-ink-muted">{{ o.name }}</span></span>
                        <span class="rounded-full bg-surface-2 px-2.5 py-0.5 text-xs font-semibold text-ink-muted">{{ o.status }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
