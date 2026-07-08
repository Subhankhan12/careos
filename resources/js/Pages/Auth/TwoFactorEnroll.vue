<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';
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

async function startEnrollment(): Promise<void> {
    await axios.post('/user/two-factor-authentication');
    const [qr, codes] = await Promise.all([
        axios.get('/user/two-factor-qr-code'),
        axios.get('/user/two-factor-recovery-codes'),
    ]);
    qrSvg.value = qr.data.svg;
    recoveryCodes.value = codes.data;
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
                <div>
                    <p class="mb-3 text-sm text-ink-muted">{{ t('auth.twoFactor.enrollStep1') }}</p>
                    <div class="inline-block rounded-lg border border-line bg-surface p-3" v-html="qrSvg" />
                </div>

                <div>
                    <h2 class="mb-1 text-sm font-semibold text-ink">{{ t('auth.twoFactor.recoveryCodesTitle') }}</h2>
                    <p class="mb-2 text-sm text-ink-muted">{{ t('auth.twoFactor.recoveryCodesHint') }}</p>
                    <ul class="grid grid-cols-2 gap-1 rounded-md bg-surface-muted p-3 font-mono text-sm text-ink">
                        <li v-for="rc in recoveryCodes" :key="rc">{{ rc }}</li>
                    </ul>
                </div>

                <form class="space-y-4" @submit.prevent="confirm">
                    <Input
                        id="code"
                        v-model="code"
                        :label="t('auth.twoFactor.enrollStep2')"
                        autocomplete="one-time-code"
                        :error="error"
                    />
                    <Button type="submit">{{ t('auth.twoFactor.confirm') }}</Button>
                </form>
            </div>
        </Card>
    </GuestLayout>
</template>
