<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';

/*
 * AUTH-SEC.2 — request a password-reset link.
 *
 * The route was already registered by Fortify but had no view bound, so this page 500'd and a
 * locked-out user had no self-service recovery. The POST behaviour is unchanged and still Fortify's:
 * the response is deliberately the SAME whether or not the address matches an account, so this page
 * cannot be used to enumerate users. Nothing here weakens a gate — it makes an existing one reachable.
 */

const { t } = useI18n();

defineProps<{ status?: string | null }>();

const form = useForm({ email: '' });

function submit(): void {
    form.post('/forgot-password');
}
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.forgot.title')" />
        <Card :title="t('auth.forgot.title')" :subtitle="t('auth.forgot.subtitle')">
            <!-- Fortify's generic confirmation: identical for a known and an unknown address. -->
            <p v-if="status" class="mb-4 rounded-xl bg-euca-50 px-3 py-2 text-sm text-euca-800">{{ status }}</p>

            <form class="space-y-5" @submit.prevent="submit">
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    :label="t('auth.forgot.email')"
                    :placeholder="t('auth.login.emailPlaceholder')"
                    autocomplete="username"
                    :error="form.errors.email"
                    required
                />
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? t('auth.forgot.submitting') : t('auth.forgot.submit') }}
                </Button>
            </form>

            <p class="mt-4 text-xs text-ink-subtle">{{ t('auth.forgot.hint') }}</p>
        </Card>
    </GuestLayout>
</template>
