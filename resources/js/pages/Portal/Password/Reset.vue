<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();

/*
 * PT.P7 — step three, opened from the emailed link. The refusal state carries NOTHING: no token, no
 * address, no practice, no reason, so unknown / expired / already-used / wrong-purpose /
 * cross-tenant tokens cannot be told apart (D-185).
 */
const props = defineProps<{
    valid: boolean;
    token?: string;
    practiceName?: string;
    /** The real expiry of THIS token, as recorded on the row. */
    expiresAt?: string;
}>();

const form = useForm({ otp: '', password: '', password_confirmation: '' });

function submit(): void {
    form.post(`/portal/reset/${props.token}`, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}

function expiryLabel(iso: string): string {
    // Display only — the server decides whether the token still works.
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10">
        <Head :title="t('portal.password.resetTitle')" />

        <Card v-if="!valid" class="w-full max-w-sm">
            <h1 class="mb-2 text-lg font-semibold text-ink">{{ t('portal.password.invalidTitle') }}</h1>
            <p class="text-sm text-ink-muted">{{ t('portal.password.invalidBody') }}</p>
            <Link href="/portal/forgot-password" class="mt-5 inline-block text-sm font-semibold text-euca-700 hover:underline">
                {{ t('portal.password.invalidNext') }}
            </Link>
        </Card>

        <Card v-else class="w-full max-w-sm">
            <h1 class="mb-1 text-lg font-semibold text-ink">{{ t('portal.password.resetTitle') }}</h1>
            <p class="mb-5 text-sm text-ink-muted">{{ t('portal.password.resetLead', { practice: practiceName }) }}</p>

            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <Input
                        id="otp"
                        v-model="form.otp"
                        :label="t('portal.password.code')"
                        :error="form.errors.otp"
                        autocomplete="one-time-code"
                        required
                    />
                    <p class="mt-1.5 text-xs text-ink-subtle">{{ t('portal.password.codeHint') }}</p>
                </div>
                <div>
                    <!--
                        No strength meter. The mock shows one ("Strong"), but the server's rule is a
                        minimum length and nothing else — a verdict the code cannot back is a
                        judgment invented for the screen (D-176). The real rule is stated instead.
                    -->
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        reveal
                        :label="t('portal.password.newPassword')"
                        :error="form.errors.password"
                        autocomplete="new-password"
                        required
                    />
                    <p class="mt-1.5 text-xs text-ink-subtle">{{ t('portal.password.passwordHint') }}</p>
                </div>
                <Input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    reveal
                    :label="t('portal.password.confirmPassword')"
                    autocomplete="new-password"
                    required
                />

                <Button type="submit" pill class="w-full" :disabled="form.processing">
                    {{ t('portal.password.save') }}
                </Button>
            </form>

            <p v-if="expiresAt" class="mt-4 text-xs text-ink-subtle">
                {{ t('portal.password.expiresAt', { when: expiryLabel(expiresAt) }) }}
            </p>
        </Card>
    </div>
</template>
