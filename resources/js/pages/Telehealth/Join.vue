<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

const { t } = useI18n();

interface Participant {
    id: string;
    type: string;
    joinedAt: string;
    leftAt: string | null;
}

interface Session {
    id: string;
    patientName: string | null;
    state: string;
    appointmentAt: string | null;
    startedAt: string | null;
    participants: Participant[];
    joinUrl: string;
    joinable: boolean;
}

const props = defineProps<{ session: Session; providerConfigured: boolean }>();

/*
 * THE DEVICE CHECK IS ENTIRELY LOCAL.
 *
 * It calls `getUserMedia` in this browser, notes whether a camera and a microphone were found, and
 * stops the tracks again immediately. The result is shown to the clinician and to nobody else: it is
 * never posted to CareOS, never recorded against the session, and never sent with the join request.
 * The server's answer to "may this person join?" is identical whether this ran, passed or failed —
 * which is what makes a forged "pre-check passed" claim worthless.
 *
 * It reports AVAILABILITY only. No bandwidth figure, no signal bars, no quality grade (D-169): we
 * can honestly say a camera was found; we cannot honestly score a call that has not happened.
 */
const checking = ref(false);
const checked = ref(false);
const camera = ref<boolean | null>(null);
const microphone = ref<boolean | null>(null);
const denied = ref(false);

async function runDeviceCheck(): Promise<void> {
    checking.value = true;
    denied.value = false;

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        camera.value = stream.getVideoTracks().length > 0;
        microphone.value = stream.getAudioTracks().length > 0;
        // Release the devices straight away — nothing is captured, nothing is kept.
        stream.getTracks().forEach((track) => track.stop());
    } catch {
        denied.value = true;
        camera.value = false;
        microphone.value = false;
    } finally {
        checking.value = false;
        checked.value = true;
    }
}

const joinResult = ref<{ room: string; role: string; expires_at: string } | null>(null);

/**
 * The join itself goes through the EXISTING token endpoint, unchanged. The token lives in this
 * function's scope for the moment of joining and is never stored — not in localStorage, not in a
 * cookie, not in the page's state.
 */
async function join(): Promise<void> {
    const response = await fetch(props.session.joinUrl, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
        },
    });

    if (response.ok) {
        const payload = await response.json();
        // Deliberately NOT keeping payload.token: the room and expiry are all the page needs to show.
        joinResult.value = { room: payload.room, role: payload.role, expires_at: payload.expires_at };
    }
}

function dateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}
</script>

<template>
    <AppLayout>
        <Head :title="t('staffTelehealth.preJoin.title')" />
        <div class="mx-auto max-w-3xl space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('staffTelehealth.preJoin.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('staffTelehealth.preJoin.title') }}</h1>
            </div>

            <!-- Who you are joining, and when it was scheduled — recorded facts. -->
            <div class="glass-card p-5">
                <p class="text-lg font-semibold text-ink">{{ session.patientName ?? t('staffTelehealth.aPatient') }}</p>
                <p class="mt-1 text-sm text-ink-muted">
                    {{ t('staffTelehealth.appointmentAt') }}: {{ dateTime(session.appointmentAt) }}
                    · {{ t(`staffTelehealth.states.${session.state}`) }}
                </p>

                <div class="mt-4 border-t border-line/70 pt-3">
                    <p class="text-xs font-medium text-ink-muted">{{ t('staffTelehealth.participants') }}</p>
                    <ul v-if="session.participants.length" class="mt-1.5 space-y-1 text-sm text-ink">
                        <li v-for="p in session.participants" :key="p.id">
                            {{ t(`staffTelehealth.party.${p.type}`) }} ·
                            {{ t('staffTelehealth.joinedAt', { time: new Date(p.joinedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }) }}
                        </li>
                    </ul>
                    <p v-else class="mt-1 text-sm text-ink-subtle">{{ t('staffTelehealth.noParticipants') }}</p>
                </div>
            </div>

            <!-- The "not recorded" discipline, on the surface where it matters most. -->
            <div class="flex items-start gap-2 rounded-2xl border border-euca-200 bg-euca-50 p-4 text-sm text-euca-800">
                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                    <path d="M8 12h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                {{ t('staffTelehealth.notRecorded') }}
            </div>

            <!-- Local device check. -->
            <div class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">{{ t('staffTelehealth.preJoin.checkTitle') }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ t('staffTelehealth.preJoin.checkIntro') }}</p>

                <button
                    type="button"
                    class="mt-3 rounded-xl border border-line bg-surface/70 px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                    :disabled="checking"
                    @click="runDeviceCheck"
                >
                    {{ checking ? t('staffTelehealth.preJoin.checking') : t('staffTelehealth.preJoin.run') }}
                </button>

                <ul v-if="checked" class="mt-3 space-y-1 text-sm text-ink">
                    <li>{{ camera ? t('staffTelehealth.preJoin.cameraFound') : t('staffTelehealth.preJoin.cameraMissing') }}</li>
                    <li>{{ microphone ? t('staffTelehealth.preJoin.micFound') : t('staffTelehealth.preJoin.micMissing') }}</li>
                </ul>
                <p v-if="denied" class="mt-2 text-sm text-ink-muted">{{ t('staffTelehealth.preJoin.denied') }}</p>
                <p class="mt-3 text-xs text-ink-subtle">{{ t('staffTelehealth.preJoin.noGrade') }}</p>
            </div>

            <div v-if="!providerConfigured" class="rounded-2xl border border-warning/40 bg-warning-soft p-4 text-sm text-ink">
                {{ t('staffTelehealth.notConfigured') }}
            </div>
            <p v-else-if="!session.joinable" class="rounded-2xl border border-line bg-surface p-4 text-sm text-ink-muted">
                {{ t('staffTelehealth.preJoin.ended') }}
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    v-if="session.joinable && providerConfigured"
                    type="button"
                    class="btn-glow inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold"
                    @click="join"
                >
                    {{ t('staffTelehealth.preJoin.join') }}
                </button>
                <Link href="/telehealth" class="text-sm font-semibold text-ink-muted transition hover:text-ink">
                    {{ t('staffTelehealth.preJoin.back') }}
                </Link>
            </div>

            <div v-if="joinResult" class="flex items-start gap-2 rounded-xl border border-success/30 bg-success-soft p-4 text-sm text-ink">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M5 12.5l4 4 10-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                {{ t('staffTelehealth.joined') }}
            </div>
        </div>
    </AppLayout>
</template>
