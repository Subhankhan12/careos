<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BrandMark from '@/Components/BrandMark.vue';

const { t } = useI18n();
const page = usePage();

const nav = [
    { key: 'portal.nav.home', href: '/portal' },
    { key: 'portal.nav.appointments', href: '/portal/appointments' },
    { key: 'portal.nav.documents', href: '/portal/documents' },
    { key: 'portal.nav.messages', href: '/portal/messages' },
    { key: 'portal.nav.invoices', href: '/portal/invoices' },
    { key: 'portal.nav.consents', href: '/portal/consents' },
    { key: 'portal.nav.telehealth', href: '/portal/telehealth' },
];

function isActive(href: string): boolean {
    const url = page.url;
    return href === '/portal' ? url === '/portal' || url.startsWith('/portal?') : url.startsWith(href);
}

function logout(): void {
    router.post('/portal/logout');
}
</script>

<template>
    <!-- The patient portal has its OWN layout — never the staff shell — with a softer,
         larger, reassuring variant (16px base, roomier cards, bigger touch targets). -->
    <div class="euca-wash relative min-h-full text-base text-ink">
        <header class="sticky top-0 z-30 px-4 pt-4">
            <div class="mx-auto max-w-6xl">
                <div class="glass-card flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
                    <div class="flex items-center gap-2.5">
                        <BrandMark size="sm" />
                        <span class="text-base font-semibold tracking-tight text-ink">{{ t('portal.title') }}</span>
                    </div>

                    <nav class="flex flex-wrap items-center gap-1">
                        <Link
                            v-for="item in nav"
                            :key="item.href"
                            :href="item.href"
                            class="rounded-full px-3.5 py-2 text-sm font-medium transition"
                            :class="isActive(item.href) ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                        >
                            {{ t(item.key) }}
                        </Link>
                    </nav>

                    <div class="flex items-center gap-2">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-euca-200 text-euca-900" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="8.5" r="3.5" stroke="currentColor" stroke-width="1.6" />
                                <path d="M5 19c1-3.4 3.7-5 7-5s6 1.6 7 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                            </svg>
                        </span>
                        <button
                            type="button"
                            class="rounded-full px-3.5 py-2 text-sm font-medium text-ink-muted transition hover:bg-euca-50 hover:text-ink"
                            @click="logout"
                        >
                            {{ t('portal.signOut') }}
                        </button>
                    </div>
                </div>
            </div>
        </header>
        <main class="relative z-10 mx-auto max-w-6xl px-4 py-8 sm:py-10">
            <slot />
        </main>
    </div>
</template>
