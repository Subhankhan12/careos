<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Inpatient admission detail (HOSPITAL.G2) — PRESENTATIONAL read view of one Stay + its
// append-only bed journey. The rich ward board (all wards/beds) is HOSPITAL.G3. Facts only:
// bed/ward/route/disposition — no acuity/severity/score is computed or rendered (electric fence).
const { t } = useI18n();

type JourneyEvent = {
    id: string;
    event_type: string;
    bed_id: string | null;
    from_bed_id: string | null;
    reason: string | null;
    disposition: string | null;
    occurred_at: string | null;
};

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
    journey: JourneyEvent[];
    actions: {
        can_manage: boolean;
        transfer_url: string;
        discharge_url: string;
        can_invoice: boolean;
        invoice_url: string;
        summary_url: string;
    };
}>();

const isActive = computed(() => props.stay.status === 'admitted');

// Length-of-stay = plain elapsed time (a fact), rendered from the derived minutes. No judgment/grade.
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

function invoiceStay(): void {
    router.post(props.actions.invoice_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('hospital.admission.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('hospital.admission.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ stay.patient }}</h1>
                    <p class="mt-1 text-sm text-euca-200">
                        {{ t('hospital.admission.bed') }}: {{ stay.ward ?? '—' }} · {{ stay.bed ?? '—' }}
                    </p>
                </div>
                <div class="flex flex-col items-start gap-2 sm:items-end">
                    <span
                        class="inline-flex items-center gap-1.5 self-start rounded-full px-3 py-1 text-xs font-semibold"
                        :class="isActive ? 'bg-euca-400 text-euca-900' : 'bg-white/15 text-euca-50'"
                    >
                        {{ t(`hospital.admission.status.${stay.status}`) }}
                    </span>
                    <button
                        v-if="actions.can_invoice"
                        type="button"
                        class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-euca-50 transition hover:bg-white/25"
                        @click="invoiceStay"
                    >
                        {{ t('hospital.admission.invoiceStay') }}
                    </button>
                    <Link
                        :href="actions.summary_url"
                        class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-euca-50 transition hover:bg-white/25"
                    >
                        {{ t('hospital.admission.dischargeSummary') }}
                    </Link>
                </div>
            </div>

            <!-- Summary -->
            <div class="glass-card grid gap-4 p-6 sm:grid-cols-2 xl:grid-cols-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.admission.type') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink">{{ t(`hospital.admission.types.${stay.admission_type}`) }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.admission.clinician') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink">{{ stay.admitting_clinician || '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.admission.admittedAt') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink">{{ stay.admitted_at ?? '—' }}</p>
                </div>
                <div v-if="stay.discharged_at">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.admission.dischargedAt') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink">{{ stay.discharged_at }}</p>
                </div>
                <div v-if="stay.discharge_disposition">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.admission.disposition') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink">{{ stay.discharge_disposition }}</p>
                </div>
                <div v-if="losText">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.admission.los') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink">{{ losText }}</p>
                </div>
                <div v-if="stay.admission_reason">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('hospital.admission.reason') }}</p>
                    <p class="mt-1 text-sm font-medium text-ink">{{ stay.admission_reason }}</p>
                </div>
            </div>

            <!-- Append-only bed journey -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('hospital.admission.journey') }}</h2>
                <ol class="mt-4 space-y-3">
                    <li v-for="event in journey" :key="event.id" class="flex items-start gap-3 rounded-xl bg-euca-50/60 px-4 py-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-euca-200 text-xs font-semibold text-euca-900">•</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-ink">{{ t(`hospital.admission.events.${event.event_type}`) }}</span>
                            <span class="block text-xs text-ink-muted">{{ event.occurred_at }}</span>
                            <span v-if="event.reason" class="mt-0.5 block text-xs text-ink-muted">{{ event.reason }}</span>
                        </span>
                    </li>
                </ol>
            </div>
        </div>
    </AppLayout>
</template>
