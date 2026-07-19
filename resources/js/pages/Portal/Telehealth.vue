<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const { t } = useI18n();

defineProps<{
    sessions: Array<{ id: string; provider: string; status: string; created_at: string | null; token_url: string }>;
}>();

// Tokens live only in memory for the moment of joining — never persisted.
const joined = reactive<Record<string, { token: string; room: string; expires_at: string } | undefined>>({});

async function join(session: { id: string; token_url: string }): Promise<void> {
    const response = await fetch(session.token_url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
        },
    });

    if (response.ok) {
        joined[session.id] = await response.json();
    }
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.telehealth.title')" />

        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('portal.telehealth.eyebrow') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{{ t('portal.telehealth.title') }}</h1>
        <p class="mt-1 max-w-2xl text-ink-muted">{{ t('portal.telehealth.subtitle') }}</p>

        <div v-if="sessions.length" class="mt-6 space-y-4">
            <div v-for="session in sessions" :key="session.id" class="glass-card p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-euca-50 text-euca-700">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="3.5" y="6" width="12" height="12" rx="2.5" stroke="currentColor" stroke-width="1.6" />
                                <path d="M15.5 10l5-3v10l-5-3" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            </svg>
                        </span>
                        <div>
                            <p class="font-semibold text-ink">{{ t('portal.telehealth.sessionTitle') }}</p>
                            <p class="text-sm text-ink-muted">{{ session.created_at ?? '—' }} · {{ session.status }}</p>
                        </div>
                    </div>
                    <button
                        v-if="!joined[session.id]"
                        type="button"
                        class="btn-glow inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold"
                        @click="join(session)"
                    >
                        {{ t('portal.telehealth.join') }}
                    </button>
                </div>
                <div
                    v-if="joined[session.id]"
                    class="mt-4 flex items-start gap-2 rounded-xl border border-success/30 bg-success-soft p-4 text-sm text-ink"
                >
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M5 12.5l4 4 10-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    {{ t('portal.telehealth.joined') }}
                </div>
            </div>
        </div>
        <p v-else class="mt-6 text-ink-muted">{{ t('portal.telehealth.empty') }}</p>

        <p v-if="sessions.length" class="mt-4 text-sm text-ink-subtle">{{ t('portal.telehealth.footer') }}</p>
    </PortalLayout>
</template>
