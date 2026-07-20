<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t } = useI18n();

interface Session {
    id: string;
    patientName: string | null;
    provider: string;
    status: string;
    createdAt: string | null;
    tokenUrl: string;
}

defineProps<{ sessions: Session[] }>();

// The staff join token lives only in memory for the moment of joining — the same
// posture as the portal side; it is never persisted here or on the server.
const joined = reactive<Record<string, { token: string; room: string; role: string; expires_at: string } | undefined>>({});

async function join(session: Session): Promise<void> {
    const response = await fetch(session.tokenUrl, {
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

function dateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}
</script>

<template>
    <AppLayout>
        <Head :title="t('staffTelehealth.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('staffTelehealth.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('staffTelehealth.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('staffTelehealth.subtitle') }}</p>
            </div>

            <!-- The "not recorded" discipline, displayed prominently (D-G2/D-G3). -->
            <div class="flex items-start gap-2 rounded-2xl border border-euca-200 bg-euca-50 p-4 text-sm text-euca-800">
                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                    <path d="M8 12h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                {{ t('staffTelehealth.notRecorded') }}
            </div>

            <div v-if="sessions.length" class="space-y-4">
                <div v-for="session in sessions" :key="session.id" class="glass-card p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-euca-50 text-euca-700">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <rect x="3.5" y="6" width="12" height="12" rx="2.5" stroke="currentColor" stroke-width="1.6" />
                                    <path d="M15.5 10l5-3v10l-5-3" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                </svg>
                            </span>
                            <div>
                                <p class="font-semibold text-ink">{{ session.patientName ?? t('staffTelehealth.aPatient') }}</p>
                                <p class="text-sm text-ink-muted">
                                    {{ dateTime(session.createdAt) }} ·
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold" :class="session.status === 'active' ? 'bg-success-soft text-success' : 'bg-euca-50 text-euca-700'">{{ t(`staffTelehealth.status.${session.status}`) }}</span>
                                </p>
                            </div>
                        </div>
                        <button
                            v-if="!joined[session.id]"
                            type="button"
                            class="btn-glow inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold"
                            @click="join(session)"
                        >
                            {{ t('staffTelehealth.join') }}
                        </button>
                    </div>
                    <div
                        v-if="joined[session.id]"
                        class="mt-4 flex items-start gap-2 rounded-xl border border-success/30 bg-success-soft p-4 text-sm text-ink"
                    >
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M5 12.5l4 4 10-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        {{ t('staffTelehealth.joined') }}
                    </div>
                </div>
            </div>
            <p v-else class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-muted">{{ t('staffTelehealth.empty') }}</p>
        </div>
    </AppLayout>
</template>
