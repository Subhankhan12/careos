<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';

const { t } = useI18n();
const page = usePage();

const locale = computed(() => (page.props.locale as string) || 'en');
const today = computed(() =>
    new Intl.DateTimeFormat(locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(
        new Date(),
    ),
);
</script>

<template>
    <AppLayout>
        <Head :title="t('shell.app.title')" />

        <!-- Greeting hero + the single deep-eucalyptus accent tile. -->
        <section class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
            <div>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface/60 px-3 py-1 text-xs font-medium text-ink-muted"
                >
                    <span class="h-1.5 w-1.5 rounded-full bg-euca-600"></span>{{ today }}
                </span>
                <h1 class="mt-3 max-w-xl text-4xl font-semibold leading-[1.08] tracking-tight text-ink sm:text-5xl">
                    {{ t('shell.app.welcome') }}
                </h1>
                <div class="mt-6 flex flex-wrap gap-3">
                    <Link
                        href="/patients/register"
                        class="btn-glow inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold"
                    >
                        {{ t('shell.app.registerPatient') }}
                    </Link>
                    <Link
                        href="/scheduling/day-board"
                        class="inline-flex items-center gap-2 rounded-xl border border-line bg-surface/70 px-4 py-2.5 text-sm font-semibold text-ink transition hover:bg-surface-2"
                    >
                        {{ t('shell.app.openDayBoard') }}
                    </Link>
                </div>
            </div>

            <div class="euca-tile-dark relative w-full max-w-sm overflow-hidden p-6">
                <svg
                    class="pointer-events-none absolute -right-6 -top-6 h-32 w-32 text-euca-50 opacity-10"
                    viewBox="0 0 200 200"
                    fill="none"
                    aria-hidden="true"
                >
                    <path d="M40 170C40 100 70 55 160 45c-4 78-44 118-120 125z" fill="currentColor" />
                </svg>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">
                    {{ t('shell.app.glanceTitle') }}
                </p>
                <p class="mt-3 text-2xl font-semibold text-euca-50">{{ today }}</p>
                <p class="mt-2 max-w-[16rem] text-sm text-euca-200">{{ t('shell.app.glancePending') }}</p>
            </div>
        </section>

        <!-- KPI row — values bind to future stat props; "—" until then, sparkline hidden. -->
        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard :label="t('shell.app.kpiAppointments')" :hint="t('shell.app.statPending')">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <rect x="3.5" y="5" width="17" height="15" rx="2.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="M3.5 9h17M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                </template>
            </StatCard>
            <StatCard :label="t('shell.app.kpiWaiting')" :hint="t('shell.app.statPending')">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.6" />
                        <path d="M4 19c.6-3 2.6-4.5 5-4.5s4.4 1.5 5 4.5M17 8v3l2 1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                </template>
            </StatCard>
            <StatCard :label="t('shell.app.kpiUnsigned')" :hint="t('shell.app.statPending')">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M5 19l1-4 9-9 3 3-9 9-4 1Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    </svg>
                </template>
            </StatCard>
            <StatCard :label="t('shell.app.kpiThreads')" :hint="t('shell.app.statPending')">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M4 6h16v10H9l-5 4V6Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    </svg>
                </template>
            </StatCard>
        </section>

        <!-- Two-column body: schedule (empty) + approvals/quick links. -->
        <section class="mt-6 grid gap-5 lg:grid-cols-[1.6fr_1fr]">
            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('shell.app.scheduleTitle') }}</h2>
                    <Link href="/scheduling/day-board" class="text-sm font-medium text-euca-700 transition hover:text-euca-800">
                        {{ t('shell.app.openDayBoard') }} →
                    </Link>
                </div>
                <div class="mt-6 flex flex-col items-center justify-center rounded-xl bg-euca-50/60 px-6 py-12 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-euca-100 text-euca-700">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M5 19C5 11 9 5.2 19 4.8c-.4 9.2-5 13.8-14 14.2z" fill="currentColor" />
                        </svg>
                    </span>
                    <p class="mt-4 max-w-sm text-sm text-ink-muted">{{ t('shell.app.scheduleEmpty') }}</p>
                    <Link
                        href="/scheduling/day-board"
                        class="btn-glow mt-5 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold"
                    >
                        {{ t('shell.app.openDayBoard') }}
                    </Link>
                </div>
            </div>

            <div class="space-y-5">
                <div class="glass-card p-6">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('shell.app.approvalsTitle') }}</h2>
                    <p class="mt-4 text-sm text-ink-subtle">{{ t('shell.app.approvalsEmpty') }}</p>
                </div>
                <div class="glass-card overflow-hidden p-2">
                    <Link
                        href="/patients/register"
                        class="flex items-center justify-between rounded-xl px-4 py-3 text-sm font-medium text-ink transition hover:bg-euca-50"
                    >
                        {{ t('shell.app.registerPatient') }}
                        <span class="text-ink-subtle">›</span>
                    </Link>
                    <Link
                        href="/nursing/dispatch"
                        class="flex items-center justify-between rounded-xl px-4 py-3 text-sm font-medium text-ink transition hover:bg-euca-50"
                    >
                        {{ t('shell.app.nursingDispatch') }}
                        <span class="text-ink-subtle">›</span>
                    </Link>
                    <Link
                        href="/comms/inbox"
                        class="flex items-center justify-between rounded-xl px-4 py-3 text-sm font-medium text-ink transition hover:bg-euca-50"
                    >
                        {{ t('shell.app.unifiedInbox') }}
                        <span class="text-ink-subtle">›</span>
                    </Link>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
