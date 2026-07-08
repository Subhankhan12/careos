<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import SlotPicker from '@/Components/SlotPicker.vue';

const { t } = useI18n();

const props = defineProps<{
    tenant: { slug: string; name: string };
    services: Array<{ id: string; name: string; duration: number }>;
    branches: Array<{ id: string; name: string }>;
    slotsUrl: string;
    storeUrl: string;
}>();

const slotFilters = reactive({
    service_id: props.services[0]?.id ?? '',
    branch_id: props.branches[0]?.id ?? '',
    date: '',
});
const slots = ref<Array<{ starts_at: string; ends_at: string; resource_ids: string[] }>>([]);
const selected = ref('');
const selectedResources = ref<string[]>([]);

const form = useForm({
    service_id: slotFilters.service_id,
    branch_id: slotFilters.branch_id,
    starts_at: '',
    resource_ids: [] as string[],
    first_name: '',
    last_name: '',
    date_of_birth: '',
    sex: '',
    email: '',
});

async function loadSlots(): Promise<void> {
    if (!slotFilters.service_id || !slotFilters.branch_id || !slotFilters.date) {
        slots.value = [];
        return;
    }

    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(props.slotsUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            Accept: 'application/json',
        },
        body: JSON.stringify(slotFilters),
    });
    const json = (await response.json()) as { slots: typeof slots.value };
    slots.value = json.slots;
}

function selectSlot(slot: { starts_at: string; resource_ids: string[] }): void {
    selected.value = slot.starts_at;
    selectedResources.value = slot.resource_ids;
}

function submit(): void {
    form.service_id = slotFilters.service_id;
    form.branch_id = slotFilters.branch_id;
    form.starts_at = selected.value;
    form.resource_ids = selectedResources.value;
    form.post(props.storeUrl, { preserveScroll: true });
}

watch(() => [slotFilters.service_id, slotFilters.branch_id, slotFilters.date], loadSlots);
</script>

<template>
    <GuestLayout>
        <Head :title="t('scheduling.public.title')" />
        <Card :title="t('scheduling.public.title')" :subtitle="props.tenant.name">
            <div class="mb-5 rounded-md border border-danger/30 bg-danger/5 p-3 text-sm font-medium text-danger">
                {{ t('scheduling.public.emergencyNotice') }}
            </div>
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.fields.service') }}</span>
                        <select v-model="slotFilters.service_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                            <option v-for="service in services" :key="service.id" :value="service.id">{{ service.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.fields.branch') }}</span>
                        <select v-model="slotFilters.branch_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </label>
                    <Input id="public-date" v-model="slotFilters.date" type="date" :label="t('scheduling.fields.date')" />
                </div>

                <SlotPicker :slots="slots" :selected="selected" @select="selectSlot" />

                <div class="grid gap-4 md:grid-cols-2">
                    <Input id="public-first-name" v-model="form.first_name" :label="t('patients.fields.firstName')" :error="form.errors.first_name" required />
                    <Input id="public-last-name" v-model="form.last_name" :label="t('patients.fields.lastName')" :error="form.errors.last_name" required />
                    <Input id="public-dob" v-model="form.date_of_birth" type="date" :label="t('patients.fields.dateOfBirth')" :error="form.errors.date_of_birth" required />
                    <Input id="public-sex" v-model="form.sex" :label="t('patients.fields.sex')" :error="form.errors.sex" required />
                    <Input id="public-email" v-model="form.email" type="email" :label="t('patients.fields.email')" :error="form.errors.email" required />
                </div>

                <Button type="submit" :disabled="form.processing || !selected">{{ t('scheduling.public.book') }}</Button>
            </form>
        </Card>
    </GuestLayout>
</template>
