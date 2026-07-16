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
    appointments: Array<{ id: string; patient_id: string | null; patient: string | null; service_id: string; service: string | null; starts_at: string; ends_at: string; status: string; resource_ids: string[] }>;
    services: Array<{ id: string; name: string; duration: number }>;
    patients: Array<{ id: string; name: string; mrn: string }>;
    slotPreview: Array<{ starts_at: string; ends_at: string; resource_ids: string[] }>;
    waitlistOffers: Array<{ id: string; patient: string | null; service_id: string; starts_at: string; ends_at: string; status: string; expires_at: string; booked_appointment_id: string | null }>;
    actions: {
        transitionUrl: string;
        quickBookUrl: string;
        slotsUrl: string;
        openEncounterUrl: string;
        waitlistCandidatesUrl: string;
        waitlistOfferUrl: string;
        waitlistAcceptUrl: string;
        waitlistDeclineUrl: string;
        seriesPreviewUrl: string;
        seriesStoreUrl: string;
    };
}>();

type SeriesOccurrence = { date: string; starts_at: string; free: boolean; reason: string | null };

const series = reactive({
    frequency: 'weekly',
    interval: 1,
    byday: [] as string[],
    start_time: '09:00',
    starts_on: '',
    end_type: 'count',
    count: 6,
    ends_on: '',
});
const seriesPreview = ref<SeriesOccurrence[]>([]);
const weekdays = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

function seriesPayload(): Record<string, unknown> {
    return {
        patient_id: quick.patient_id,
        service_id: quick.service_id,
        branch_id: filters.branch_id,
        resource_ids: quick.resource_ids,
        frequency: series.frequency,
        interval: series.interval,
        byday: series.byday,
        start_time: series.start_time,
        starts_on: series.starts_on,
        end_type: series.end_type,
        count: series.count,
        ends_on: series.ends_on,
    };
}

function toggleDay(day: string): void {
    const i = series.byday.indexOf(day);
    if (i >= 0) series.byday.splice(i, 1);
    else series.byday.push(day);
}

async function previewSeries(): Promise<void> {
    const response = await fetch(props.actions.seriesPreviewUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        body: JSON.stringify(seriesPayload()),
    });
    seriesPreview.value = response.ok ? ((await response.json()).occurrences as SeriesOccurrence[]) : [];
}

function createSeries(): void {
    router.post(props.actions.seriesStoreUrl, seriesPayload(), { preserveScroll: true });
}

type Candidate = {
    waitlist_entry_id: string;
    patient: string | null;
    priority: number;
    flexible: boolean;
    desired_starts_at: string | null;
    desired_ends_at: string | null;
};

const fill = reactive({ appointment_id: '' });
const candidates = ref<Candidate[]>([]);

const fillAppointment = computed(() => props.appointments.find((a) => a.id === fill.appointment_id) ?? null);

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function findCandidates(): Promise<void> {
    const appt = fillAppointment.value;
    if (!appt) {
        candidates.value = [];
        return;
    }
    const response = await fetch(props.actions.waitlistCandidatesUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        body: JSON.stringify({
            service_id: appt.service_id,
            branch_id: filters.branch_id,
            starts_at: appt.starts_at,
            ends_at: appt.ends_at,
        }),
    });
    const json = (await response.json()) as { candidates: Candidate[] };
    candidates.value = json.candidates;
}

function offerToCandidate(candidate: Candidate): void {
    const appt = fillAppointment.value;
    if (!appt) return;
    router.post(props.actions.waitlistOfferUrl, {
        waitlist_entry_id: candidate.waitlist_entry_id,
        branch_id: filters.branch_id,
        starts_at: appt.starts_at,
        ends_at: appt.ends_at,
        resource_ids: appt.resource_ids,
        source_appointment_id: appt.id,
    }, { preserveScroll: true });
}

function acceptOffer(offerId: string): void {
    router.post(props.actions.waitlistAcceptUrl, { offer_id: offerId }, { preserveScroll: true });
}

