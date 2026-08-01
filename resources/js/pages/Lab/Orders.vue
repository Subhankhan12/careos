<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Lab order entry (LAB.G2) — PRESENTATIONAL. A lab order IS a Clinical Order (reused); this places one + lists
// the patient's lab orders with their lifecycle state. The priority is a RECORDED flag the clinician sets — the
// screen records it; nothing computes a priority or ranks by urgency (the electric fence).
const { t } = useI18n();

type LabOrderRow = {
    id: string;
    code: string | null;
    name: string | null;
    specimen_type: string | null;
    priority: string;
    status: string | null;
    ordered_at: string | null;
};
type TestOption = { id: string; code: string | null; name: string | null; specimen: string | null };

const props = defineProps<{
    patient: { id: string; name: string };
    orders: LabOrderRow[];
    tests: TestOption[];
    options: { priorities: string[] };
    actions: { can_order: boolean; store_url: string };
}>();

const form = reactive({ lab_test_id: props.tests[0]?.id ?? '', priority: 'routine', specimen_type: '', clinical_note: '' });

// The selected test's default specimen (a display hint; the clinician may override in the field).
const selectedSpecimen = computed(() => props.tests.find((tf) => tf.id === form.lab_test_id)?.specimen ?? '');

function fmt(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

function place(): void {
    router.post(props.actions.store_url, { ...form }, { preserveScroll: true, onSuccess: () => { form.specimen_type = ''; form.clinical_note = ''; form.priority = 'routine'; } });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('lab.orders.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('lab.orders.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ patient.name }}</h1>
            </div>

            <div v-if="actions.can_order" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('lab.orders.place') }}</h2>
                <form class="mt-4 space-y-3" @submit.prevent="place">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('lab.orders.test') }}</label>
                        <select v-model="form.lab_test_id" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                            <option v-for="tf in tests" :key="tf.id" :value="tf.id">{{ tf.code }} — {{ tf.name }}</option>
                        </select>
                        <p v-if="!tests.length" class="mt-1 text-xs text-danger">{{ t('lab.orders.noTests') }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('lab.orders.specimen') }}</label>
                            <input v-model="form.specimen_type" type="text" maxlength="60" :placeholder="selectedSpecimen || t('lab.orders.specimenPlaceholder')" class="mt-1 w-full rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('lab.orders.priority') }}</label>
                            <select v-model="form.priority" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                                <option v-for="p in options.priorities" :key="p" :value="p">{{ t(`lab.orders.priorityValue.${p}`) }}</option>
                            </select>
                        </div>
                    </div>
                    <input v-model="form.clinical_note" type="text" maxlength="500" :placeholder="t('lab.orders.note')" class="w-full rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <p class="text-xs text-ink-muted">{{ t('lab.orders.priorityHint') }}</p>
                    <button type="submit" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('lab.orders.submit') }}</button>
                </form>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('lab.orders.history') }}</h2>
                <p v-if="!orders.length" class="mt-3 text-sm text-ink-muted">{{ t('lab.orders.empty') }}</p>
                <table v-else class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-ink-subtle">
                            <th class="py-1">{{ t('lab.orders.test') }}</th>
                            <th>{{ t('lab.orders.specimen') }}</th>
                            <th>{{ t('lab.orders.priority') }}</th>
                            <th>{{ t('lab.orders.status') }}</th>
                            <th>{{ t('lab.orders.orderedAt') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in orders" :key="o.id" class="border-t border-euca-50 text-ink">
                            <td class="py-1 font-semibold">{{ o.code }} — {{ o.name }}</td>
                            <td class="text-ink-subtle">{{ o.specimen_type ?? '—' }}</td>
                            <td>
                                <span class="rounded-full bg-ink/5 px-2 py-0.5 text-xs font-semibold" :class="o.priority === 'stat' ? 'text-danger' : 'text-ink'">{{ t(`lab.orders.priorityValue.${o.priority}`) }}</span>
                            </td>
                            <td class="text-ink-subtle">{{ o.status }}</td>
                            <td class="text-ink-subtle">{{ fmt(o.ordered_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
