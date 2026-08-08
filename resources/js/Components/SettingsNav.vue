<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();

// The wireframe's sticky sub-nav, wired to the live Settings surfaces (P1–P6). "Online booking"
// has no dedicated settings page — the public-booking slug + patient-facing profile live on the
// Practice page (/settings), so it links there honestly rather than to a dead section.
const items = [
    { key: 'practice', href: '/settings' },
    { key: 'scheduling', href: '/admin/scheduling' },
    { key: 'onlineBooking', href: '/settings' },
    { key: 'agents', href: '/admin/agents' },
    { key: 'notifications', href: '/admin/notifications' },
    { key: 'team', href: '/admin/roles' },
    { key: 'security', href: '/admin/security' },
];

const url = computed(() => page.url.split('?')[0]);

const matches = (href: string): boolean => (href === '/settings' ? url.value === '/settings' : url.value.startsWith(href));

// First-match-wins so a shared href (Practice / Online booking → /settings) highlights only once.
const activeIndex = computed(() => items.findIndex((i) => matches(i.href)));
</script>

<template>
    <nav class="lg:sticky lg:top-24" :aria-label="t('settingsNav.heading')">
        <div class="glass-card p-2">
            <p class="px-3 py-2 text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('settingsNav.heading') }}</p>
            <ul class="space-y-0.5">
                <li v-for="(item, index) in items" :key="item.key + index">
                    <Link
                        :href="item.href"
                        class="block rounded-xl px-3 py-2 text-sm font-medium transition"
                        :class="index === activeIndex ? 'nav-pill-active text-ink' : 'text-ink-muted hover:bg-euca-50 hover:text-ink'"
                        :aria-current="index === activeIndex ? 'page' : undefined"
                    >
                        {{ t(`settingsNav.${item.key}`) }}
                    </Link>
                </li>
            </ul>
        </div>
    </nav>
</template>
