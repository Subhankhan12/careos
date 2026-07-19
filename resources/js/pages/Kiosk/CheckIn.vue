<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps<{
    branch: { name: string | null };
    urls: { resolve: string; checkIn: string; updateContact: string };
}>();

type Contact = {
    phone: string | null;
    email: string | null;
    address: { line1: string | null; line2: string | null; city: string | null; postal: string | null; country: string | null };
};

const step = ref<'verify' | 'confirm' | 'done'>('verify');
const error = ref('');
const busy = ref(false);

// Ephemeral, in-memory only — NEVER localStorage/sessionStorage. Cleared on
// completion and on idle timeout so no patient data lingers on a shared device.
const form = reactive({ name: '', date_of_birth: '', code: '' });
const verification = ref('');
const appointment = ref<{ service: string | null; starts_at: string; checked_in: boolean } | null>(null);
const contact = reactive<Contact>({ phone: null, email: null, address: { line1: null, line2: null, city: null, postal: null, country: null } });

let idleTimer: ReturnType<typeof setTimeout> | undefined;

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function resetIdle(): void {
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(reset, 60_000);
}

function reset(): void {
    step.value = 'verify';
    error.value = '';
    form.name = '';
    form.date_of_birth = '';
    form.code = '';
    verification.value = '';
    appointment.value = null;
    contact.phone = null;
    contact.email = null;
    contact.address = { line1: null, line2: null, city: null, postal: null, country: null };
    resetIdle();
}

async function post(url: string, body: Record<string, unknown>): Promise<Response> {
    resetIdle();
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        body: JSON.stringify(body),
    });
}

async function verify(): Promise<void> {
    error.value = '';
    busy.value = true;
    try {
        const res = await post(props.urls.resolve, { name: form.name, date_of_birth: form.date_of_birth, code: form.code });
        if (res.status === 429) {
            error.value = t('kiosk.tooMany');
            return;
        }
        const json = (await res.json()) as { found: boolean; verification?: string; appointment?: typeof appointment.value; contact?: Contact };
        if (!json.found || !json.verification || !json.appointment) {
            error.value = t('kiosk.notFound');
            return;
        }
        verification.value = json.verification;
        appointment.value = json.appointment;
        if (json.contact) {
            contact.phone = json.contact.phone;
            contact.email = json.contact.email;
            contact.address = json.contact.address;
        }
        step.value = 'confirm';
    } finally {
        busy.value = false;
    }
}

async function saveContact(): Promise<void> {
    busy.value = true;
    try {
        await post(props.urls.updateContact, { verification: verification.value, phone: contact.phone, email: contact.email, address: contact.address });
    } finally {
        busy.value = false;
    }
}

async function checkIn(): Promise<void> {
    busy.value = true;
    try {
        const res = await post(props.urls.checkIn, { verification: verification.value });
        if (!res.ok) {
            error.value = t('kiosk.notFound');
            return;
        }
        step.value = 'done';
        // Auto-reset for the next patient.
        if (idleTimer) clearTimeout(idleTimer);
        idleTimer = setTimeout(reset, 8_000);
    } finally {
        busy.value = false;
    }
}

resetIdle();
onBeforeUnmount(() => idleTimer && clearTimeout(idleTimer));
</script>

