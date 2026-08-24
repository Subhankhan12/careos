<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();

const props = defineProps<{ actions: { loginUrl: string } }>();

const form = reactive({ email: '', password: '' });
const failed = ref(false);

// PT.P7 — set by the reset flow, which deliberately does NOT sign the patient in: they arrive
// here and go through the real login path, consent re-check included.
const page = usePage();
const justReset = computed(() => (page.props.flash as { status?: string } | undefined)?.status === 'portal.password.reset');

async function submit(): Promise<void> {
    failed.value = false;

    const response = await fetch(props.actions.loginUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
        },
        body: JSON.stringify(form),
    });

    if (response.ok) {
        router.visit('/portal');
    } else {
        failed.value = true;
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4">
        <Head :title="t('portal.login.title')" />
        <Card class="w-full max-w-sm">
            <h1 class="mb-4 text-lg font-semibold">{{ t('portal.login.title') }}</h1>
            <p v-if="justReset" class="mb-4 rounded-xl bg-euca-50 px-3.5 py-2.5 text-sm text-euca-800">
                {{ t('portal.password.resetDone') }}
            </p>
            <form class="space-y-3" @submit.prevent="submit">
                <Input v-model="form.email" type="email" :label="t('portal.login.email')" required />
                <Input v-model="form.password" type="password" :label="t('portal.login.password')" required />
                <p v-if="failed" class="text-sm text-red-600">{{ t('portal.login.failed') }}</p>
                <Button type="submit" class="w-full">{{ t('portal.login.submit') }}</Button>
            </form>

            <!-- PT.P7 — the way back in. Until this gate a patient who forgot their password had
                 to ask the practice to re-invite them. -->
            <Link href="/portal/forgot-password" class="mt-4 inline-block text-sm font-semibold text-euca-700 hover:underline">
                {{ t('portal.password.forgotLink') }}
            </Link>
        </Card>
    </div>
</template>
