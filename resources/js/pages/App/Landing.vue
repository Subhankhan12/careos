<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type Operational = {
    appointments: number;
    by_status: Record<string, number>;
    waiting: number;
    no_shows: number;
    scheduled: number;
    active_patients: number;
};
type Financial = { outstanding_minor: number; currency: string };

const props = defineProps<{
    today: string;
    operational: Operational | null;
    financial: Financial | null;
}>();

// Parse the server date as LOCAL midnight ('T00:00:00', no Z) so the weekday/label
// never shifts a day in a behind-UTC browser.
const todayLabel = computed(() => {
    try {
        return new Intl.DateTimeFormat(locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${props.today}T00:00:00`));
    } catch {
        return props.today;
    }
});

const completed = computed(() => props.operational?.by_status?.completed ?? 0);
const hasAppointments = computed(() => (props.operational?.appointments ?? 0) > 0);

function money(minor: number, currency: string): string {
    return `${(minor / 100).toFixed(2)} ${currency}`;
}
</script>

<template>
    <AppLayout>
        <Head :title="t('shell.app.title')" />

        <!-- Greeting hero + the deep-eucalyptus "today at a glance" tile (now real). -->
        <section class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface/60 px-3 py-1 text-xs font-medium text-ink-muted">
                    <span class="h-1.5 w-1.5 rounded-full bg-euca-600"></span>{{ todayLabel }}
                </span>
                <h1 class="mt-3 max-w-xl text-4xl font-semibold leading-[1.08] tracking-tight text-ink sm:text-5xl">
                    {{ t('shell.app.welcome') }}
                </h1>
                <div class="mt-6 flex flex-wrap gap-3">
                    <Link href="/patients/register" class="btn-glow inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold">
                        {{ t('shell.app.registerPatient') }}
                    </Link>
                    <Link href="/scheduling/day-board" class="inline-flex items-center gap-2 rounded-xl border border-line bg-surface/70 px-4 py-2.5 text-sm font-semibold text-ink transition hover:bg-surface-2">
                        {{ t('shell.app.openDayBoard') }}
                    </Link>
                </div>
            </div>

            <div class="euca-tile-dark relative w-full max-w-sm overflow-hidden p-6">
                <svg class="pointer-events-none absolute -right-6 -top-6 h-32 w-32 text-euca-50 opacity-10" viewBox="0 0 200 200" fill="none" aria-hidden="true">
                    <path d="M40 170C40 100 70 55 160 45c-4 78-44 118-120 125z" fill="currentColor" />
                </svg>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('shell.app.glanceTitle') }}</p>
                <p class="mt-3 text-2xl font-semibold text-euca-50">{{ todayLabel }}</p>
                <p v-if="operational" class="mt-2 max-w-[16rem] text-sm text-euca-200">
                    {{ t('shell.app.glanceSummary', { appointments: operational.appointments, waiting: operational.waiting, noShows: operational.no_shows }) }}
                </p>
                <p v-else class="mt-2 max-w-[16rem] text-sm text-euca-200">{{ t('shell.app.glanceWelcome') }}</p>
            </div>
        </section>

        <!-- KPI row — real figures from MetricsService, shown per the actor's permissions. -->
        <section v-if="operational || financial" class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <template v-if="operational">
                <StatCard :label="t('shell.app.kpiAppointments')" :value="String(operational.appointments)" :hint="t('shell.app.hintCompleted', { count: completed })">
                    <template #icon>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <rect x="3.5" y="5" width="17" height="15" rx="2.5" stroke="currentColor" stroke-width="1.6" />
                            <path d="M3.5 9h17M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                    </template>
                </StatCard>
                <StatCard :label="t('shell.app.kpiWaiting')" :value="String(operational.waiting)" :hint="t('shell.app.hintCheckedIn')">
                    <template #icon>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="9" cy="8" r="3" stroke="currentColor" stroke-width="1.6" />
                            <path d="M4 19c.6-3 2.6-4.5 5-4.5s4.4 1.5 5 4.5M17 8v3l2 1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                    </template>
                </StatCard>
                <StatCard :label="t('shell.app.kpiNoShows')" :value="String(operational.no_shows)" :hint="t('shell.app.hintScheduled', { count: operational.scheduled })">
                    <template #icon>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.6" />
                            <path d="M9 9l6 6M15 9l-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                    </template>
                </StatCard>
                <StatCard :label="t('shell.app.kpiActive')" :value="String(operational.active_patients)" :hint="t('shell.app.hintSeenToday')">
                    <template #icon>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="8" cy="8" r="3" stroke="currentColor" stroke-width="1.6" />
                            <path d="M2 19c.7-3.2 2.8-5 6-5s5.3 1.8 6 5M16 6a3 3 0 0 1 0 6M22 19c-.5-2.3-1.8-3.8-4-4.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                    </template>
                </StatCard>
            </template>
            <StatCard v-if="financial" :label="t('shell.app.kpiOutstanding')" :value="money(financial.outstanding_minor, financial.currency)" :hint="t('shell.app.hintPractice')">
                <template #icon>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.6" />
                        <path d="M12 8v8M9.5 9.5c0-1 1-1.5 2.5-1.5s2.5.6 2.5 1.6c0 2.2-5 1.2-5 3.4 0 1 1 1.6 2.5 1.6s2.5-.5 2.5-1.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                    </svg>
                </template>
            </StatCard>
        </section>

        <!-- Two-column body: today's schedule (real count) + quick links. -->
        <section class="mt-6 grid gap-5 lg:grid-cols-[1.6fr_1fr]">
            <div class="glass-card p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('shell.app.scheduleTitle') }}</h2>
                    <Link href="/scheduling/day-board" class="text-sm font-medium text-euca-700 transition hover:text-euca-800">
                        {{ t('shell.app.openDayBoard') }} →
                    </Link>
                </div>

                <!-- Real count when the actor can see it; a genuine empty state at zero. -->
                <div v-if="operational && hasAppointments" class="mt-6 rounded-xl bg-euca-50/60 p-5">
                    <p class="text-3xl font-semibold text-ink">{{ t('shell.app.scheduleCount', { count: operational.appointments }) }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span v-for="(count, status) in operational.by_status" :key="status" v-show="count > 0" class="inline-flex items-center gap-1.5 rounded-full bg-white/70 px-3 py-1 text-xs text-ink-muted">
                            <span class="font-medium">{{ t(`reporting.apptStatus.${status}`) }}</span>
                            <span class="tabular-nums font-semibold text-ink">{{ count }}</span>
                        </span>
                    </div>
                    <Link href="/scheduling/day-board" class="btn-glow mt-5 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold">
                        {{ t('shell.app.openDayBoard') }}
                    </Link>
                </div>
                <div v-else class="mt-6 flex flex-col items-center justify-center rounded-xl bg-euca-50/60 px-6 py-12 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-euca-100 text-euca-700">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M5 19C5 11 9 5.2 19 4.8c-.4 9.2-5 13.8-14 14.2z" fill="currentColor" />
                        </svg>
                    </span>
                    <p class="mt-4 max-w-sm text-sm text-ink-muted">{{ operational ? t('shell.app.scheduleNone') : t('shell.app.scheduleGoBoard') }}</p>
                    <Link href="/scheduling/day-board" class="btn-glow mt-5 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold">
                        {{ t('shell.app.openDayBoard') }}
                    </Link>
                </div>
            </div>

            <div class="glass-card overflow-hidden p-2">
                <p class="px-4 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('shell.app.quickActions') }}</p>
                <Link href="/patients/register" class="flex items-center justify-between rounded-xl px-4 py-3 text-sm font-medium text-ink transition hover:bg-euca-50">
                    {{ t('shell.app.registerPatient') }}<span class="text-ink-subtle">›</span>
                </Link>
                <Link href="/nursing/dispatch" class="flex items-center justify-between rounded-xl px-4 py-3 text-sm font-medium text-ink transition hover:bg-euca-50">
                    {{ t('shell.app.nursingDispatch') }}<span class="text-ink-subtle">›</span>
                </Link>
                <Link href="/comms/inbox" class="flex items-center justify-between rounded-xl px-4 py-3 text-sm font-medium text-ink transition hover:bg-euca-50">
                    {{ t('shell.app.unifiedInbox') }}<span class="text-ink-subtle">›</span>
                </Link>
            </div>
        </section>
    </AppLayout>
</template>
