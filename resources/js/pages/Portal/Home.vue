<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

const props = defineProps<{
    nextAppointment: { id: string; service: string | null; starts_at: string; status: string } | null;
    unreadMessages: number;
    outstandingBalanceMinor: number;
}>();

const today = computed(() => {
    try {
        return new Intl.DateTimeFormat(locale.value, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
            .format(new Date())
            .toUpperCase();
    } catch {
        return '';
    }
});

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return t('portal.home.greeting.morning');
    if (h < 18) return t('portal.home.greeting.afternoon');
    return t('portal.home.greeting.evening');
});

function formatWhen(value: string): string {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(locale.value, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            hour: '2-digit',
            minute: '2-digit',
        }).format(d);
    } catch {
        return value;
    }
}

const balance = computed(() => (props.outstandingBalanceMinor / 100).toFixed(2));
const hasBalance = computed(() => props.outstandingBalanceMinor > 0);

const quickActions = [
    { href: '/portal/appointments', title: 'portal.home.bookVisit', sub: 'portal.home.bookVisitSub', icon: 'calendar' },
    { href: '/portal/messages', title: 'portal.home.messageUs', sub: 'portal.home.messageUsSub', icon: 'chat' },
    { href: '/portal/documents', title: 'portal.nav.documents', sub: 'portal.home.documentsSub', icon: 'doc' },
    { href: '/portal/telehealth', title: 'portal.nav.telehealth', sub: 'portal.home.telehealthSub', icon: 'video' },
];
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.nav.home')" />

        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ today }}</p>
        <h1 class="mt-1 text-3xl font-semibold tracking-tight text-ink">{{ greeting }}</h1>

        <div class="mt-6 grid gap-5 lg:grid-cols-[1.5fr_1fr]">
            <!-- The one dark tile: next appointment. -->
            <div class="euca-tile-dark relative overflow-hidden p-6 sm:p-7">
                <svg
                    class="pointer-events-none absolute -right-8 -top-8 h-40 w-40 text-euca-50 opacity-10"
                    viewBox="0 0 200 200"
                    fill="none"
                    aria-hidden="true"
                >
                    <path d="M40 170C40 100 70 55 160 45c-4 78-44 118-120 125z" fill="currentColor" />
                </svg>
                <p class="relative text-sm font-medium text-euca-200">{{ t('portal.home.nextAppointment') }}</p>
                <template v-if="nextAppointment">
                    <p class="relative mt-3 text-2xl font-semibold text-euca-50">{{ nextAppointment.service ?? '—' }}</p>
                    <p class="relative mt-1 text-euca-100">{{ formatWhen(nextAppointment.starts_at) }}</p>
                    <Link
                        href="/portal/appointments"
                        class="relative mt-5 inline-flex items-center gap-1.5 rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25"
                    >
                        {{ t('portal.home.viewAppointments') }} →
                    </Link>
                </template>
                <template v-else>
                    <p class="relative mt-3 text-lg text-euca-100">{{ t('portal.home.none') }}</p>
                    <Link
                        href="/portal/appointments"
                        class="relative mt-5 inline-flex items-center gap-1.5 rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25"
                    >
                        {{ t('portal.home.bookVisit') }} →
                    </Link>
                </template>
            </div>

            <div class="grid gap-5">
                <Link href="/portal/messages" class="glass-card glass-card-hover block p-6">
                    <p class="text-sm font-medium text-ink-muted">{{ t('portal.home.unreadMessages') }}</p>
                    <p class="mt-2 text-3xl font-semibold text-ink">{{ unreadMessages }}</p>
                    <p class="mt-1 text-sm text-euca-700">{{ t('portal.home.viewMessages') }} →</p>
                </Link>
                <div class="glass-card p-6">
                    <p class="text-sm font-medium text-ink-muted">{{ t('portal.home.outstandingBalance') }}</p>
                    <p v-if="hasBalance" class="mt-2 text-3xl font-semibold text-ink">{{ balance }}</p>
                    <span
                        v-else
                        class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-euca-100 px-3 py-1 text-sm font-semibold text-euca-800"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M5 12.5l4 4 10-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        {{ t('portal.home.noPaymentDue') }}
                    </span>
                    <p class="mt-2 text-xs text-ink-subtle">{{ t('portal.home.balanceNote') }}</p>
                </div>
            </div>
        </div>

        <!-- Quick actions — navigation only, no new server data. -->
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Link
                v-for="action in quickActions"
                :key="action.href"
                :href="action.href"
                class="glass-card glass-card-hover block p-5"
            >
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-euca-50 text-euca-700">
                    <svg v-if="action.icon === 'calendar'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <rect x="3.5" y="5" width="17" height="15" rx="2.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="M3.5 9h17M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <svg v-else-if="action.icon === 'chat'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M4 6h16v10H9l-5 4V6Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    </svg>
                    <svg v-else-if="action.icon === 'doc'" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M7 3h7l4 4v14H7V3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        <path d="M14 3v4h4" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    </svg>
                    <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <rect x="3.5" y="6" width="12" height="12" rx="2.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="M15.5 10l5-3v10l-5-3" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    </svg>
                </span>
                <p class="mt-3 font-semibold text-ink">{{ t(action.title) }}</p>
                <p class="text-sm text-ink-muted">{{ t(action.sub) }}</p>
            </Link>
        </div>
    </PortalLayout>
</template>
