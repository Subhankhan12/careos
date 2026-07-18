<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';
import CodeInput from '@/Components/CodeInput.vue';

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
        <Card>
            <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-euca-50">
                <svg class="h-6 w-6 text-euca-700" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                        d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z"
                        stroke="currentColor"
                        stroke-width="1.6"
                        stroke-linejoin="round"
                    />
                    <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ t('auth.twoFactor.challengeTitle') }}</h1>
            <p class="mt-1 text-sm text-ink-muted">{{ t('auth.twoFactor.challengeSubtitle') }}</p>

            <form class="mt-6 space-y-5" @submit.prevent="submit">
                <div v-if="!useRecovery">
                    <CodeInput
                        v-model="form.code"
                        :aria-label="t('auth.twoFactor.code')"
                        :invalid="!!form.errors.code"
                        autofocus
                    />
                    <p v-if="form.errors.code" class="mt-2 text-sm text-danger">{{ form.errors.code }}</p>
                </div>
                <Input
                    v-else
                    id="recovery_code"
                    v-model="form.recovery_code"
                    :label="t('auth.twoFactor.recoveryCode')"
                    autocomplete="one-time-code"
                    :error="form.errors.recovery_code"
                />
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? t('auth.twoFactor.verifying') : t('auth.twoFactor.verify') }}
                </Button>
                <button
                    type="button"
                    class="w-full text-center text-sm font-medium text-euca-700 transition hover:text-euca-800"
                    @click="useRecovery = !useRecovery"
                >
                    {{ useRecovery ? t('auth.twoFactor.useCode') : t('auth.twoFactor.useRecovery') }}
                </button>
            </form>
        </Card>
    </GuestLayout>
</template>
