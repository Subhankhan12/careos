<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();

const props = defineProps<{
    devices: Array<{ id: string; name: string; branch: string | null; active: boolean; last_used_at: string | null }>;
    branches: Array<{ id: string; name: string }>;
    issued: { token: string; url: string } | null;
    issueUrl: string;
    revokeUrl: string;
}>();

const form = useForm({ branch_id: props.branches[0]?.id ?? '', name: '' });
const revokeForm = useForm({ device_id: '' });

function issue(): void {
    form.post(props.issueUrl, { preserveScroll: true });
}

function revoke(id: string): void {
    revokeForm.device_id = id;
    revokeForm.post(props.revokeUrl, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('kioskAdmin.title')" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ t('kioskAdmin.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('kioskAdmin.subtitle') }}</p>
            </div>

            <Card v-if="issued" :title="t('kioskAdmin.issuedTitle')">
                <p class="text-sm text-ink-muted">{{ t('kioskAdmin.issuedHint') }}</p>
                <p class="mt-2 break-all rounded-md bg-surface-muted p-3 font-mono text-sm text-ink">{{ issued.url }}</p>
            </Card>

            <Card :title="t('kioskAdmin.provision')">
                <form class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end" @submit.prevent="issue">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('kioskAdmin.branch') }}</span>
                        <select v-model="form.branch_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </label>
                    <Input id="kiosk-name" v-model="form.name" :label="t('kioskAdmin.name')" />
                    <div><Button type="submit" :disabled="form.processing || !form.name">{{ t('kioskAdmin.issue') }}</Button></div>
                </form>
            </Card>

            <Card :title="t('kioskAdmin.devices')">
                <p v-if="devices.length === 0" class="text-sm text-ink-muted">{{ t('kioskAdmin.none') }}</p>
                <table v-else class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('kioskAdmin.name') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('kioskAdmin.branch') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('kioskAdmin.status') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="device in devices" :key="device.id" class="border-b border-line/60">
                            <td class="py-2 pr-4 text-ink">{{ device.name }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ device.branch }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ device.active ? t('kioskAdmin.active') : t('kioskAdmin.revoked') }}</td>
                            <td class="py-2 text-right">
                                <button v-if="device.active" type="button" class="font-medium text-danger hover:opacity-80" @click="revoke(device.id)">{{ t('kioskAdmin.revoke') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>
    </AppLayout>
</template>
