<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';

const { t } = useI18n();

const useRecovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

function submit(): void {
    form.post('/two-factor-challenge');
}
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.twoFactor.challengeTitle')" />
        <Card :title="t('auth.twoFactor.challengeTitle')" :subtitle="t('auth.twoFactor.challengeSubtitle')">
            <form class="space-y-5" @submit.prevent="submit">
                <Input
                    v-if="!useRecovery"
                    id="code"
                    v-model="form.code"
                    :label="t('auth.twoFactor.code')"
                    autocomplete="one-time-code"
                    :error="form.errors.code"
                />
                <Input
                    v-else
                    id="recovery_code"
                    v-model="form.recovery_code"
                    :label="t('auth.twoFactor.recoveryCode')"
                    :error="form.errors.recovery_code"
                />
                <Button type="submit" :disabled="form.processing">{{ t('auth.twoFactor.verify') }}</Button>
                <button
                    type="button"
                    class="w-full text-center text-sm font-medium text-brand-700 hover:text-brand-800"
                    @click="useRecovery = !useRecovery"
                >
                    {{ useRecovery ? t('auth.twoFactor.useCode') : t('auth.twoFactor.useRecovery') }}
                </button>
            </form>
        </Card>
    </GuestLayout>
</template>
