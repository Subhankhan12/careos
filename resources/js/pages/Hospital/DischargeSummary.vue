<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Discharge summary + closed-episode view (HOSPITAL.G7) — the Phase-1 close-out. PRESENTATIONAL: it shows
// the derived LOS (a fact), the sign-and-lock summary (draft editor OR finalized read-only), and the stay's
// EXISTING records (ADT timeline, rounds, handovers, invoices) read-only. No acuity/score/grade is computed.
const { t, locale } = useI18n();

type JourneyEvent = { id: string; event_type: string; reason: string | null; disposition: string | null; occurred_at: string };
type Round = { id: string; at: string | null };
type HandoverRow = { id: string; shift: string; situation: string; handed_over_at: string };
type InvoiceRow = { id: string; series: string; number: string | null; status: string; total_minor: number; issue_date: string | null };

const props = defineProps<{
    stay: {
        id: string;
        patient: string;
        status: string;
        admission_type: string;
        admission_reason: string | null;
        admitting_clinician: string;
        bed: string | null;
        ward: string | null;
        admitted_at: string | null;
        discharged_at: string | null;
        discharge_disposition: string | null;
        los_minutes: number | null;
    };
    summary: {
        id: string;
        status: string;
        summary: string;
        instructions: string | null;
        is_finalized: boolean;
        finalized_at: string | null;
    } | null;
    episode: {
        journey: JourneyEvent[];
        rounds: Round[];
        handovers: HandoverRow[];
        invoices: InvoiceRow[];
    };
    actions: { can_author: boolean; can_finalize: boolean; save_url: string; finalize_url: string };
}>();

const form = reactive({
    summary: props.summary?.summary ?? '',
    instructions: props.summary?.instructions ?? '',
});

// LOS = plain elapsed time (a fact), rendered from the derived minutes. No judgment/grade.
const losText = computed<string | null>(() => {
    const mins = props.stay.los_minutes;
    if (mins === null) {
        return null;
    }
    const days = Math.floor(mins / 1440);
    const hours = Math.floor((mins % 1440) / 60);
    if (days > 0) return t('hospital.board.losDaysHours', { days, hours });
    if (hours > 0) return t('hospital.board.losHours', { hours });
    return t('hospital.board.losMinutes', { minutes: mins });
});

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}

function fmtDay(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(iso));
}

