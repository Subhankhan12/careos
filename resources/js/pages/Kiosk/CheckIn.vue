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
    <div class="min-h-screen bg-surface-muted px-4 py-10" @click="resetIdle" @keydown="resetIdle">
        <Head :title="t('kiosk.title')" />
        <div class="mx-auto max-w-xl">
            <div class="mb-8 text-center">
                <h1 class="text-3xl font-semibold text-ink">{{ t('kiosk.title') }}</h1>
                <p class="mt-1 text-lg text-ink-muted">{{ branch.name }}</p>
            </div>

            <!-- Step 1: verify identity -->
            <div v-if="step === 'verify'" class="space-y-5 rounded-2xl border border-line bg-surface p-6">
                <p class="text-lg text-ink">{{ t('kiosk.verifyHint') }}</p>
                <label class="block">
                    <span class="mb-2 block text-base font-medium text-ink">{{ t('kiosk.name') }}</span>
                    <input v-model="form.name" type="text" autocomplete="off" class="block w-full rounded-xl border border-line bg-surface px-4 py-4 text-lg text-ink focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" />
                </label>
                <label class="block">
                    <span class="mb-2 block text-base font-medium text-ink">{{ t('kiosk.dob') }}</span>
                    <input v-model="form.date_of_birth" type="date" class="block w-full rounded-xl border border-line bg-surface px-4 py-4 text-lg text-ink focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" />
                </label>
                <label class="block">
                    <span class="mb-2 block text-base font-medium text-ink">{{ t('kiosk.code') }}</span>
                    <input v-model="form.code" type="text" autocomplete="off" class="block w-full rounded-xl border border-line bg-surface px-4 py-4 text-lg uppercase tracking-widest text-ink focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" />
                </label>
                <p v-if="error" class="text-base text-danger">{{ error }}</p>
                <button type="button" :disabled="busy || !form.name || !form.date_of_birth || !form.code" class="min-h-[3.5rem] w-full rounded-xl bg-brand-600 px-6 text-lg font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60" @click="verify">
                    {{ t('kiosk.continue') }}
                </button>
            </div>

            <!-- Step 2: confirm + optional contact update -->
            <div v-else-if="step === 'confirm'" class="space-y-6 rounded-2xl border border-line bg-surface p-6">
                <div>
                    <p class="text-lg text-ink">{{ t('kiosk.confirmHint') }}</p>
                    <p class="mt-2 text-xl font-semibold text-ink">{{ appointment?.service }}</p>
                    <p class="text-lg text-ink-muted">{{ appointment?.starts_at }}</p>
                </div>
                <div class="space-y-4 border-t border-line pt-4">
                    <p class="text-base font-medium text-ink">{{ t('kiosk.contactTitle') }}</p>
                    <label class="block">
                        <span class="mb-1 block text-sm text-ink-muted">{{ t('kiosk.phone') }}</span>
                        <input v-model="contact.phone" type="tel" class="block w-full rounded-xl border border-line bg-surface px-4 py-3 text-lg text-ink" />
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm text-ink-muted">{{ t('kiosk.email') }}</span>
                        <input v-model="contact.email" type="email" class="block w-full rounded-xl border border-line bg-surface px-4 py-3 text-lg text-ink" />
                    </label>
                    <button type="button" :disabled="busy" class="min-h-[3rem] rounded-xl border border-line bg-surface px-5 text-base font-medium text-ink hover:bg-surface-muted" @click="saveContact">
                        {{ t('kiosk.saveContact') }}
                    </button>
                </div>
                <button type="button" :disabled="busy" class="min-h-[3.5rem] w-full rounded-xl bg-brand-600 px-6 text-lg font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60" @click="checkIn">
                    {{ t('kiosk.checkInNow') }}
                </button>
                <button type="button" class="w-full text-base text-ink-muted hover:text-ink" @click="reset">{{ t('kiosk.startOver') }}</button>
            </div>

            <!-- Step 3: done -->
            <div v-else class="space-y-4 rounded-2xl border border-line bg-surface p-8 text-center">
                <p class="text-2xl font-semibold text-ink">{{ t('kiosk.doneTitle') }}</p>
                <p class="text-lg text-ink-muted">{{ t('kiosk.doneHint') }}</p>
            </div>
        </div>
    </div>
</template>
