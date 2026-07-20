<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const { t } = useI18n();

interface Item {
    id: string;
    name: string | null;
    tooth: string | null;
    estimate_minor: number;
}
interface Phase {
    id: string;
    name: string;
    total_minor: number;
    items: Item[];
}

defineProps<{
    plans: Array<{ id: string; title: string | null; status: string; total_minor: number; phases: Phase[] }>;
}>();

// The tenant currency isn't shared to the portal here; estimates display as a plain amount.
function money(minor: number): string {
    return (minor / 100).toFixed(2);
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portalTreatmentPlan.title')" />

        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('portalTreatmentPlan.eyebrow') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{{ t('portalTreatmentPlan.title') }}</h1>
        <p class="mt-1 max-w-2xl text-ink-muted">{{ t('portalTreatmentPlan.subtitle') }}</p>

        <div v-if="plans.length" class="mt-6 space-y-4">
            <div v-for="plan in plans" :key="plan.id" class="glass-card p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="font-semibold text-ink">{{ plan.title ?? t('portalTreatmentPlan.title') }}</p>
                    <span class="inline-flex items-center rounded-full bg-euca-50 px-3 py-1 text-xs font-semibold text-euca-700">{{ t(`portalTreatmentPlan.status.${plan.status}`) }}</span>
                </div>
                <p class="mt-1 text-sm text-ink-muted">{{ t('portalTreatmentPlan.total') }}: <span class="font-semibold text-ink">{{ money(plan.total_minor) }}</span></p>

                <div class="mt-4 space-y-3">
                    <div v-for="phase in plan.phases" :key="phase.id" class="rounded-xl border border-line p-4">
                        <div class="flex items-center justify-between">
                            <p class="font-semibold text-ink">{{ phase.name }}</p>
                            <p class="text-sm text-ink-muted">{{ money(phase.total_minor) }}</p>
                        </div>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-for="item in phase.items" :key="item.id" class="flex items-center justify-between border-t border-line/60 pt-1">
                                <span class="text-ink">{{ item.name }}<span v-if="item.tooth" class="text-ink-subtle"> · {{ item.tooth }}</span></span>
                                <span class="text-ink-muted">{{ money(item.estimate_minor) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <p v-else class="mt-6 text-ink-muted">{{ t('portalTreatmentPlan.empty') }}</p>
    </PortalLayout>
</template>
