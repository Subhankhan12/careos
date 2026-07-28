<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// ED triage (ED.G2) — PRESENTATIONAL. The triage nurse records the presenting complaint, RAW vitals, and the
// ASSIGNED acuity (they SELECT the level — the system never computes it). The "suggestion" area is wired to
// the triage-acuity seam and shows nothing today (the electric fence: assigned-not-computed).
const { t } = useI18n();

type Triage = {
    id: string;
    presenting_complaint: string;
    acuity_scale: string;
    acuity_level: string;
    triaged_by: string | null;
    triaged_at: string;
};
type Vital = { recorded_at: string; systolic: number | null; diastolic: number | null; heart_rate: number | null; temperature_c: string | null; spo2: number | null };
type Option = { id: string; name: string };

const props = defineProps<{
    visit: {
        id: string;
        patient: { id: string; name: string };
        arrival_mode: string;
        chief_complaint: string;
        status: string;
        arrived_at: string;
    };
    triages: Triage[];
    vitals: Vital[];
    acuity_suggestion: { level: string; scale: string } | null;
    options: { scales: string[]; levels: Record<string, string[]>; nurses: Option[] };
    actions: { can_record: boolean; store_url: string };
}>();

const form = reactive({
    triaged_by: props.options.nurses[0]?.id ?? '',
    presenting_complaint: props.visit.chief_complaint,
    acuity_scale: props.options.scales[0] ?? 'ESI',
    acuity_level: '',
    systolic: '',
    diastolic: '',
    heart_rate: '',
    temperature_c: '',
    spo2: '',
});

const levelsForScale = computed<string[]>(() => props.options.levels[form.acuity_scale] ?? []);

function fmt(iso: string): string {
    return new Date(iso).toLocaleString();
}

function submit(): void {
    router.post(props.actions.store_url, { ...form }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('ed.triage.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('ed.triage.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ visit.patient.name }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ visit.chief_complaint }} · {{ t(`ed.arrivalMode.${visit.arrival_mode}`) }}</p>
                <span class="mt-3 inline-block rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-euca-50">{{ t(`ed.status.${visit.status}`) }}</span>
            </div>

            <!-- The triage-acuity SEAM area — empty today. CareOS computes no acuity; a certified partner would
                 fill this as an ADVISORY the nurse considers, never an auto-assignment. -->
            <div class="glass-card p-4">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('ed.triage.suggestionArea') }}</h2>
                <p v-if="acuity_suggestion" class="mt-1 text-sm text-ink">
                    {{ t('ed.triage.suggestionAdvisory', { level: acuity_suggestion.level, scale: acuity_suggestion.scale }) }}
                </p>
                <p v-else class="mt-1 text-sm text-ink-muted">{{ t('ed.triage.noSuggestion') }}</p>
            </div>

            <div v-if="actions.can_record" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.triage.record') }}</h2>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.triage.nurse') }}</label>
                        <select v-model="form.triaged_by" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                            <option v-for="n in options.nurses" :key="n.id" :value="n.id">{{ n.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.triage.complaint') }}</label>
                        <input v-model="form.presenting_complaint" type="text" maxlength="500" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.triage.scale') }}</label>
                            <select v-model="form.acuity_scale" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                                <option v-for="s in options.scales" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.triage.assignedLevel') }}</label>
                            <select v-model="form.acuity_level" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                                <option value="" disabled>{{ t('ed.triage.selectLevel') }}</option>
                                <option v-for="l in levelsForScale" :key="l" :value="l">{{ l }}</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-xs text-ink-muted">{{ t('ed.triage.assignedHint') }}</p>

                    <fieldset class="rounded-lg border border-euca-100 p-3">
                        <legend class="px-1 text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.triage.rawVitals') }}</legend>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                            <label class="text-xs text-ink-subtle">{{ t('ed.triage.systolic') }}<input v-model="form.systolic" type="number" class="mt-1 w-full rounded border border-euca-200 px-2 py-1 text-sm" /></label>
                            <label class="text-xs text-ink-subtle">{{ t('ed.triage.diastolic') }}<input v-model="form.diastolic" type="number" class="mt-1 w-full rounded border border-euca-200 px-2 py-1 text-sm" /></label>
                            <label class="text-xs text-ink-subtle">{{ t('ed.triage.heartRate') }}<input v-model="form.heart_rate" type="number" class="mt-1 w-full rounded border border-euca-200 px-2 py-1 text-sm" /></label>
                            <label class="text-xs text-ink-subtle">{{ t('ed.triage.temperature') }}<input v-model="form.temperature_c" type="number" step="0.1" class="mt-1 w-full rounded border border-euca-200 px-2 py-1 text-sm" /></label>
                            <label class="text-xs text-ink-subtle">{{ t('ed.triage.spo2') }}<input v-model="form.spo2" type="number" class="mt-1 w-full rounded border border-euca-200 px-2 py-1 text-sm" /></label>
                        </div>
                    </fieldset>

                    <button type="submit" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('ed.triage.save') }}</button>
                </form>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.triage.history') }}</h2>
                <p v-if="!triages.length" class="mt-3 text-sm text-ink-muted">{{ t('ed.triage.none') }}</p>
                <ul v-else class="mt-4 space-y-3">
                    <li v-for="tri in triages" :key="tri.id" class="rounded-lg border border-euca-100 p-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">{{ tri.acuity_scale }} · {{ tri.acuity_level }}</span>
                            <span class="text-xs text-ink-subtle">{{ fmt(tri.triaged_at) }}</span>
                        </div>
                        <p class="mt-1 text-sm text-ink">{{ tri.presenting_complaint }}</p>
                        <p v-if="tri.triaged_by" class="mt-1 text-xs text-ink-muted">{{ t('ed.triage.by', { nurse: tri.triaged_by }) }}</p>
                    </li>
                </ul>
            </div>

            <div v-if="vitals.length" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.triage.vitalsHistory') }}</h2>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-ink-subtle">
                            <th class="py-1">{{ t('ed.triage.recordedAt') }}</th>
                            <th>{{ t('ed.triage.bp') }}</th>
                            <th>{{ t('ed.triage.hr') }}</th>
                            <th>{{ t('ed.triage.temp') }}</th>
                            <th>{{ t('ed.triage.spo2') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(v, i) in vitals" :key="i" class="border-t border-euca-50 text-ink">
                            <td class="py-1">{{ fmt(v.recorded_at) }}</td>
                            <td><template v-if="v.systolic">{{ v.systolic }}/{{ v.diastolic }}</template><template v-else>—</template></td>
                            <td>{{ v.heart_rate ?? '—' }}</td>
                            <td><template v-if="v.temperature_c">{{ v.temperature_c }}</template><template v-else>—</template></td>
                            <td>{{ v.spo2 ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
