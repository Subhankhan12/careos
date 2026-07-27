<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Tenant-authored formulary (PHARMACY.G1) — the pharmacist's OWN medication list. PRESENTATIONAL: a plain
// record (name/form/strength), NO licensed drug data and NO computed-safety field. Orders/eMAR/dispensing
// are later pharmacy gates.
const { t } = useI18n();

type FormularyRow = {
    id: string;
    code: string;
    name: string;
    form: string | null;
    strength: string | null;
    active: boolean;
    deactivate_url: string;
};

const props = defineProps<{
    items: FormularyRow[];
    forms: string[];
    actions: { store_url: string };
}>();

const form = reactive({ code: '', name: '', form: '', strength: '' });

function submit(): void {
    router.post(props.actions.store_url, { ...form }, {
        preserveScroll: true,
        onSuccess: () => Object.assign(form, { code: '', name: '', form: '', strength: '' }),
    });
}

function deactivate(row: FormularyRow): void {
    router.post(row.deactivate_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('pharmacy.formulary.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('pharmacy.formulary.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('pharmacy.formulary.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('pharmacy.formulary.subtitle') }}</p>
            </div>

            <!-- Add a medication -->
            <form class="glass-card grid gap-3 p-6 sm:grid-cols-2 xl:grid-cols-5" @submit.prevent="submit">
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="f-code">{{ t('pharmacy.formulary.code') }}</label>
                    <input id="f-code" v-model="form.code" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="f-name">{{ t('pharmacy.formulary.name') }}</label>
                    <input id="f-name" v-model="form.name" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="f-form">{{ t('pharmacy.formulary.form') }}</label>
                    <select id="f-form" v-model="form.form" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">—</option>
                        <option v-for="f in forms" :key="f" :value="f">{{ t(`pharmacy.formulary.forms.${f}`) }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="f-strength">{{ t('pharmacy.formulary.strength') }}</label>
                    <input id="f-strength" v-model="form.strength" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">
                        {{ t('pharmacy.formulary.add') }}
                    </button>
                </div>
            </form>

            <!-- The formulary -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('pharmacy.formulary.heading') }}</h2>
                <p v-if="items.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('pharmacy.formulary.empty') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-100">
                    <li v-for="item in items" :key="item.id" class="flex items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">
                                {{ item.name }}
                                <span v-if="item.strength" class="text-ink-muted">· {{ item.strength }}</span>
                                <span v-if="item.form" class="text-ink-muted">· {{ t(`pharmacy.formulary.forms.${item.form}`) }}</span>
                            </p>
                            <p class="text-xs text-ink-muted">{{ item.code }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="item.active ? 'bg-euca-100 text-euca-800' : 'bg-white/40 text-ink-muted'"
                            >
                                {{ item.active ? t('pharmacy.formulary.active') : t('pharmacy.formulary.inactive') }}
                            </span>
                            <button
                                v-if="item.active"
                                type="button"
                                class="rounded-full bg-white/50 px-3 py-1 text-xs font-semibold text-ink transition hover:bg-white/70"
                                @click="deactivate(item)"
                            >
                                {{ t('pharmacy.formulary.deactivate') }}
                            </button>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
