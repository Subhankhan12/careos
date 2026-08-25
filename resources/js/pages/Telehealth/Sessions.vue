<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';

const { t } = useI18n();

/*
 * A recorded join. `leftAt` being null does NOT mean the person is in the call — a dropped
 * connection never reports a leave — so this page renders join/leave times and never a live state.
 */
interface Participant {
    id: string;
    type: string;
    joinedAt: string;
    leftAt: string | null;
}

interface Session {
    id: string;
    patientName: string | null;
    provider: string;
    status: string;
    state: string;
    createdAt: string | null;
    startedAt: string | null;
    endedAt: string | null;
    appointmentAt: string | null;
    hasEncounter: boolean;
    participants: Participant[];
    joinUrl: string;
    joinable: boolean;
}

defineProps<{
    sessions: Session[];
    counts: { scheduled: number; joined: number; ended: number };
    filters: { state: string | null };
    providerConfigured: boolean;
}>();

const stateFilters = ['scheduled', 'joined', 'ended'] as const;

// The declined affordances, iterated — so removing a key removes the rendered line too, and the
// test that asserts the RENDERED output notices (GOV.P3).
const omittedKeys = ['recording', 'transcript', 'quality', 'presence'] as const;

function setState(state: string | null): void {
    router.get('/telehealth', state ? { state } : {}, { preserveState: false, replace: true });
}

function dateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}
function time(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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

            <!-- Said up front, not discovered when a Join fails (D-176). -->
            <div v-if="!providerConfigured" class="flex items-start gap-2 rounded-2xl border border-warning/40 bg-warning-soft p-4 text-sm text-ink">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 4 21 19H3L12 4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    <path d="M12 10v4M12 16.5v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                {{ t('staffTelehealth.notConfigured') }}
            </div>

            <!-- Plain counts of rows that exist, counted server-side over the whole record (D-166/D-174). -->
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard :label="t('staffTelehealth.counts.scheduled')" :value="String(counts.scheduled)" :hint="t('staffTelehealth.counts.scheduledHint')" />
                <StatCard :label="t('staffTelehealth.counts.joined')" :value="String(counts.joined)" :hint="t('staffTelehealth.counts.joinedHint')" />
                <StatCard :label="t('staffTelehealth.counts.ended')" :value="String(counts.ended)" :hint="t('staffTelehealth.counts.endedHint')" />
            </div>

            <!-- Filters over REAL attributes only: the recorded status and the recorded joins. -->
            <div class="inline-flex flex-wrap items-center gap-1 rounded-full bg-euca-50/70 p-1">
                <button
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                    :class="filters.state === null ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                    @click="setState(null)"
                >
                    {{ t('staffTelehealth.states.all') }}
                </button>
                <button
                    v-for="s in stateFilters"
                    :key="s"
                    type="button"
                    class="rounded-full px-3 py-1.5 text-xs font-medium transition"
                    :class="filters.state === s ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                    @click="setState(s)"
                >
                    {{ t(`staffTelehealth.states.${s}`) }}
                </button>
            </div>

            <div v-if="sessions.length" class="space-y-4">
                <div v-for="session in sessions" :key="session.id" class="glass-card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-euca-50 text-euca-700">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <rect x="3.5" y="6" width="12" height="12" rx="2.5" stroke="currentColor" stroke-width="1.6" />
                                    <path d="M15.5 10l5-3v10l-5-3" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                </svg>
                            </span>
                            <div>
                                <p class="font-semibold text-ink">{{ session.patientName ?? t('staffTelehealth.aPatient') }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-ink-muted">
                                    <!-- The state is DERIVED from recorded rows; no tint implies urgency (D-169). -->
                                    <span class="inline-flex items-center rounded-full bg-euca-50 px-2 py-0.5 text-xs font-semibold text-euca-700">
                                        {{ t(`staffTelehealth.states.${session.state}`) }}
                                    </span>
                                    <span v-if="session.appointmentAt">{{ t('staffTelehealth.appointmentAt') }}: {{ dateTime(session.appointmentAt) }}</span>
                                </p>
                                <p class="mt-1 text-xs text-ink-subtle">
                                    {{ t('staffTelehealth.openedAt') }}: {{ dateTime(session.createdAt) }}
                                    <template v-if="session.startedAt"> · {{ t('staffTelehealth.startedAt') }}: {{ dateTime(session.startedAt) }}</template>
                                    <template v-if="session.endedAt"> · {{ t('staffTelehealth.endedAt') }}: {{ dateTime(session.endedAt) }}</template>
                                </p>
                            </div>
                        </div>
                        <a
                            v-if="session.joinable && providerConfigured"
                            :href="`/telehealth/${session.id}`"
                            class="btn-glow inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold"
                        >
                            {{ t('staffTelehealth.join') }}
                        </a>
                    </div>

                    <!-- Recorded joins — times, never a live state. -->
                    <div class="mt-4 border-t border-line/70 pt-3">
                        <p class="text-xs font-medium text-ink-muted">{{ t('staffTelehealth.participants') }}</p>
                        <ul v-if="session.participants.length" class="mt-1.5 space-y-1">
                            <li v-for="p in session.participants" :key="p.id" class="text-sm text-ink">
                                {{ t(`staffTelehealth.party.${p.type}`) }} ·
                                {{ t('staffTelehealth.joinedAt', { time: time(p.joinedAt) }) }}
                                <span class="text-ink-subtle">
                                    · {{ p.leftAt ? t('staffTelehealth.leftAt', { time: time(p.leftAt) }) : t('staffTelehealth.stillOpen') }}
                                </span>
                            </li>
                        </ul>
                        <p v-else class="mt-1 text-sm text-ink-subtle">{{ t('staffTelehealth.noParticipants') }}</p>
                    </div>
                </div>
            </div>
            <p v-else class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-muted">{{ t('staffTelehealth.empty') }}</p>

            <!-- Presence, stated rather than left to assumption. -->
            <div class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">{{ t('staffTelehealth.presence.title') }}</p>
                <p class="mt-2 text-sm text-ink-muted">{{ t('staffTelehealth.presence.tracked') }}</p>
                <p class="mt-1.5 text-sm text-ink-muted">{{ t('staffTelehealth.presence.notTracked') }}</p>
            </div>

            <!-- What the design offers that this build cannot honestly back. -->
            <div class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">{{ t('staffTelehealth.omitted.title') }}</p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('staffTelehealth.omitted.subtitle') }}</p>
                <ul class="mt-3 space-y-1.5 text-sm text-ink-muted">
                    <li v-for="key in omittedKeys" :key="key" class="flex items-start gap-2">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-ink-subtle" />
                        <span>{{ t(`staffTelehealth.omitted.${key}`) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
