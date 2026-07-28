<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// The ED tracking board (ED.G3) — the live cockpit of active ED visits + their flow state. PRESENTATIONAL
// over the G1 EdVisit flow + the G2 triage domain (P0D.GU): it renders service data and dispatches the
// EXISTING flow action (advance the visit — the G1 legal transitions); it computes no flow/acuity logic.
// Reuses the ward-board tile/status idiom for layout, over the ED flow state.
//
// ELECTRIC FENCE: OPERATIONAL FACTS + the RECORDED acuity only — the flow state, patient, presenting
// complaint, arrival/elapsed time (a plain fact), and the nurse-ASSIGNED acuity shown as a recorded value
// with its scale + provenance. Staff MAY sort by the recorded acuity (a FACT) or by arrival — but the board
// NEVER computes a priority ranking, an acuity-driven "next patient" judgment, or a wait-risk/deterioration.
// The flow COLOUR is the operational state, never a clinical severity.
const { t } = useI18n();

type Acuity = { scale: string; level: string; by: string | null };
type VisitTile = {
    id: string;
    patient: string;
    branch: string | null;
    status: string;
    arrival_mode: string;
    arrived_at: string;
    presenting_complaint: string;
    acuity: Acuity | null;
    available_transitions: string[];
    triage_url: string;
    record_url: string;
    disposition_url: string | null;
    transition_url: string | null;
};

const props = defineProps<{
    visits: VisitTile[];
    summary: { total: number; waiting: number; in_treatment: number; awaiting_disposition: number };
    statuses: string[];
    actions: { can_manage: boolean };
}>();

// Sort ONLY on recorded facts: arrival time, or the nurse's RECORDED acuity value. This orders by a recorded
// field — it is NOT a computed priority ranking (no score is derived; the acuity shown is the nurse's value).
const sortBy = ref<'arrival' | 'acuity'>('arrival');

const sortedVisits = computed<VisitTile[]>(() => {
    const list = [...props.visits];
    if (sortBy.value === 'acuity') {
        // Order by the RECORDED acuity value (a fact the nurse assigned); untriaged visits sort last. This is
        // ordering-by-a-recorded-field, not a computed judgment.
        return list.sort((a, b) => (a.acuity?.level ?? '~').localeCompare(b.acuity?.level ?? '~'));
    }
    return list.sort((a, b) => a.arrived_at.localeCompare(b.arrived_at));
});

// The flow state → an operational colour (NOT a clinical severity). Eucalyptus Glow tokens only.
function statusClass(status: string): string {
    return (
        {
            arrived: 'border-line bg-surface-2',
            triaged: 'border-euca-200 bg-euca-50',
            in_treatment: 'border-euca-400 bg-euca-100',
            awaiting_disposition: 'border-warning/40 bg-warning/10',
        }[status] ?? 'border-line bg-surface-2'
    );
}

function elapsed(iso: string): string {
    const mins = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    if (mins < 60) return t('ed.board.elapsedMin', { n: mins });
    return t('ed.board.elapsedHr', { h: Math.floor(mins / 60), m: mins % 60 });
}

function advance(tile: VisitTile, status: string): void {
    if (!tile.transition_url) return;
    router.post(tile.transition_url, { status }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('ed.board.title')" />
        <div class="mx-auto max-w-6xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('ed.board.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('ed.board.title') }}</h1>
                <!-- Plain department counts — facts, not a rating. -->
                <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold text-euca-50">
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ t('ed.board.inDept', { n: summary.total }) }}</span>
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ t('ed.board.waiting', { n: summary.waiting }) }}</span>
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ t('ed.board.inTreatment', { n: summary.in_treatment }) }}</span>
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ t('ed.board.awaiting', { n: summary.awaiting_disposition }) }}</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.board.sortBy') }}</span>
                <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-semibold transition"
                    :class="sortBy === 'arrival' ? 'bg-euca-600 text-white' : 'bg-surface-2 text-ink'"
                    @click="sortBy = 'arrival'"
                >
                    {{ t('ed.board.sortArrival') }}
                </button>
                <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-semibold transition"
                    :class="sortBy === 'acuity' ? 'bg-euca-600 text-white' : 'bg-surface-2 text-ink'"
                    @click="sortBy = 'acuity'"
                >
                    {{ t('ed.board.sortAcuity') }}
                </button>
            </div>

            <p v-if="!visits.length" class="glass-card p-6 text-sm text-ink-muted">{{ t('ed.board.empty') }}</p>

            <div v-else class="grid gap-4 sm:grid-cols-2">
                <div v-for="v in sortedVisits" :key="v.id" class="glass-card p-4" :class="statusClass(v.status)">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-sm font-semibold text-ink">{{ v.patient }}</p>
                            <p class="mt-0.5 text-xs text-ink-subtle">{{ v.presenting_complaint }}</p>
                        </div>
                        <!-- The RECORDED acuity — the nurse's assigned value + scale (a fact), not a computed rank. -->
                        <span v-if="v.acuity" class="rounded-full bg-ink/5 px-2.5 py-1 text-xs font-semibold text-ink">{{ v.acuity.scale }} {{ v.acuity.level }}</span>
                        <span v-else class="rounded-full bg-ink/5 px-2.5 py-1 text-xs text-ink-muted">{{ t('ed.board.untriaged') }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-ink-subtle">
                        <span class="rounded-full bg-white/60 px-2 py-0.5 font-semibold">{{ t(`ed.status.${v.status}`) }}</span>
                        <span>{{ elapsed(v.arrived_at) }}</span>
                        <span v-if="v.branch">· {{ v.branch }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button
                            v-for="s in v.available_transitions"
                            :key="s"
                            v-show="actions.can_manage && v.transition_url && s !== 'dispositioned'"
                            type="button"
                            class="rounded-full bg-euca-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-euca-700"
                            @click="advance(v, s)"
                        >
                            {{ t(`ed.board.advanceTo.${s}`) }}
                        </button>
                        <Link :href="v.triage_url" class="text-xs font-semibold text-euca-700 underline">{{ t('ed.board.openTriage') }}</Link>
                        <Link :href="v.record_url" class="text-xs font-semibold text-euca-700 underline">{{ t('ed.board.openRecord') }}</Link>
                        <Link v-if="v.disposition_url" :href="v.disposition_url" class="text-xs font-semibold text-euca-700 underline">{{ t('ed.board.dispose') }}</Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
