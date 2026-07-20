<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BrandMark from '@/Components/BrandMark.vue';

const { t } = useI18n();
const page = usePage();

const user = computed(
    () =>
        (
            page.props.auth as {
                user: { name: string; isSuperAdmin: boolean; permissions?: Record<string, boolean> } | null;
            }
        ).user,
);
const isSuperAdmin = computed(() => user.value?.isSuperAdmin ?? false);
const permissions = computed(() => user.value?.permissions ?? {});

const initials = computed(() => {
    const name = user.value?.name?.trim() ?? '';
    if (!name) return '';
    const parts = name.split(/\s+/);
    return (parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1][0] ?? '') : '');
});

// `permission` mirrors the server-side Gate on each route's controller. It only hides
// links a role can't use — the server Gate stays authoritative, so a hidden route is
// still blocked by URL, and a shown one still 403s if the user truly lacks access.
// Dashboard has no permission (any authenticated staff member reaches /app).
const nav: { key: string; href: string; permission?: string }[] = [
    { key: 'app.nav.dashboard', href: '/app' },
    { key: 'app.nav.patients', href: '/patients', permission: 'patient.view' },
    { key: 'app.nav.scheduling', href: '/scheduling/day-board', permission: 'appointment.manage' },
    { key: 'app.nav.nursing', href: '/nursing/dispatch', permission: 'dispatch.manage' },
    { key: 'app.nav.inbox', href: '/comms/inbox', permission: 'comms.manage' },
    { key: 'app.nav.billing', href: '/billing/invoices', permission: 'billing.view' },
    { key: 'app.nav.reporting', href: '/reporting', permission: 'reporting.view' },
    { key: 'app.nav.governance', href: '/governance', permission: 'audit.view' },
    { key: 'app.nav.approvals', href: '/governance/approvals', permission: 'ai.manage' },
    { key: 'app.nav.settings', href: '/settings', permission: 'admin.manage' },
];

const visibleNav = computed(() => nav.filter((item) => !item.permission || permissions.value[item.permission] === true));

function isActive(href: string): boolean {
    const url = page.url;
    return href === '/app' ? url === '/app' || url === '/admin' : url.startsWith(href);
}

function signOut(): void {
    router.post('/logout');
}
</script>

<template>
    <div class="euca-wash relative min-h-full">
        <header class="sticky top-0 z-30 px-4 pt-4">
            <div class="mx-auto max-w-7xl">
                <div class="glass-card flex h-16 items-center justify-between gap-4 px-4 sm:px-5">
                    <!-- Identity cluster: brand + platform scope for super-admins. -->
                    <div class="flex items-center gap-3">
                        <BrandMark size="sm" />
                        <div class="flex flex-col leading-tight">
                            <span class="text-sm font-semibold tracking-tight text-ink">{{ t('app.name') }}</span>
                            <span v-if="isSuperAdmin" class="text-xs text-ink-subtle">{{ t('shell.platformScope') }}</span>
                        </div>
                    </div>

                    <!-- Center pill nav on an ivory well; active = gradient white pill. -->
                    <nav class="hidden items-center gap-1 rounded-full bg-euca-50/80 p-1 md:flex">
                        <Link
                            v-for="item in visibleNav"
                            :key="item.href"
                            :href="item.href"
                            class="rounded-full px-3.5 py-1.5 text-sm font-medium transition"
                            :class="isActive(item.href) ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                        >
                            {{ t(item.key) }}
                        </Link>
                    </nav>

                    <!-- Right icon cluster + avatar + sign out. -->
                    <div class="flex items-center gap-1.5 sm:gap-2">
                        <button
                            type="button"
                            :aria-label="t('shell.search')"
                            class="hidden h-9 w-9 items-center justify-center rounded-full text-ink-muted transition hover:bg-euca-50 hover:text-ink sm:flex"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="1.6" />
                                <path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            :aria-label="t('shell.notifications')"
                            class="relative hidden h-9 w-9 items-center justify-center rounded-full text-ink-muted transition hover:bg-euca-50 hover:text-ink sm:flex"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path
                                    d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6Z"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linejoin="round"
                                />
                                <path d="M10 19a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                            </svg>
                            <span class="absolute right-2 top-2 h-1.5 w-1.5 rounded-full bg-euca-600"></span>
                        </button>
                        <span
                            v-if="initials"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-euca-300 text-xs font-semibold text-euca-900"
                            :title="user?.name"
                        >
                            {{ initials }}
                        </span>
                        <button
                            type="button"
                            :aria-label="t('app.signOut')"
                            class="flex h-9 w-9 items-center justify-center rounded-full text-ink-muted transition hover:bg-euca-50 hover:text-ink"
                            @click="signOut"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path
                                    d="M15 12H4m0 0 3.5-3.5M4 12l3.5 3.5M14 5h4a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-4"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </header>
        <main class="relative z-10 mx-auto max-w-7xl px-4 py-8 sm:py-10">
            <slot />
        </main>
    </div>
</template>
