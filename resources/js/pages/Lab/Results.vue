<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Manual result entry (LAB.G4) — PRESENTATIONAL. THE FENCE GATE. Enter a raw result value against a specimen
// (REUSES the Clinical OrderResult); the reference range is DISPLAYED beside the value as recorded reference
// data. The screen records the value + SHOWS the range — it computes NO abnormal/high/low/critical flag, does
// NOT colour-by-abnormal, does NOT delta-check, does NOT interpret. The clinician reads value-vs-range.
const { t, locale } = useI18n();

type SpecimenRow = { id: string; accession_number: string; status: string; can_result: boolean; result_url: string };
type ResultRow = { id: string; result_value: string | null; source: string; entered_at: string; accession_number: string | null };

const props = defineProps<{
    labOrder: { id: string; patient: string; test: string | null; code: string | null; priority: string; order_status: string | null };
    reference: { unit: string | null; reference_range: string | null };
    specimens: SpecimenRow[];
    results: ResultRow[];
    actions: { can_result: boolean };
}>();

const resultable = props.specimens.filter((s) => s.can_result);
const form = reactive({ specimen_id: resultable[0]?.id ?? '', value: '' });
const submitting = ref(false);

function fmt(iso: string): string {
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function record(): void {
    const target = resultable.find((s) => s.id === form.specimen_id);
    if (!target) return;
    submitting.value = true;
    router.post(target.result_url, { value: form.value }, {
        preserveScroll: true,
        onSuccess: () => { form.value = ''; },
        onFinish: () => { submitting.value = false; },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('lab.results.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('lab.results.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ labOrder.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ labOrder.code }} — {{ labOrder.test }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-semibold text-euca-50">
                    <span class="rounded-full bg-white/15 px-3 py-1">{{ t(`lab.orders.priorityValue.${labOrder.priority}`) }}</span>
                    <span v-if="labOrder.order_status" class="rounded-full bg-white/15 px-3 py-1">{{ labOrder.order_status }}</span>
                </div>
            </div>

            <!-- The DISPLAYED reference data (recorded reference — the fence): shown beside the value, never a computed grade. -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('lab.results.reference') }}</h2>
                <div class="mt-2 flex flex-wrap gap-6 text-sm text-ink">
                    <p><span class="text-ink-subtle">{{ t('lab.results.unit') }}:</span> <span class="font-medium">{{ reference.unit ?? '—' }}</span></p>
                    <p><span class="text-ink-subtle">{{ t('lab.results.reference') }}:</span> <span class="font-medium">{{ reference.reference_range ?? '—' }}</span></p>
                </div>
                <p class="mt-2 text-xs text-ink-muted">{{ t('lab.results.referenceHint') }}</p>
            </div>

            <div v-if="actions.can_result" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('lab.results.enter') }}</h2>
                <p v-if="!resultable.length" class="mt-3 text-sm text-ink-muted">{{ t('lab.results.noSpecimens') }}</p>
                <form v-else class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="record">
                    <label class="flex flex-col gap-1 text-xs font-semibold text-ink-subtle">
                        {{ t('lab.results.selectSpecimen') }}
                        <select v-model="form.specimen_id" class="w-48 rounded-lg border border-euca-200 px-3 py-2 text-sm">
                            <option v-for="s in resultable" :key="s.id" :value="s.id">{{ s.accession_number }}</option>
                        </select>
                    </label>
                    <label class="flex flex-1 flex-col gap-1 text-xs font-semibold text-ink-subtle">
                        {{ t('lab.results.value') }}<template v-if="reference.unit"> ({{ reference.unit }})</template>
                        <input v-model="form.value" type="text" maxlength="500" :placeholder="t('lab.results.valuePlaceholder')" class="w-full rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    </label>
                    <button type="submit" :disabled="submitting || !form.value.trim()" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700 disabled:opacity-50">{{ t('lab.results.resultBtn') }}</button>
                </form>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('lab.results.history') }}</h2>
                <p v-if="!results.length" class="mt-3 text-sm text-ink-muted">{{ t('lab.results.empty') }}</p>
                <ul v-else class="mt-4 space-y-3">
                    <li v-for="r in results" :key="r.id" class="rounded-lg border border-euca-100 p-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <!-- The RAW value beside the DISPLAYED range — no colour-by-abnormal, no flag (the fence). -->
                            <span class="text-lg font-semibold text-ink">{{ r.result_value ?? '—' }}<span v-if="reference.unit" class="ml-1 text-sm font-normal text-ink-subtle">{{ reference.unit }}</span></span>
                            <span v-if="reference.reference_range" class="text-xs text-ink-subtle">{{ t('lab.results.reference') }}: {{ reference.reference_range }}</span>
                        </div>
                        <p class="mt-1 text-xs text-ink-subtle">
                            <template v-if="r.accession_number">{{ r.accession_number }} · </template>{{ t('lab.results.source') }}: {{ r.source }} · {{ fmt(r.entered_at) }}
                        </p>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
