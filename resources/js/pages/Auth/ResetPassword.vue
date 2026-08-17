<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';

/*
 * AUTH-SEC.2 — set a new password from a reset link.
 *
 * The route existed but had no view bound, so it 500'd. The POST is unchanged and remains Fortify's:
 * the signed token is verified server-side and the new password is validated by the application's
 * real password rules (App\Actions\Fortify\ResetUserPassword → PasswordValidationRules), so this page
 * cannot loosen the policy — a refused password comes back as a field error.
 *
 * Two-factor enrollment is untouched by a reset: the user still faces the mandatory 2FA gate on the
 * next sign-in.
 */

const { t } = useI18n();

const props = defineProps<{ token: string; email: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.reset.title')" />
        <Card :title="t('auth.reset.title')" :subtitle="t('auth.reset.subtitle')">
            <form class="space-y-5" @submit.prevent="submit">
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    :label="t('auth.reset.email')"
                    autocomplete="username"
                    :error="form.errors.email"
                    required
                />
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    :label="t('auth.reset.password')"
                    autocomplete="new-password"
                    :error="form.errors.password"
                    reveal
                    :toggle-label="t('auth.login.togglePassword')"
                    required
                />
                <Input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    :label="t('auth.reset.confirm')"
                    autocomplete="new-password"
                    :error="form.errors.password_confirmation"
                    required
                />
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? t('auth.reset.submitting') : t('auth.reset.submit') }}
                </Button>
            </form>

            <p class="mt-4 text-xs text-ink-subtle">{{ t('auth.reset.hint') }}</p>
        </Card>
    </GuestLayout>
</template>
