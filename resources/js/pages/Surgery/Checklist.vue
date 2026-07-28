<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// WHO Surgical Safety Checklist (SURGERY.G3) — PRESENTATIONAL. The three WHO phases; the team confirms items;
// a FACTUAL "checked / total" count is shown. RECORDED, NOT ENFORCED: this page never blocks the case — there
// is no "proceed" gate and no computed safety verdict; the team owns the safety decision. A count is a fact.
const { t } = useI18n();

type Item = { template_item_id: string; label: string; checked: boolean; confirmed_at: string | null };
type Phase = { phase: string; items: Item[]; checked_count: number; total: number };

const props = defineProps<{
    surgicalCase: { id: string; patient: string; procedure: string; status: string; case_url: string };
    checklist: { checklist_id: string | null; phases: Phase[] };
    actions: { can_confirm: boolean; confirm_url: string };
}>();

function confirm(item: Item, checked: boolean): void {
    router.post(props.actions.confirm_url, { template_item_id: item.template_item_id, checked }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('surgery.checklist.title')" />
        <div class="space-y-5">
            <!-- Header -->
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('surgery.checklist.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ surgicalCase.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ surgicalCase.procedure }}</p>
                <Link :href="surgicalCase.case_url" class="mt-3 inline-block text-xs font-semibold text-euca-100 underline">{{ t('surgery.checklist.backToCase') }}</Link>
            </div>

            <!-- Record-not-enforce note -->
            <div class="glass-card border-l-4 border-euca-300 p-4">
                <p class="text-sm text-ink-muted">{{ t('surgery.checklist.recordNote') }}</p>
            </div>

            <!-- The three WHO phases -->
            <div v-for="phase in checklist.phases" :key="phase.phase" class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t(`surgery.checklist.phase.${phase.phase}`) }}</h2>
                    <!-- A FACTUAL count — not a verdict, not a pass/fail. -->
                    <span class="rounded-full bg-white/40 px-2.5 py-0.5 text-xs font-semibold text-ink-muted">{{ t('surgery.checklist.count', { checked: phase.checked_count, total: phase.total }) }}</span>
                </div>
                <ul class="mt-4 space-y-2">
                    <li v-for="item in phase.items" :key="item.template_item_id" class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            :checked="item.checked"
                            :disabled="!actions.can_confirm"
                            class="mt-1 rounded border-euca-300"
                            @change="confirm(item, ($event.target as HTMLInputElement).checked)"
                        />
                        <span class="text-sm" :class="item.checked ? 'text-ink' : 'text-ink-muted'">{{ item.label }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
