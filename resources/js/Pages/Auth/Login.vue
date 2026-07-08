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
                    required
                />
                <label class="flex items-center gap-2 text-sm text-ink-muted">
                    <input v-model="form.remember" type="checkbox" class="rounded border-line text-brand-600 focus:ring-brand-500" />
                    {{ t('auth.login.remember') }}
                </label>
                <Button type="submit" :disabled="form.processing">{{ t('auth.login.submit') }}</Button>
            </form>
        </Card>
    </GuestLayout>
</template>