<template>
    <div class="euca-wash relative min-h-screen px-4 py-10 text-ink" @click="resetIdle" @keydown="resetIdle">
        <Head :title="t('kiosk.title')" />
        <div class="relative z-10 mx-auto max-w-xl">
            <div class="mb-8 flex flex-col items-center text-center">
                <span class="euca-tile-dark mb-4 flex h-12 w-12 items-center justify-center rounded-2xl">
                    <svg class="h-6 w-6 text-euca-50" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M5 19C5 11 9 5.2 19 4.8c-.4 9.2-5 13.8-14 14.2z" fill="currentColor" />
                    </svg>
                </span>
                <h1 class="text-3xl font-semibold tracking-tight text-ink">{{ t('kiosk.title') }}</h1>
                <p class="mt-1 text-lg text-ink-muted">{{ branch.name }}</p>
            </div>

            <!-- Step 1: verify identity (name + date of birth + check-in code) -->
            <div v-if="step === 'verify'" class="glass-card space-y-5 p-7">
                <p class="text-lg text-ink">{{ t('kiosk.verifyHint') }}</p>
                <label class="block">
                    <span class="mb-2 block text-base font-medium text-ink">{{ t('kiosk.name') }}</span>
                    <input v-model="form.name" type="text" autocomplete="off" class="block w-full rounded-xl border border-line bg-surface-2 px-4 py-4 text-lg text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                </label>
                <label class="block">
                    <span class="mb-2 block text-base font-medium text-ink">{{ t('kiosk.dob') }}</span>
                    <input v-model="form.date_of_birth" type="date" class="block w-full rounded-xl border border-line bg-surface-2 px-4 py-4 text-lg text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                </label>
                <label class="block">
                    <span class="mb-2 block text-base font-medium text-ink">{{ t('kiosk.code') }}</span>
                    <input v-model="form.code" type="text" autocomplete="off" class="block w-full rounded-xl border border-line bg-surface-2 px-4 py-4 text-lg uppercase tracking-widest text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                </label>
                <p v-if="error" class="text-base text-danger">{{ error }}</p>
                <button type="button" :disabled="busy || !form.name || !form.date_of_birth || !form.code" class="btn-glow min-h-[3.5rem] w-full rounded-xl px-6 text-lg font-semibold disabled:opacity-60" @click="verify">
                    {{ t('kiosk.continue') }}
                </button>
            </div>

            <!-- Step 2: confirm + optional contact update (no clinical data, no patient browsing) -->
            <div v-else-if="step === 'confirm'" class="glass-card space-y-6 p-7">
                <div>
                    <p class="text-lg text-ink">{{ t('kiosk.confirmHint') }}</p>
                    <p class="mt-2 text-xl font-semibold text-ink">{{ appointment?.service }}</p>
                    <p class="text-lg text-ink-muted">{{ appointment?.starts_at }}</p>
                </div>
                <div class="space-y-4 border-t border-line pt-5">
                    <p class="text-base font-medium text-ink">{{ t('kiosk.contactTitle') }}</p>
                    <label class="block">
                        <span class="mb-1 block text-sm text-ink-muted">{{ t('kiosk.phone') }}</span>
                        <input v-model="contact.phone" type="tel" class="block w-full rounded-xl border border-line bg-surface-2 px-4 py-3 text-lg text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm text-ink-muted">{{ t('kiosk.email') }}</span>
                        <input v-model="contact.email" type="email" class="block w-full rounded-xl border border-line bg-surface-2 px-4 py-3 text-lg text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                    </label>
                    <button type="button" :disabled="busy" class="min-h-[3rem] rounded-xl border border-line bg-surface/70 px-5 text-base font-semibold text-ink transition hover:bg-surface-2" @click="saveContact">
                        {{ t('kiosk.saveContact') }}
                    </button>
                </div>
                <button type="button" :disabled="busy" class="btn-glow min-h-[3.5rem] w-full rounded-xl px-6 text-lg font-semibold disabled:opacity-60" @click="checkIn">
                    {{ t('kiosk.checkInNow') }}
                </button>
                <button type="button" class="w-full text-base text-ink-muted transition hover:text-ink" @click="reset">{{ t('kiosk.startOver') }}</button>
            </div>

            <!-- Step 3: done (auto-resets for the next patient) -->
            <div v-else class="glass-card space-y-4 p-10 text-center">
                <span class="euca-tile-dark mx-auto flex h-16 w-16 items-center justify-center rounded-full">
                    <svg class="h-8 w-8 text-euca-50" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M5 12.5l4 4 10-10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <p class="text-2xl font-semibold text-ink">{{ t('kiosk.doneTitle') }}</p>
                <p class="text-lg text-ink-muted">{{ t('kiosk.doneHint') }}</p>
            </div>
        </div>
    </div>
</template>
