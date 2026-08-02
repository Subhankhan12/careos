<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Imaging-study tracking (RAD.G3) — PRESENTATIONAL. Acquire a study for an imaging order (accession generated)
// + advance its legal-only state; view the append-only state history. The state + accession are FACTS — the
// screen records them; nothing computes an image finding or a priority (the fence). THE STUDY IS METADATA, NOT
// THE IMAGE — the DICOM image path is the seam-stubbed RAD.G6 (a certified partner), never a diagnostic viewer.
const { t, locale } = useI18n();

type StudyEventRow = { event_type: string; reason: string | null; occurred_at: string };
type StudyRow = {
    id: string;
    accession_number: string;
    modality: string | null;
    status: string;
    acquired_at: string | null;
    available_transitions: string[];
    transition_url: string;
    events: StudyEventRow[];
};

const props = defineProps<{
    order: { id: string; patient: string; exam: string | null; code: string | null; modality: string | null; body_part: string | null; priority: string; order_status: string | null };
    study: StudyRow | null;
    image: { available: boolean; note: string };
    actions: { can_acquire: boolean; acquire_url: string };
}>();

function fmt(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function acquire(): void {
    router.post(props.actions.acquire_url, {}, { preserveScroll: true });
}
function advance(status: string): void {
    if (!props.study) return;
    const reason = status === 'cancelled' ? (window.prompt(t('radiology.study.cancelReason')) ?? '') : null;
    router.post(props.study.transition_url, { status, reason }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('radiology.study.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('radiology.study.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ order.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ order.code }} — {{ order.exam }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-semibold text-euca-50">
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ t(`radiology.orders.priorityValue.${order.priority}`) }}</span>
                    <span v-if="order.modality" class="rounded-full bg-white/15 px-3 py-1">{{ order.modality }}<template v-if="order.body_part"> · {{ order.body_part }}</template></span>
                    <span v-if="order.order_status" class="rounded-full bg-white/15 px-3 py-1">{{ order.order_status }}</span>
                </div>
            </div>

            <div v-if="!study && actions.can_acquire" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.study.acquire') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ t('radiology.study.noStudy') }}</p>
                <button type="button" class="mt-3 rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700" @click="acquire">{{ t('radiology.study.acquireBtn') }}</button>
                <p class="mt-2 text-xs text-ink-muted">{{ t('radiology.study.acquireHint') }}</p>
            </div>

            <div v-if="study" class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm font-semibold text-ink">{{ study.accession_number }}</span>
                    <span class="rounded-full bg-ink/5 px-2.5 py-1 text-xs font-semibold text-ink">{{ t(`radiology.study.status.${study.status}`) }}</span>
                </div>
                <p class="mt-1 text-xs text-ink-subtle">{{ study.modality ?? '—' }}<template v-if="study.acquired_at"> · {{ fmt(study.acquired_at) }}</template></p>
                <div v-if="actions.can_acquire && study.available_transitions.length" class="mt-3 flex flex-wrap gap-2">
                    <button v-for="st in study.available_transitions" :key="st" type="button" class="rounded-full bg-euca-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-euca-700" @click="advance(st)">{{ t(`radiology.study.advanceTo.${st}`) }}</button>
                </div>
                <ol class="mt-3 space-y-1 border-t border-euca-50 pt-2 text-xs text-ink-subtle">
                    <li v-for="(e, i) in study.events" :key="i">{{ t(`radiology.study.status.${e.event_type}`) }} · {{ fmt(e.occurred_at) }}<template v-if="e.reason"> — {{ e.reason }}</template></li>
                </ol>
            </div>

            <!-- The image is the PACS partner's (RAD.G6) — a labelled seam note, never a diagnostic viewer. -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.study.imageTitle') }}</h2>
                <p class="mt-2 text-sm text-ink-muted">{{ t('radiology.study.imageSeamNote') }}</p>
            </div>
        </div>
    </AppLayout>
</template>
