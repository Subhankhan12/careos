<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';

const { t } = useI18n();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit(): void {
    form.transform((data) => ({ ...data, remember: data.remember ? 'on' : '' })).post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.login.title')" />
        <Card :title="t('auth.login.title')" :subtitle="t('auth.login.subtitle')">
            <form class="space-y-5" @submit.prevent="submit">
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    :label="t('auth.login.email')"
                    :placeholder="t('auth.login.emailPlaceholder')"
                    autocomplete="username"
                    :error="form.errors.email"
                    required
                />
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    :label="t('auth.login.password')"
                    autocomplete="current-password"
                    :error="form.errors.password"
                    reveal
                    :toggle-label="t('auth.login.togglePassword')"
                    required
                />
                <label class="flex items-center gap-2 text-sm text-ink-muted">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="h-4 w-4 rounded border-line accent-euca-700 focus:ring-euca-500/40"
                    />
                    {{ t('auth.login.remember') }}
                </label>
                <Button type="submit" :disabled="form.processing">
                    <svg
                        v-if="form.processing"
                        class="h-4 w-4 animate-spin"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden="true"
                    >
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.3" stroke-width="2.5" />
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                    {{ form.processing ? t('auth.login.submitting') : t('auth.login.submit') }}
                </Button>
            </form>
        </Card>
    </GuestLayout>
</template>
