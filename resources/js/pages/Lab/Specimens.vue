<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Specimen tracking (LAB.G3) — PRESENTATIONAL. Collect a specimen for a lab order (accession generated) +
// advance its legal-only state; view the append-only state history. The state + accession are operational
// FACTS — the screen records them; nothing computes a priority or auto-routes (the electric fence).
const { t, locale } = useI18n();

type SpecimenEventRow = { event_type: string; reason: string | null; occurred_at: string };
type SpecimenRow = {
    id: string;
    accession_number: string;
    specimen_type: string | null;
    container_type: string | null;
    status: string;
    collected_at: string;
    available_transitions: string[];
    transition_url: string;
    events: SpecimenEventRow[];
};

const props = defineProps<{
    labOrder: { id: string; patient: string; test: string | null; code: string | null; specimen_type: string | null; priority: string; order_status: string | null };
    specimens: SpecimenRow[];
    options: { statuses: string[] };
    actions: { can_collect: boolean; collect_url: string };
}>();

const collectForm = reactive({ container_type: '', collection_note: '' });

function fmt(iso: string): string {
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function collect(): void {
    router.post(props.actions.collect_url, { ...collectForm }, { preserveScroll: true, onSuccess: () => { collectForm.container_type = ''; collectForm.collection_note = ''; } });
}
function advance(s: SpecimenRow, status: string): void {
    const reason = status === 'rejected' ? (window.prompt(t('lab.specimens.rejectReason')) ?? '') : null;
    router.post(s.transition_url, { status, reason }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('lab.specimens.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('lab.specimens.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ labOrder.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ labOrder.code }} — {{ labOrder.test }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-semibold text-euca-50">
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ t(`lab.orders.priorityValue.${labOrder.priority}`) }}</span>
                    <span v-if="labOrder.order_status" class="rounded-full bg-white/15 px-3 py-1">{{ labOrder.order_status }}</span>
                </div>
            </div>

            <div v-if="actions.can_collect" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('lab.specimens.collect') }}</h2>
                <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="collect">
                    <input v-model="collectForm.container_type" type="text" maxlength="60" :placeholder="t('lab.specimens.container')" class="w-40 rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <input v-model="collectForm.collection_note" type="text" maxlength="500" :placeholder="t('lab.specimens.note')" class="flex-1 rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <button type="submit" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('lab.specimens.collectBtn') }}</button>
                </form>
                <p class="mt-2 text-xs text-ink-muted">{{ t('lab.specimens.accessionHint') }}</p>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('lab.specimens.tracked') }}</h2>
                <p v-if="!specimens.length" class="mt-3 text-sm text-ink-muted">{{ t('lab.specimens.empty') }}</p>
                <ul v-else class="mt-4 space-y-4">
                    <li v-for="s in specimens" :key="s.id" class="rounded-lg border border-euca-100 p-4">
                        <div class="flex items-center justify-between">
                            <span class="font-mono text-sm font-semibold text-ink">{{ s.accession_number }}</span>
                            <span class="rounded-full bg-ink/5 px-2.5 py-1 text-xs font-semibold text-ink">{{ t(`lab.specimens.status.${s.status}`) }}</span>
                        </div>
                        <p class="mt-1 text-xs text-ink-subtle">{{ s.specimen_type ?? '—' }}<template v-if="s.container_type"> · {{ s.container_type }}</template> · {{ fmt(s.collected_at) }}</p>
                        <div v-if="actions.can_collect && s.available_transitions.length" class="mt-3 flex flex-wrap gap-2">
                            <button v-for="st in s.available_transitions" :key="st" type="button" class="rounded-full bg-euca-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-euca-700" @click="advance(s, st)">{{ t(`lab.specimens.advanceTo.${st}`) }}</button>
                        </div>
                        <ol class="mt-3 space-y-1 border-t border-euca-50 pt-2 text-xs text-ink-subtle">
                            <li v-for="(e, i) in s.events" :key="i">{{ t(`lab.specimens.status.${e.event_type}`) }} · {{ fmt(e.occurred_at) }}<template v-if="e.reason"> — {{ e.reason }}</template></li>
                        </ol>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
