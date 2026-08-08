<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import Button from '@/Components/Button.vue';

const { t } = useI18n();

const props = defineProps<{
    token: string;
    valid: boolean;
    email: string | null;
    tenantName: string | null;
    roleName: string | null;
}>();

const form = useForm({ name: '', password: '', password_confirmation: '' });

function submit(): void {
    form.post(`/invite/${props.token}`, { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <GuestLayout>
        <Head :title="t('staffInvite.accept.title')" />

        <Card v-if="!valid" :title="t('staffInvite.accept.invalidTitle')">
            <p class="text-sm text-ink-muted">{{ t('staffInvite.accept.invalidBody') }}</p>
        </Card>

        <Card v-else :title="t('staffInvite.accept.title')">
            <p class="mb-5 text-sm text-ink-muted">
                {{ t('staffInvite.accept.roleLine', { tenant: tenantName, role: roleName }) }}
            </p>
            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('staffInvite.accept.email') }}</span>
                    <p class="rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink-muted">{{ email }}</p>
                </div>
                <Input id="name" v-model="form.name" :label="t('staffInvite.accept.name')" :error="form.errors.name" autocomplete="name" required />
                <Input id="password" v-model="form.password" type="password" reveal :label="t('staffInvite.accept.password')" :error="form.errors.password" autocomplete="new-password" required />
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" reveal :label="t('staffInvite.accept.passwordConfirm')" autocomplete="new-password" required />
                <Button type="submit" pill :disabled="form.processing">{{ t('staffInvite.accept.submit') }}</Button>
            </form>
        </Card>
    </GuestLayout>
</template>
