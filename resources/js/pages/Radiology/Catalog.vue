<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// The imaging exam catalog (RAD.G1) — PRESENTATIONAL. The tenant authors its OWN exam menu (no licensed set).
// The catalog records modality + body part (reference data) — this screen records it; it computes NO image
// finding/CAD/abnormality flag (the AI-imaging fence). The radiologist authors the report (later gates).
const { t } = useI18n();

type RadiologyExam = {
    id: string;
    code: string | null;
    name: string | null;
    modality: string | null;
    body_part: string | null;
    contrast: boolean;
    active: boolean;
    deactivate_url: string;
};

const props = defineProps<{
    exams: RadiologyExam[];
    actions: { can_manage: boolean; store_url: string; seed_url: string };
}>();

const form = reactive({ code: '', name: '', modality: '', body_part: '', contrast: false });

function author(): void {
    router.post(props.actions.store_url, { ...form }, { preserveScroll: true, onSuccess: () => { form.code = ''; form.name = ''; form.modality = ''; form.body_part = ''; form.contrast = false; } });
}
function seed(): void {
    router.post(props.actions.seed_url, {}, { preserveScroll: true });
}
function deactivate(exam: RadiologyExam): void {
    router.post(exam.deactivate_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('radiology.catalog.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('radiology.catalog.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('radiology.catalog.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('radiology.catalog.subtitle') }}</p>
            </div>

            <div v-if="actions.can_manage" class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.catalog.author') }}</h2>
                    <button type="button" class="rounded-full bg-surface-2 px-4 py-1.5 text-xs font-semibold text-ink" @click="seed">{{ t('radiology.catalog.seed') }}</button>
                </div>
                <form class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3" @submit.prevent="author">
                    <input v-model="form.code" type="text" maxlength="60" :placeholder="t('radiology.catalog.code')" class="rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <input v-model="form.name" type="text" maxlength="120" :placeholder="t('radiology.catalog.name')" class="rounded-lg border border-euca-200 px-3 py-2 text-sm sm:col-span-2" />
                    <input v-model="form.modality" type="text" maxlength="40" :placeholder="t('radiology.catalog.modality')" class="rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <input v-model="form.body_part" type="text" maxlength="60" :placeholder="t('radiology.catalog.bodyPart')" class="rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input v-model="form.contrast" type="checkbox" class="rounded border-euca-200" />
                        {{ t('radiology.catalog.contrast') }}
                    </label>
                    <button type="submit" class="col-span-2 rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white sm:col-span-3">{{ t('radiology.catalog.addExam') }}</button>
                </form>
                <p class="mt-2 text-xs text-ink-muted">{{ t('radiology.catalog.fenceHint') }}</p>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.catalog.menu') }}</h2>
                <p v-if="!exams.length" class="mt-3 text-sm text-ink-muted">{{ t('radiology.catalog.empty') }}</p>
                <table v-else class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-ink-subtle">
                            <th class="py-1">{{ t('radiology.catalog.code') }}</th>
                            <th>{{ t('radiology.catalog.name') }}</th>
                            <th>{{ t('radiology.catalog.modality') }}</th>
                            <th>{{ t('radiology.catalog.bodyPart') }}</th>
                            <th>{{ t('radiology.catalog.contrast') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="exam in exams" :key="exam.id" class="border-t border-euca-50 text-ink" :class="{ 'opacity-50': !exam.active }">
                            <td class="py-1 font-semibold">{{ exam.code }}</td>
                            <td>{{ exam.name }}</td>
                            <td class="text-ink-subtle">{{ exam.modality ?? '—' }}</td>
                            <td class="text-ink-subtle">{{ exam.body_part ?? '—' }}</td>
                            <td class="text-ink-subtle">{{ exam.contrast ? t('radiology.catalog.withContrast') : '—' }}</td>
                            <td class="text-right">
                                <button v-if="actions.can_manage && exam.active" type="button" class="text-xs font-semibold text-danger" @click="deactivate(exam)">{{ t('radiology.catalog.deactivate') }}</button>
                                <span v-else-if="!exam.active" class="text-xs text-ink-muted">{{ t('radiology.catalog.inactive') }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