function fmtAmount(minor: number): string {
    return (minor / 100).toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function saveDraft(): void {
    router.post(props.actions.save_url, { ...form }, { preserveScroll: true });
}

function finalize(): void {
    router.post(props.actions.finalize_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('hospital.dischargeSummary.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-start">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('hospital.dischargeSummary.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ stay.patient }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ stay.ward ?? '—' }} · {{ stay.bed ?? '—' }}</p>
                </div>
                <dl class="grid gap-x-6 gap-y-1 text-sm text-euca-100 sm:text-right">
                    <div class="sm:flex sm:justify-end sm:gap-2">
                        <dt class="text-euca-300">{{ t('hospital.dischargeSummary.los') }}:</dt>
                        <dd class="font-semibold text-euca-50">{{ losText ?? t('hospital.dischargeSummary.stillAdmitted') }}</dd>
                    </div>
                    <div v-if="stay.discharge_disposition" class="sm:flex sm:justify-end sm:gap-2">
                        <dt class="text-euca-300">{{ t('hospital.dischargeSummary.disposition') }}:</dt>
                        <dd class="font-semibold text-euca-50">{{ stay.discharge_disposition }}</dd>
                    </div>
                </dl>
            </div>

            <!-- The discharge summary: finalized read-only, or a draft editor. -->
            <div class="glass-card p-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('hospital.dischargeSummary.heading') }}</h2>
                    <span
                        v-if="summary"
                        class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold"
                        :class="summary.is_finalized ? 'bg-euca-600 text-white' : 'bg-euca-100 text-euca-800'"
                    >
                        {{ summary.is_finalized ? t('hospital.dischargeSummary.finalizedBadge') : t('hospital.dischargeSummary.draftBadge') }}
                    </span>
                </div>

                <!-- Finalized: immutable, read-only. -->
                <div v-if="summary && summary.is_finalized" class="mt-4 space-y-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.dischargeSummary.summaryLabel') }}</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-ink">{{ summary.summary }}</p>
                    </div>
                    <div v-if="summary.instructions">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.dischargeSummary.instructionsLabel') }}</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-ink">{{ summary.instructions }}</p>
                    </div>
                    <p class="text-xs text-ink-muted">{{ t('hospital.dischargeSummary.finalizedAt', { at: fmtDate(summary.finalized_at) }) }}</p>
                </div>

                <!-- Draft / not-yet-authored: the editor (when the actor may author). -->
                <div v-else-if="actions.can_author" class="mt-4 space-y-4">
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="ds-summary">{{ t('hospital.dischargeSummary.summaryLabel') }}</label>
                        <textarea
                            id="ds-summary"
                            v-model="form.summary"
                            rows="6"
                            class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none"
                            :placeholder="t('hospital.dischargeSummary.summaryPlaceholder')"
                        ></textarea>
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="ds-instructions">{{ t('hospital.dischargeSummary.instructionsLabel') }}</label>
                        <textarea
                            id="ds-instructions"
                            v-model="form.instructions"
                            rows="4"
                            class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none"
                            :placeholder="t('hospital.dischargeSummary.instructionsPlaceholder')"
                        ></textarea>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="rounded-full bg-euca-100 px-4 py-2 text-sm font-semibold text-euca-800 transition hover:bg-euca-200"
                            @click="saveDraft"
                        >
                            {{ t('hospital.dischargeSummary.saveDraft') }}
                        </button>
                        <button
                            v-if="actions.can_finalize"
                            type="button"
                            class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700"
                            @click="finalize"
                        >
                            {{ t('hospital.dischargeSummary.finalize') }}
                        </button>
                    </div>
                </div>

                <!-- No summary and cannot author. -->
                <p v-else class="mt-4 text-sm text-ink-muted">{{ t('hospital.dischargeSummary.empty') }}</p>
            </div>

            <!-- The closed episode: the stay's existing records, referenced read-only. -->
            <div class="grid gap-5 lg:grid-cols-2">
                <!-- ADT timeline -->
                <div class="glass-card p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.dischargeSummary.timeline') }}</h3>
                    <ol class="mt-3 space-y-2">
                        <li v-for="event in episode.journey" :key="event.id" class="text-sm text-ink">
                            <span class="font-medium">{{ t(`hospital.admission.events.${event.event_type}`) }}</span>
                            <span class="text-ink-muted"> · {{ fmtDate(event.occurred_at) }}</span>
                        </li>
                    </ol>
                </div>

                <!-- Ward rounds -->
                <div class="glass-card p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.dischargeSummary.rounds') }}</h3>
                    <p v-if="episode.rounds.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('hospital.dischargeSummary.noRounds') }}</p>
                    <ol v-else class="mt-3 space-y-2">
                        <li v-for="round in episode.rounds" :key="round.id" class="text-sm text-ink-muted">{{ fmtDate(round.at) }}</li>
                    </ol>
                </div>

                <!-- Handovers -->
                <div class="glass-card p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.dischargeSummary.handovers') }}</h3>
                    <p v-if="episode.handovers.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('hospital.dischargeSummary.noHandovers') }}</p>
                    <ol v-else class="mt-3 space-y-2">
                        <li v-for="h in episode.handovers" :key="h.id" class="text-sm text-ink">
                            <span class="font-medium">{{ t(`hospital.handover.shift.${h.shift}`) }}</span>
                            <span class="text-ink-muted"> · {{ fmtDate(h.handed_over_at) }}</span>
                        </li>
                    </ol>
                </div>

                <!-- Invoices (G6) -->
                <div class="glass-card p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.dischargeSummary.invoices') }}</h3>
                    <p v-if="episode.invoices.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('hospital.dischargeSummary.noInvoices') }}</p>
                    <ol v-else class="mt-3 space-y-2">
                        <li v-for="inv in episode.invoices" :key="inv.id" class="flex items-center justify-between text-sm text-ink">
                            <span>{{ inv.series }}-{{ inv.number ?? '—' }} · {{ fmtDay(inv.issue_date) }}</span>
                            <span class="font-semibold">{{ fmtAmount(inv.total_minor) }}</span>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
