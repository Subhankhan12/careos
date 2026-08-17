<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';

const { t } = useI18n();

const qrSvg = ref('');
const recoveryCodes = ref<string[]>([]);
const code = ref('');
const error = ref('');
const ready = ref(false);

/*
 * AUTH-VIS — the "Can't scan?" manual fallback.
 *
 * This is the SAME secret the QR above already encodes, read from the user's own Fortify endpoint
 * (`/user/two-factor-secret-key`, which decrypts `$request->user()->two_factor_secret`). Nothing is
 * generated here and no other user's secret is reachable: it is the authenticated user's own key, on
 * their own enrolment screen, in the same auth context that is already showing them the QR. It exists
 * so someone who cannot scan — no camera, or authenticating on the same device — can still enrol.
 * Kept behind a reveal so it is not sitting on screen by default.
 */
const secretKey = ref('');
const secretShown = ref(false);

/** Group the REAL secret into four-character blocks — display formatting only, never a new value. */
const groupedSecret = computed(() => (secretKey.value.match(/.{1,4}/g) ?? []).join(' · '));

async function startEnrollment(): Promise<void> {
    await axios.post('/user/two-factor-authentication');
    const [qr, codes, secret] = await Promise.all([
        axios.get('/user/two-factor-qr-code'),
        axios.get('/user/two-factor-recovery-codes'),
        axios.get('/user/two-factor-secret-key'),
    ]);
    qrSvg.value = qr.data.svg;
    recoveryCodes.value = codes.data;
    secretKey.value = secret.data.secretKey;
    ready.value = true;
}

async function confirm(): Promise<void> {
    error.value = '';
    try {
        await axios.post('/user/confirmed-two-factor-authentication', { code: code.value });
        router.visit('/app');
    } catch {
        error.value = t('auth.login.invalid');
    }
}

onMounted(startEnrollment);
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.twoFactor.enrollTitle')" />
        <Card :title="t('auth.twoFactor.enrollTitle')" :subtitle="t('auth.twoFactor.enrollSubtitle')">
            <div v-if="ready" class="space-y-6">
                <!-- Step 1 — scan QR (server SVG via v-html, never an <img>). -->
                <section class="flex gap-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-euca-100 text-sm font-semibold text-euca-800">1</span>
                    <div class="flex-1">
                        <h2 class="text-sm font-semibold text-ink">{{ t('auth.twoFactor.enrollStep1') }}</h2>
                        <div class="mt-3 inline-block rounded-xl border border-line bg-surface p-3" v-html="qrSvg" />

                        <!-- The manual fallback: the SAME secret the QR encodes, as selectable text
                             (never an image), for anyone who cannot scan it. -->
                        <div class="mt-3">
                            <button
                                v-if="!secretShown"
                                type="button"
                                class="text-xs font-semibold text-euca-700 underline-offset-2 transition hover:text-euca-900 hover:underline"
                                @click="secretShown = true"
                            >
                                {{ t('auth.twoFactor.cantScan') }}
                            </button>
                            <div v-else>
                                <p class="text-xs text-ink-muted">{{ t('auth.twoFactor.secretLabel') }}</p>
                                <p class="mt-1 select-all font-mono text-sm tracking-wide text-ink">{{ groupedSecret }}</p>
                                <p class="mt-1 text-xs text-ink-subtle">{{ t('auth.twoFactor.secretHint') }}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Step 2 — recovery codes (selectable text, never an image). -->
                <section class="flex gap-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-euca-100 text-sm font-semibold text-euca-800">2</span>
                    <div class="flex-1">
                        <h2 class="text-sm font-semibold text-ink">{{ t('auth.twoFactor.recoveryCodesTitle') }}</h2>
                        <div class="mt-2 rounded-xl border border-warning/40 bg-warning-soft p-3">
                            <p class="flex items-start gap-1.5 text-sm text-ink">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 4 21 19H3L12 4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                    <path d="M12 10v4M12 16.5v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                </svg>
                                {{ t('auth.twoFactor.recoveryCodesHint') }}
                            </p>
                            <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-sm text-ink">
                                <li v-for="rc in recoveryCodes" :key="rc" class="select-all">{{ rc }}</li>
                            </ul>
                        </div>
                    </div>
                </section>

                <!-- Step 3 — confirm a code. -->
                <section class="flex gap-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-euca-100 text-sm font-semibold text-euca-800">3</span>
                    <form class="flex-1 space-y-3" @submit.prevent="confirm">
                        <Input
                            id="code"
                            v-model="code"
                            :label="t('auth.twoFactor.enrollStep2')"
                            autocomplete="one-time-code"
                            :error="error"
                        />
                        <Button type="submit">{{ t('auth.twoFactor.confirm') }}</Button>
                    </form>
                </section>
            </div>

            <!-- Until the QR, codes and secret have arrived the card was simply blank. Say what is
                 happening instead — no claim about the outcome, just the pending state. -->
            <p v-else class="text-sm text-ink-muted">{{ t('auth.twoFactor.preparing') }}</p>
        </Card>
    </GuestLayout>
</template>
