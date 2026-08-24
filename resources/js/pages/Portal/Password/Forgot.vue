<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();
const page = usePage();

/*
 * PT.P7 — step one of recovery. The confirmation below is shown after ANY submission, because the
 * server behaves identically for a live account, an unknown address, a patient who was never
 * invited and a disabled account. That is why the wording is conditional: we cannot say "we've
 * emailed you" when for most subjects nothing was sent (D-179).
 */
const sent = computed(() => (page.props.flash as { status?: string } | undefined)?.status === 'portal.password.sent');

const form = useForm({ email: '' });

function submit(): void {
    form.post('/portal/forgot-password', { onFinish: () => form.reset('email') });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10">
        <Head :title="t('portal.password.forgotTitle')" />

        <Card class="w-full max-w-sm">
            <template v-if="sent">
                <h1 class="mb-2 text-lg font-semibold text-ink">{{ t('portal.password.sentTitle') }}</h1>
                <p class="text-sm text-ink-muted">{{ t('portal.password.sentBody') }}</p>
                <!--
                    No countdown and no resend button. The wireframe draws "Resend available in 0:47",
                    but CareOS has no per-address cooldown to count down to — only the route throttle —
                    so a timer here would be a number with nothing behind it (D-176).
                -->
                <p class="mt-3 text-sm text-ink-muted">{{ t('portal.password.sentNext') }}</p>
                <Link href="/portal/login" class="mt-5 inline-block text-sm font-semibold text-euca-700 hover:underline">
                    {{ t('portal.password.backToSignIn') }}
                </Link>
            </template>

            <template v-else>
                <h1 class="mb-2 text-lg font-semibold text-ink">{{ t('portal.password.forgotTitle') }}</h1>
                <p class="mb-5 text-sm text-ink-muted">{{ t('portal.password.forgotLead') }}</p>

                <form class="space-y-5" @submit.prevent="submit">
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        :label="t('portal.password.email')"
                        :error="form.errors.email"
                        autocomplete="email"
                        required
                    />
                    <Button type="submit" pill class="w-full" :disabled="form.processing">
                        {{ t('portal.password.submit') }}
                    </Button>
                </form>

                <Link href="/portal/login" class="mt-5 inline-block text-sm font-semibold text-euca-700 hover:underline">
                    {{ t('portal.password.backToSignIn') }}
                </Link>
            </template>
        </Card>
    </div>
</template>
