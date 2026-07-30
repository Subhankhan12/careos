<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// The lab test catalog (LAB.G1) — PRESENTATIONAL. The tenant authors its OWN test menu (no licensed set). The
// reference range is RECORDED reference data (displayed beside a result later) — this screen records it; it
// computes NO abnormal/critical flag (the electric fence).
const { t } = useI18n();

type LabTest = {
    id: string;
    code: string | null;
    name: string | null;
    specimen: string | null;
    unit: string | null;
    reference_range: string | null;
    active: boolean;
    deactivate_url: string;
};

const props = defineProps<{
    tests: LabTest[];
    actions: { can_manage: boolean; store_url: string; seed_url: string };
}>();

const form = reactive({ code: '', name: '', specimen_type: '', unit: '', reference_range: '' });

function author(): void {
    router.post(props.actions.store_url, { ...form }, { preserveScroll: true, onSuccess: () => { form.code = ''; form.name = ''; form.specimen_type = ''; form.unit = ''; form.reference_range = ''; } });
}
function seed(): void {
    router.post(props.actions.seed_url, {}, { preserveScroll: true });
}
function deactivate(test: LabTest): void {
    router.post(test.deactivate_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('lab.catalog.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('lab.catalog.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('lab.catalog.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('lab.catalog.subtitle') }}</p>
            </div>

            <div v-if="actions.can_manage" class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('lab.catalog.author') }}</h2>
                    <button type="button" class="rounded-full bg-surface-2 px-4 py-1.5 text-xs font-semibold text-ink" @click="seed">{{ t('lab.catalog.seed') }}</button>
                </div>
                <form class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3" @submit.prevent="author">
                    <input v-model="form.code" type="text" maxlength="60" :placeholder="t('lab.catalog.code')" class="rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <input v-model="form.name" type="text" maxlength="120" :placeholder="t('lab.catalog.name')" class="rounded-lg border border-euca-200 px-3 py-2 text-sm sm:col-span-2" />
                    <input v-model="form.specimen_type" type="text" maxlength="60" :placeholder="t('lab.catalog.specimen')" class="rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <input v-model="form.unit" type="text" maxlength="40" :placeholder="t('lab.catalog.unit')" class="rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <input v-model="form.reference_range" type="text" maxlength="120" :placeholder="t('lab.catalog.range')" class="rounded-lg border border-euca-200 px-3 py-2 text-sm" />
                    <button type="submit" class="col-span-2 rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white sm:col-span-3">{{ t('lab.catalog.addTest') }}</button>
                </form>
                <p class="mt-2 text-xs text-ink-muted">{{ t('lab.catalog.rangeHint') }}</p>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('lab.catalog.menu') }}</h2>
                <p v-if="!tests.length" class="mt-3 text-sm text-ink-muted">{{ t('lab.catalog.empty') }}</p>
                <table v-else class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-ink-subtle">
                            <th class="py-1">{{ t('lab.catalog.code') }}</th>
                            <th>{{ t('lab.catalog.name') }}</th>
                            <th>{{ t('lab.catalog.specimen') }}</th>
                            <th>{{ t('lab.catalog.unit') }}</th>
                            <th>{{ t('lab.catalog.range') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="test in tests" :key="test.id" class="border-t border-euca-50 text-ink" :class="{ 'opacity-50': !test.active }">
                            <td class="py-1 font-semibold">{{ test.code }}</td>
                            <td>{{ test.name }}</td>
                            <td class="text-ink-subtle">{{ test.specimen ?? '—' }}</td>
                            <td class="text-ink-subtle">{{ test.unit ?? '—' }}</td>
                            <td class="text-ink-subtle">{{ test.reference_range ?? '—' }}</td>
                            <td class="text-right">
                                <button v-if="actions.can_manage && test.active" type="button" class="text-xs font-semibold text-danger" @click="deactivate(test)">{{ t('lab.catalog.deactivate') }}</button>
                                <span v-else-if="!test.active" class="text-xs text-ink-muted">{{ t('lab.catalog.inactive') }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
