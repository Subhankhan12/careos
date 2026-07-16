<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();

const props = defineProps<{
    items: Array<{ id: string; category: string; code: string; name: string; specimen_or_modality: string | null; active: boolean }>;
    storeUrl: string;
    deactivateUrl: string;
}>();

const form = useForm({ category: 'lab', code: '', name: '', specimen_or_modality: '' });

function create(): void {
    form.post(props.storeUrl, { preserveScroll: true, onSuccess: () => form.reset('code', 'name', 'specimen_or_modality') });
}
function deactivate(id: string): void {
    useForm({ item_id: id }).post(props.deactivateUrl, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('clinical.orderableItems.title')" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ t('clinical.orderableItems.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('clinical.orderableItems.subtitle') }}</p>
            </div>

            <Card :title="t('clinical.orderableItems.add')">
                <form class="grid gap-3 sm:grid-cols-[auto_1fr_1fr_1fr_auto] sm:items-end" @submit.prevent="create">
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('clinical.orderableItems.category') }}</span>
                        <select v-model="form.category" class="rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option value="lab">lab</option>
                            <option value="imaging">imaging</option>
                            <option value="other">other</option>
                        </select>
                    </label>
                    <Input id="oi-code" v-model="form.code" :label="t('clinical.orderableItems.code')" />
                    <Input id="oi-name" v-model="form.name" :label="t('clinical.orderableItems.name')" />
                    <Input id="oi-spec" v-model="form.specimen_or_modality" :label="t('clinical.orderableItems.specimen')" />
                    <div><Button type="submit" :disabled="!form.code || !form.name">{{ t('clinical.orderableItems.addButton') }}</Button></div>
                </form>
            </Card>

            <Card>
                <table class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('clinical.orderableItems.category') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('clinical.orderableItems.code') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('clinical.orderableItems.name') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('clinical.orderableItems.specimen') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id" class="border-b border-line/60" :class="item.active ? '' : 'opacity-50'">
                            <td class="py-2 pr-4 text-ink-muted">{{ item.category }}</td>
                            <td class="py-2 pr-4 text-ink">{{ item.code }}</td>
                            <td class="py-2 pr-4 text-ink">{{ item.name }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ item.specimen_or_modality }}</td>
                            <td class="py-2 text-right">
                                <button v-if="item.active" type="button" class="text-sm font-medium text-danger hover:opacity-80" @click="deactivate(item.id)">{{ t('clinical.orderableItems.deactivate') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>
    </AppLayout>
</template>
