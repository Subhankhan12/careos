<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { type Financial, isNewTenant, type Operational, visibleSetupSteps } from '@/lib/firstRun';

// First-run "Get started" checklist (POLISH.3). Shows ONLY when the landing's existing props read as an
// empty/new tenant and the actor has >=1 setup step; dismissible (per-tenant, localStorage). Every link is
// permission-gated (a role only sees what it can reach) and the server Gate stays authoritative. No query,
// no data write, no computed recommendation — the steps are the fixed onboarding sequence.
const { t } = useI18n();
const page = usePage();

const props = defineProps<{ operational: Operational; financial: Financial }>();

const user = computed(
    () => (page.props.auth as { user: { tenantId?: string | null; permissions?: Record<string, boolean> } | null }).user,
);
const permissions = computed(() => user.value?.permissions ?? {});
const storageKey = computed(() => `careos.firstRun.dismissed.${user.value?.tenantId ?? 'x'}`);

const dismissed = ref(typeof localStorage !== 'undefined' && localStorage.getItem(storageKey.value) === '1');
const steps = computed(() => visibleSetupSteps(permissions.value));
const show = computed(() => isNewTenant(props.operational, props.financial) && steps.value.length > 0 && !dismissed.value);

function dismiss(): void {
    dismissed.value = true;
    try {
        localStorage.setItem(storageKey.value, '1');
    } catch {
        /* private mode — dismiss for this session only */
    }
}
</script>

<template>
    <section v-if="show" class="euca-tile-dark relative mb-6 overflow-hidden p-6">
        <svg class="pointer-events-none absolute -right-8 -top-8 h-40 w-40 text-euca-50 opacity-10" viewBox="0 0 200 200" fill="none" aria-hidden="true">
            <path d="M40 170C40 100 70 55 160 45c-4 78-44 118-120 125z" fill="currentColor" />
        </svg>
        <button
            type="button"
            :aria-label="t('firstRun.dismiss')"
            class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-euca-200 transition hover:bg-white/10 hover:text-euca-50"
            @click="dismiss"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
        </button>

        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('firstRun.eyebrow') }}</p>
        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-euca-50">{{ t('firstRun.title') }}</h2>
        <p class="mt-1.5 max-w-lg text-sm text-euca-200">{{ t('firstRun.subtitle') }}</p>

        <ol class="mt-5 grid gap-2 sm:grid-cols-2">
            <li v-for="(step, index) in steps" :key="step.key">
                <Link
                    :href="step.href"
                    class="group flex items-center gap-3 rounded-xl bg-white/10 px-4 py-3 text-sm font-medium text-euca-50 transition hover:bg-white/20"
                >
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-euca-400 text-xs font-semibold text-euca-900">{{ index + 1 }}</span>
                    <span class="grow">{{ t(`firstRun.steps.${step.key}`) }}</span>
                    <span class="text-euca-200 transition group-hover:translate-x-0.5">→</span>
                </Link>
            </li>
        </ol>
    </section>
</template>
