<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Imaging order entry (RAD.G2) — PRESENTATIONAL. An imaging order IS a Clinical Order (reused); this places one
// + lists the patient's imaging orders with their lifecycle state. The priority is a RECORDED flag the clinician
// sets — the screen records it; nothing computes a priority or ranks by urgency (the electric fence).
const { t } = useI18n();

type RadiologyOrderRow = {
    id: string;
    code: string | null;
    name: string | null;
    modality: string | null;
    body_part: string | null;
    priority: string;
    status: string | null;
    ordered_at: string | null;
};
type ExamOption = { id: string; code: string | null; name: string | null; modality: string | null; body_part: string | null };

const props = defineProps<{
    patient: { id: string; name: string };
    orders: RadiologyOrderRow[];
    exams: ExamOption[];
    options: { priorities: string[] };
    actions: { can_order: boolean; store_url: string };
}>();

const form = reactive({ radiology_exam_id: props.exams[0]?.id ?? '', priority: 'routine', modality: '', body_part: '', clinical_note: '' });

// The selected exam's default modality/body-part (a display hint; the clinician may override in the field).
const selected = computed(() => props.exams.find((e) => e.id === form.radiology_exam_id));

function fmt(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}

function place(): void {
    router.post(props.actions.store_url, { ...form }, { preserveScroll: true, onSuccess: () => { form.modality = ''; form.body_part = ''; form.clinical_note = ''; form.priority = 'routine'; } });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('radiology.orders.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('radiology.orders.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ patient.name }}</h1>
            </div>

            <div v-if="actions.can_order" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.orders.place') }}</h2>
                <form class="mt-4 space-y-3" @submit.prevent="place">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('radiology.orders.exam') }}</label>
                        <select v-model="form.radiology_exam_id" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                            <option v-for="e in exams" :key="e.id" :value="e.id">{{ e.code }} — {{ e.name }}</option>
                        </select>
                        <p v-if="!exams.length" class="mt-1 text-xs text-danger">{{ t('radiology.orders.noExams') }}</p>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('radiology.orders.modality') }}</label>
                            <input v-model="form.modality" type="text" maxlength="40" :placeholder="selected?.modality || t('radiology.orders.modalityPlaceholder')" class="mt-1 w-full rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('radiology.orders.bodyPart') }}</label>
                            <input v-model="form.body_part" type="text" maxlength="60" :placeholder="selected?.body_part || t('radiology.orders.bodyPartPlaceholder')" class="mt-1 w-full rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('radiology.orders.priority') }}</label>
                            <select v-model="form.priority" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                                <option v-for="p in options.priorities" :key="p" :value="p">{{ t(`radiology.orders.priorityValue.${p}`) }}</option>
                            </select>
                        </div>
                    </div>
                    <input v-model="form.clinical_note" type="text" maxlength="500" :placeholder="t('radiology.orders.note')" class="w-full rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <p class="text-xs text-ink-muted">{{ t('radiology.orders.priorityHint') }}</p>
                    <button type="submit" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('radiology.orders.submit') }}</button>
                </form>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.orders.history') }}</h2>
                <p v-if="!orders.length" class="mt-3 text-sm text-ink-muted">{{ t('radiology.orders.empty') }}</p>
                <table v-else class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-ink-subtle">
                            <th class="py-1">{{ t('radiology.orders.exam') }}</th>
                            <th>{{ t('radiology.orders.modality') }}</th>
                            <th>{{ t('radiology.orders.bodyPart') }}</th>
                            <th>{{ t('radiology.orders.priority') }}</th>
                            <th>{{ t('radiology.orders.status') }}</th>
                            <th>{{ t('radiology.orders.orderedAt') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in orders" :key="o.id" class="border-t border-euca-50 text-ink">
                            <td class="py-1 font-semibold">{{ o.code }} — {{ o.name }}</td>
                            <td class="text-ink-subtle">{{ o.modality ?? '—' }}</td>
                            <td class="text-ink-subtle">{{ o.body_part ?? '—' }}</td>
                            <td>
                                <span class="rounded-full bg-ink/5 px-2 py-0.5 text-xs font-semibold" :class="o.priority === 'stat' ? 'text-danger' : 'text-ink'">{{ t(`radiology.orders.priorityValue.${o.priority}`) }}</span>
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
