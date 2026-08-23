<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();

/*
 * PT.P6 — the invite landing page. Two states, and the refusal state carries NOTHING: no token,
 * no address, no practice, no reason. Every dead token (unknown · expired · already used · not
 * bound to its own tenant) renders exactly this, so the cases cannot be told apart.
 */
const props = defineProps<{
    valid: boolean;
    token?: string;
    email?: string;
    practiceName?: string;
    /** The real expiry of THIS token, as recorded on the row. */
    expiresAt?: string;
}>();

const form = useForm({ otp: '', password: '', password_confirmation: '' });

function submit(): void {
    form.post(`/portal/invite/${props.token}`, {
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
        <Head :title="t('portal.invite.title')" />

        <Card v-if="!valid" class="w-full max-w-sm">
            <h1 class="mb-2 text-lg font-semibold text-ink">{{ t('portal.invite.invalidTitle') }}</h1>
            <!--
                ONE generic line for every dead token. The follow-up is an INSTRUCTION, not a
                control: CareOS has no patient-triggered resend, so drawing a "resend" button here
                would be an affordance that does nothing (D-176).
            -->
            <p class="text-sm text-ink-muted">{{ t('portal.invite.invalidBody') }}</p>
            <p class="mt-3 text-sm text-ink-muted">{{ t('portal.invite.invalidNext') }}</p>
        </Card>

        <Card v-else class="w-full max-w-sm">
            <h1 class="mb-1 text-lg font-semibold text-ink">{{ t('portal.invite.title') }}</h1>
            <p class="mb-5 text-sm text-ink-muted">{{ t('portal.invite.lead', { practice: practiceName }) }}</p>

            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('portal.invite.settingUpFor') }}</span>
                    <p class="rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink-muted">{{ email }}</p>
                </div>

                <div>
                    <Input
                        id="otp"
                        v-model="form.otp"
                        :label="t('portal.invite.code')"
                        :error="form.errors.otp"
                        autocomplete="one-time-code"
                        required
                    />
                    <p class="mt-1.5 text-xs text-ink-subtle">{{ t('portal.invite.codeHint') }}</p>
                </div>
                <div>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        reveal
                        :label="t('portal.invite.password')"
                        :error="form.errors.password"
                        autocomplete="new-password"
                        required
                    />
                    <p class="mt-1.5 text-xs text-ink-subtle">{{ t('portal.invite.passwordHint') }}</p>
                </div>
                <Input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    reveal
                    :label="t('portal.invite.passwordConfirm')"
                    autocomplete="new-password"
                    required
                />

                <Button type="submit" pill class="w-full" :disabled="form.processing">
                    {{ t('portal.invite.submit') }}
                </Button>
            </form>

            <!-- The REAL expiry of this token, read off the row — not the mock's "7 days". -->
            <p v-if="expiresAt" class="mt-4 text-xs text-ink-subtle">
                {{ t('portal.invite.expiresAt', { when: expiryLabel(expiresAt) }) }}
            </p>
        </Card>
    </div>
</template>
