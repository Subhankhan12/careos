<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Nursing shift handover (HOSPITAL.G5) — PRESENTATIONAL (P0D.GU): the outgoing nurse authors a
// structured SBAR handover, and the stay's shift-by-shift history is shown raw. RECORD-NOT-JUDGE:
// every field is what the nurse WROTE — "Assessment" is the nurse's own words, never a computed
// acuity/score; nothing is auto-populated.
const { t, locale } = useI18n();

type HandoverRow = {
    id: string;
    shift: string;
    situation: string;
    background: string | null;
    assessment: string | null;
    recommendation: string | null;
    reason: string | null;
    handed_over_at: string;
};

const props = defineProps<{
    stay: { id: string; patient: string; status: string; bed: string | null; ward: string | null };
    handovers: HandoverRow[];
    shifts: string[];
    actions: { can_record: boolean; store_url: string };
}>();

const open = ref(false);
const form = reactive({ shift: '', situation: '', background: '', assessment: '', recommendation: '' });

function fmt(iso: string): string {
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function submit(): void {
    router.post(props.actions.store_url, { ...form }, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            Object.assign(form, { shift: '', situation: '', background: '', assessment: '', recommendation: '' });
        },
    });
}

const SBAR = [
    { key: 'situation', field: 'situation' as const },
    { key: 'background', field: 'background' as const },
    { key: 'assessment', field: 'assessment' as const },
    { key: 'recommendation', field: 'recommendation' as const },
];
</script>

<template>
    <AppLayout>
        <Head :title="t('hospital.handover.title')" />
        <div class="space-y-5">
            <!-- Header -->
            <div class="euca-tile-dark flex flex-col justify-between gap-4 p-6 sm:flex-row sm:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('hospital.handover.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ stay.patient }}</h1>
                    <p class="mt-1 text-sm text-euca-200">{{ stay.ward ?? '—' }} · {{ stay.bed ?? '—' }}</p>
                </div>
                <button v-if="actions.can_record" type="button" class="btn-glow self-start rounded-xl px-4 py-2 text-sm font-semibold" @click="open = !open">
                    {{ t('hospital.handover.record') }}
                </button>
            </div>

            <!-- Record a handover (SBAR — nurse-authored) -->
            <div v-if="open && actions.can_record" class="glass-card space-y-3 p-6">
                <select v-model="form.shift" class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink sm:max-w-xs">
                    <option value="">{{ t('hospital.handover.selectShift') }}</option>
                    <option v-for="s in shifts" :key="s" :value="s">{{ t(`hospital.handover.shift.${s}`) }}</option>
                </select>
                <div v-for="row in SBAR" :key="row.key">
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t(`hospital.handover.sbar.${row.key}`) }}</label>
                    <textarea v-model="form[row.field]" rows="2" class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink"></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="btn-glow rounded-lg px-4 py-2 text-sm font-semibold" @click="submit">{{ t('hospital.handover.save') }}</button>
                    <button type="button" class="rounded-lg border border-line px-4 py-2 text-sm font-medium text-ink-muted" @click="open = false">{{ t('hospital.handover.cancel') }}</button>
                </div>
            </div>

            <!-- Shift trail (append-only history, newest first) -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('hospital.handover.history') }}</h2>
                <p v-if="handovers.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('hospital.handover.empty') }}</p>
                <ol v-else class="mt-4 space-y-4">
                    <li v-for="h in handovers" :key="h.id" class="rounded-2xl bg-euca-50/60 p-4">
                        <div class="flex items-center justify-between">
                            <span class="rounded-full bg-euca-100 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ t(`hospital.handover.shift.${h.shift}`) }}</span>
                            <span class="text-xs text-ink-muted">{{ fmt(h.handed_over_at) }}</span>
                        </div>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div v-for="row in SBAR" :key="row.key" v-show="h[row.field]">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t(`hospital.handover.sbar.${row.key}`) }}</dt>
                                <dd class="text-ink">{{ h[row.field] }}</dd>
                            </div>
                        </dl>
                    </li>
                </ol>
            </div>
        </div>
    </AppLayout>
</template>
