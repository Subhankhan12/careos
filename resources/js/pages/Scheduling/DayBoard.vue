<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import ScheduleGrid from '@/Components/ScheduleGrid.vue';
import SlotPicker from '@/Components/SlotPicker.vue';

const { t } = useI18n();

const props = defineProps<{
    filters: { date: string; branch_id: string };
    branches: Array<{ id: string; name: string }>;
    resources: Array<{ id: string; name: string; type: string }>;
    appointments: Array<{ id: string; patient_id: string | null; patient: string | null; service: string | null; starts_at: string; ends_at: string; status: string; resource_ids: string[] }>;
    services: Array<{ id: string; name: string; duration: number }>;
    patients: Array<{ id: string; name: string; mrn: string }>;
    slotPreview: Array<{ starts_at: string; ends_at: string; resource_ids: string[] }>;
    actions: { transitionUrl: string; quickBookUrl: string; slotsUrl: string; openEncounterUrl: string };
}>();

const filters = reactive({ ...props.filters });
const quick = reactive({
    service_id: props.services[0]?.id ?? '',
    patient_id: props.patients[0]?.id ?? '',
    starts_at: '',
    resource_ids: [] as string[],
    notes: '',
});
const slots = ref([...props.slotPreview]);

const selectedSlot = computed(() => quick.starts_at);

function reload(): void {
    router.get('/scheduling/day-board', filters, { preserveState: true, replace: true });
}

function transition(payload: { appointmentId: string; action: string }): void {
    router.post(props.actions.transitionUrl, {
        appointment_id: payload.appointmentId,
        action: payload.action,
        reason: t('scheduling.dayBoard.actionReason'),
    }, { preserveScroll: true });
}

function openEncounter(payload: { appointmentId: string }): void {
    router.post(props.actions.openEncounterUrl, {
        appointment_id: payload.appointmentId,
    });
}

async function loadSlots(): Promise<void> {
    if (!quick.service_id || !filters.branch_id || !filters.date) {
        slots.value = [];
        return;
    }

    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(props.actions.slotsUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            Accept: 'application/json',
        },
        body: JSON.stringify({ service_id: quick.service_id, branch_id: filters.branch_id, date: filters.date }),
    });
    const json = (await response.json()) as { slots: typeof slots.value };
    slots.value = json.slots;
}

function selectSlot(slot: { starts_at: string; resource_ids: string[] }): void {
    quick.starts_at = slot.starts_at;
    quick.resource_ids = slot.resource_ids;
}

function quickBook(): void {
    router.post(props.actions.quickBookUrl, {
        service_id: quick.service_id,
        patient_id: quick.patient_id,
        branch_id: filters.branch_id,
        starts_at: quick.starts_at,
        resource_ids: quick.resource_ids,
        notes: quick.notes,
    }, { preserveScroll: true });
}

watch(() => [quick.service_id, filters.branch_id, filters.date], loadSlots);
</script>

<template>
    <AppLayout>
        <Head :title="t('scheduling.dayBoard.title')" />
        <div class="space-y-6">
            <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                <div>
                    <h1 class="text-2xl font-semibold text-ink">{{ t('scheduling.dayBoard.title') }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('scheduling.dayBoard.subtitle') }}</p>
                </div>
                <form class="grid gap-3 sm:grid-cols-[180px_220px_120px]" @submit.prevent="reload">
                    <Input id="day-board-date" v-model="filters.date" type="date" :label="t('scheduling.fields.date')" />
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.fields.branch') }}</span>
                        <select v-model="filters.branch_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </label>
                    <div class="flex items-end">
                        <Button type="submit">{{ t('scheduling.dayBoard.refresh') }}</Button>
                    </div>
                </form>
            </div>

            <Card :title="t('scheduling.dayBoard.quickBook')" :subtitle="t('scheduling.dayBoard.quickBookHint')">
                <form class="space-y-4" @submit.prevent="quickBook">
                    <div class="grid gap-4 md:grid-cols-3">
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.fields.service') }}</span>
                            <select v-model="quick.service_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                                <option v-for="service in services" :key="service.id" :value="service.id">{{ service.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.fields.patient') }}</span>
                            <select v-model="quick.patient_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                                <option v-for="patient in patients" :key="patient.id" :value="patient.id">{{ patient.name }} {{ patient.mrn }}</option>
                            </select>
                        </label>
                        <Input id="quick-notes" v-model="quick.notes" :label="t('scheduling.fields.notes')" />
                    </div>
                    <SlotPicker :slots="slots" :selected="selectedSlot" @select="selectSlot" />
                    <div class="max-w-48">
                        <Button type="submit" :disabled="!quick.starts_at">{{ t('scheduling.dayBoard.book') }}</Button>
                    </div>
                </form>
            </Card>

            <ScheduleGrid :resources="resources" :appointments="appointments" @action="transition" @open-encounter="openEncounter" />
        </div>
    </AppLayout>
</template>
