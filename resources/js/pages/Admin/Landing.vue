<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const { t } = useI18n();
const page = usePage();

const locale = computed(() => (page.props.locale as string) || 'en');
const today = computed(() =>
    new Intl.DateTimeFormat(locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
        .format(new Date())
        .toUpperCase(),
);
</script>

<template>
    <AppLayout>
        <Head :title="t('shell.admin.title')" />

        <!-- Platform-scope notice (info-soft). -->
        <div class="mb-6 flex items-start gap-2.5 rounded-xl border border-info/25 bg-info-soft px-4 py-3 text-sm text-ink">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-info" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                <path d="M12 11v5M12 8v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            {{ t('shell.admin.scopeNote') }}
        </div>

        <PageHeader :eyebrow="today" :title="t('shell.admin.overview')" />

        <!-- KPI row + the single dark system-health tile. -->
        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard :label="t('shell.admin.kpiTenants')" :hint="t('shell.admin.pending')">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M4 20V7l6-3 6 3v13M4 20h16M9 20v-4h2v4" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    </svg>
                </template>
            </StatCard>

            <div class="euca-tile-dark p-5">
                <p class="text-sm font-medium text-euca-200">{{ t('shell.admin.kpiHealth') }}</p>
                <p class="mt-3 text-3xl font-semibold text-euca-50">—</p>
                <p class="mt-1 text-xs text-euca-300">{{ t('shell.admin.pending') }}</p>
            </div>

            <StatCard :label="t('shell.admin.kpiAgents')" :hint="t('shell.admin.pending')">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 3v3M12 3l1.5 1.5M12 3l-1.5 1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        <rect x="5" y="6" width="14" height="12" rx="3" stroke="currentColor" stroke-width="1.6" />
                        <circle cx="9.5" cy="12" r="1" fill="currentColor" />
                        <circle cx="14.5" cy="12" r="1" fill="currentColor" />
                    </svg>
                </template>
            </StatCard>

            <StatCard :label="t('shell.admin.kpiNewTenants')" :hint="t('shell.admin.pending')">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                </template>
            </StatCard>
        </section>

        <!-- Two-column body: tenants + service checks / recent actions (empty until wired). -->
        <section class="mt-6 grid gap-5 lg:grid-cols-[1.6fr_1fr]">
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('shell.admin.tenantsTitle') }}</h2>
                <div class="mt-6 flex flex-col items-center justify-center rounded-xl bg-euca-50/60 px-6 py-12 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-euca-100 text-euca-700">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 20V7l6-3 6 3v13M4 20h16" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <p class="mt-4 max-w-sm text-sm text-ink-muted">{{ t('shell.admin.tenantsEmpty') }}</p>
                </div>
            </div>

            <div class="space-y-5">
                <div class="glass-card p-6">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('shell.admin.serviceChecks') }}</h2>
                    <p class="mt-4 text-sm text-ink-subtle">{{ t('shell.admin.pending') }}</p>
                </div>
                <div class="glass-card p-6">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('shell.admin.recentActions') }}</h2>
                    <p class="mt-4 text-sm text-ink-subtle">{{ t('shell.admin.empty') }}</p>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
