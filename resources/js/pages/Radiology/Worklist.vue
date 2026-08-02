<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// The modality worklist (RAD.G3) — PRESENTATIONAL. The radiographer's "studies to acquire": imaging orders
// awaiting acquisition, shown as FACTS (patient, exam, modality, body-part, the recorded priority, ordered-time).
// Reuses the board/lab-review idiom. THE FENCE: no computed priority ranking — staff MAY sort by the recorded
// flag or time (a fact). No image here — the DICOM path is the seam-stubbed RAD.G6.
const { t, locale } = useI18n();

type WorklistRow = {
    radiology_order_id: string;
    patient: string | null;
    exam: string | null;
    code: string | null;
    modality: string | null;
    body_part: string | null;
    priority: string;
    ordered_at: string | null;
    status: string;
    acquire_url: string;
    study_url: string;
};

const props = defineProps<{
    studies: WorklistRow[];
    actions: { can_acquire: boolean };
}>();

// Client-side sort by a RECORDED FACT only — ordered-time (default) or the recorded priority flag. Never a
// computed priority. The flag rank is a fixed presentation order of the recorded flag, not a computed urgency.
const sortKey = ref<'ordered' | 'priority'>('ordered');
const flagRank: Record<string, number> = { stat: 0, urgent: 1, routine: 2 };

const sorted = computed<WorklistRow[]>(() => {
    const rows = [...props.studies];
    if (sortKey.value === 'priority') {
        rows.sort((a, b) => (flagRank[a.priority] ?? 9) - (flagRank[b.priority] ?? 9));
    } else {
        rows.sort((a, b) => (a.ordered_at ?? '').localeCompare(b.ordered_at ?? ''));
    }
    return rows;
});

function fmt(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function acquire(row: WorklistRow): void {
    router.post(row.acquire_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('radiology.worklist.title')" />
        <div class="mx-auto max-w-5xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('radiology.worklist.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('radiology.worklist.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('radiology.worklist.subtitle') }}</p>
            </div>

            <div class="glass-card p-6">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="rounded-full px-3 py-1 text-xs font-semibold" :class="sortKey === 'ordered' ? 'bg-euca-600 text-white' : 'bg-ink/5 text-ink'" @click="sortKey = 'ordered'">{{ t('radiology.worklist.orderedAt') }}</button>
                    <button type="button" class="rounded-full px-3 py-1 text-xs font-semibold" :class="sortKey === 'priority' ? 'bg-euca-600 text-white' : 'bg-ink/5 text-ink'" @click="sortKey = 'priority'">{{ t('radiology.worklist.priority') }}</button>
                </div>

                <p v-if="!sorted.length" class="mt-4 text-sm text-ink-muted">{{ t('radiology.worklist.empty') }}</p>
                <ul v-else class="mt-4 space-y-3">
                    <li v-for="s in sorted" :key="s.radiology_order_id" class="rounded-lg border border-euca-100 p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <div>
                                <span class="font-semibold text-ink">{{ s.patient ?? '—' }}</span>
                                <span class="ml-2 text-sm text-ink-subtle">{{ s.code }} — {{ s.exam }}</span>
                            </div>
                            <span class="rounded-full bg-ink/5 px-2.5 py-1 text-xs font-semibold" :class="s.priority === 'stat' ? 'text-danger' : 'text-ink'">{{ t(`radiology.orders.priorityValue.${s.priority}`) }}</span>
                        </div>
                        <p class="mt-1 text-xs text-ink-subtle">{{ s.modality ?? '—' }}<template v-if="s.body_part"> · {{ s.body_part }}</template> · {{ t('radiology.worklist.orderedAt') }}: {{ fmt(s.ordered_at) }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button v-if="actions.can_acquire" type="button" class="rounded-full bg-euca-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-euca-700" @click="acquire(s)">{{ t('radiology.worklist.acquire') }}</button>
                            <Link :href="s.study_url" class="rounded-full border border-euca-200 px-4 py-1.5 text-xs font-semibold text-ink transition hover:bg-euca-50">{{ t('radiology.worklist.openStudy') }}</Link>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