function declineOffer(offerId: string): void {
    router.post(props.actions.waitlistDeclineUrl, { offer_id: offerId }, { preserveScroll: true });
}

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

            <Card :title="t('scheduling.waitlist.title')" :subtitle="t('scheduling.waitlist.subtitle')">
                <div class="space-y-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label class="block sm:w-2/3">
                            <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.waitlist.fillSlot') }}</span>
                            <select v-model="fill.appointment_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                                <option value="">{{ t('scheduling.waitlist.pickSlot') }}</option>
                                <option v-for="appt in appointments" :key="appt.id" :value="appt.id">
                                    {{ appt.starts_at }} · {{ appt.service }} · {{ appt.patient ?? '—' }} ({{ appt.status }})
                                </option>
                            </select>
                        </label>
                        <div class="sm:w-1/3">
                            <Button type="button" variant="secondary" :disabled="!fill.appointment_id" @click="findCandidates">
                                {{ t('scheduling.waitlist.findCandidates') }}
                            </Button>
                        </div>
                    </div>

                    <div v-if="candidates.length" class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-ink-muted">
                                <tr class="border-b border-line">
                                    <th class="py-2 pr-4 font-medium">{{ t('scheduling.waitlist.patient') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ t('scheduling.waitlist.priority') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ t('scheduling.waitlist.window') }}</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="candidate in candidates" :key="candidate.waitlist_entry_id" class="border-b border-line/60">
                                    <td class="py-2 pr-4 text-ink">{{ candidate.patient }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">{{ candidate.priority }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">
                                        <span v-if="candidate.flexible">{{ t('scheduling.waitlist.flexible') }}</span>
                                        <span v-else>{{ candidate.desired_starts_at }} – {{ candidate.desired_ends_at }}</span>
                                    </td>
                                    <td class="py-2 text-right">
                                        <button type="button" class="font-medium text-brand-600 hover:text-brand-700" @click="offerToCandidate(candidate)">
                                            {{ t('scheduling.waitlist.offer') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-ink">{{ t('scheduling.waitlist.openOffers') }}</h3>
                        <p v-if="waitlistOffers.length === 0" class="text-sm text-ink-muted">{{ t('scheduling.waitlist.noOffers') }}</p>
                        <table v-else class="w-full text-left text-sm">
                            <thead class="text-ink-muted">
                                <tr class="border-b border-line">
                                    <th class="py-2 pr-4 font-medium">{{ t('scheduling.waitlist.patient') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ t('scheduling.fields.date') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ t('scheduling.waitlist.status') }}</th>
                                    <th class="py-2 pr-4 font-medium">{{ t('scheduling.waitlist.expires') }}</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="offer in waitlistOffers" :key="offer.id" class="border-b border-line/60">
                                    <td class="py-2 pr-4 text-ink">{{ offer.patient }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">{{ offer.starts_at }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">{{ offer.status }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">{{ offer.expires_at }}</td>
                                    <td class="py-2 text-right">
                                        <template v-if="offer.status === 'offered'">
                                            <button type="button" class="mr-3 font-medium text-brand-600 hover:text-brand-700" @click="acceptOffer(offer.id)">{{ t('scheduling.waitlist.accept') }}</button>
                                            <button type="button" class="font-medium text-ink-muted hover:text-ink" @click="declineOffer(offer.id)">{{ t('scheduling.waitlist.decline') }}</button>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </Card>

            <Card :title="t('scheduling.series.title')" :subtitle="t('scheduling.series.subtitle')">
                <p class="mb-4 text-sm text-ink-muted">{{ t('scheduling.series.hint') }}</p>
                <div class="grid gap-4 md:grid-cols-3">
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.frequency') }}</span>
                        <select v-model="series.frequency" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option value="daily">{{ t('scheduling.series.daily') }}</option>
                            <option value="weekly">{{ t('scheduling.series.weekly') }}</option>
                            <option value="monthly">{{ t('scheduling.series.monthly') }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.interval') }}</span>
                        <input v-model.number="series.interval" type="number" min="1" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.startTime') }}</span>
                        <input v-model="series.start_time" type="time" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.startsOn') }}</span>
                        <input v-model="series.starts_on" type="date" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink" />
                    </label>
                    <div class="text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.end') }}</span>
                        <div class="flex items-center gap-2">
                            <select v-model="series.end_type" class="rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                <option value="count">{{ t('scheduling.series.afterN') }}</option>
                                <option value="until">{{ t('scheduling.series.onDate') }}</option>
                            </select>
                            <input v-if="series.end_type === 'count'" v-model.number="series.count" type="number" min="1" class="w-20 rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink" />
                            <input v-else v-model="series.ends_on" type="date" class="rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink" />
                        </div>
                    </div>
                </div>

                <div v-if="series.frequency === 'weekly'" class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-for="day in weekdays"
                        :key="day"
                        type="button"
                        class="rounded-md border px-3 py-1.5 text-sm"
                        :class="series.byday.includes(day) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-line bg-surface text-ink-muted'"
                        @click="toggleDay(day)"
                    >{{ day }}</button>
                </div>

                <div class="mt-4 flex gap-3">
                    <div class="w-48"><Button type="button" variant="secondary" @click="previewSeries">{{ t('scheduling.series.preview') }}</Button></div>
                    <div class="w-48"><Button type="button" :disabled="seriesPreview.length === 0" @click="createSeries">{{ t('scheduling.series.confirm') }}</Button></div>
                </div>

                <div v-if="seriesPreview.length" class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-ink-muted">
                            <tr class="border-b border-line">
                                <th class="py-2 pr-4 font-medium">{{ t('scheduling.series.date') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ t('scheduling.series.time') }}</th>
                                <th class="py-2 font-medium">{{ t('scheduling.series.availability') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="occ in seriesPreview" :key="occ.date" class="border-b border-line/60">
                                <td class="py-2 pr-4 text-ink">{{ occ.date }}</td>
                                <td class="py-2 pr-4 text-ink-muted">{{ occ.starts_at }}</td>
                                <td class="py-2">
                                    <span v-if="occ.free" class="text-brand-600">{{ t('scheduling.series.free') }}</span>
                                    <span v-else class="text-danger">{{ t('scheduling.series.conflict') }} ({{ occ.reason }})</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
