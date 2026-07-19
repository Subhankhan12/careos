<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import ScheduleGrid from '@/Components/ScheduleGrid.vue';
import SlotPicker from '@/Components/SlotPicker.vue';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

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

const filters = reactive({ ...props.filters });
const quickBookOpen = ref(false);
const resourceView = ref<'all' | 'practitioner' | 'room'>('all');

const visibleResources = computed(() =>
    resourceView.value === 'all' ? props.resources : props.resources.filter((r) => r.type === resourceView.value),
);

const formattedDate = computed(() => {
    const [y, m, d] = filters.date.split('-').map(Number);
    const dt = new Date(y, (m ?? 1) - 1, d ?? 1);
    if (Number.isNaN(dt.getTime())) return filters.date;
    try {
        return new Intl.DateTimeFormat(locale.value, { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' }).format(dt);
    } catch {
        return filters.date;
    }
});

function toDateString(dt: Date): string {
    return `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
}
function shiftDay(delta: number): void {
    const [y, m, d] = filters.date.split('-').map(Number);
    filters.date = toDateString(new Date(y, (m ?? 1) - 1, (d ?? 1) + delta));
    reload();
}
function goToday(): void {
    filters.date = toDateString(new Date());
    reload();
}

// --- recurring series (P.8) --------------------------------------------------
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

// --- waitlist auto-fill (P.9) ------------------------------------------------
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
        body: JSON.stringify({ service_id: appt.service_id, branch_id: filters.branch_id, starts_at: appt.starts_at, ends_at: appt.ends_at }),
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

// --- quick book --------------------------------------------------------------
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
    router.post(props.actions.openEncounterUrl, { appointment_id: payload.appointmentId });
}
async function loadSlots(): Promise<void> {
    if (!quick.service_id || !filters.branch_id || !filters.date) {
        slots.value = [];
        return;
    }
    const response = await fetch(props.actions.slotsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
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

const views = [
    { key: 'all', label: 'scheduling.dayBoard.viewAll' },
    { key: 'practitioner', label: 'scheduling.dayBoard.viewPractitioners' },
    { key: 'room', label: 'scheduling.dayBoard.viewRooms' },
] as const;

const legend = [
    { key: 'booked', dot: 'bg-euca-300' },
    { key: 'arrived', dot: 'bg-euca-500' },
    { key: 'in_progress', dot: 'bg-euca-700' },
    { key: 'completed', dot: 'bg-ink-subtle' },
    { key: 'cancelled', dot: 'bg-danger' },
];
</script>

<template>
    <AppLayout>
        <Head :title="t('scheduling.dayBoard.title')" />
        <div class="space-y-5">
            <!-- Header: eyebrow · date pager + branch · view filters + quick-book -->
            <div class="flex flex-col justify-between gap-4 xl:flex-row xl:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('scheduling.dayBoard.eyebrow') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <div class="inline-flex items-center rounded-full bg-euca-50/70 p-1">
                            <button type="button" :aria-label="t('scheduling.dayBoard.prevDay')" class="flex h-8 w-8 items-center justify-center rounded-full text-ink-muted transition hover:bg-surface hover:text-ink" @click="shiftDay(-1)">‹</button>
                            <span class="px-3 text-sm font-semibold text-ink">{{ formattedDate }}</span>
                            <button type="button" :aria-label="t('scheduling.dayBoard.nextDay')" class="flex h-8 w-8 items-center justify-center rounded-full text-ink-muted transition hover:bg-surface hover:text-ink" @click="shiftDay(1)">›</button>
                        </div>
                        <button type="button" class="rounded-full border border-line bg-surface/70 px-3 py-1.5 text-sm font-medium text-ink transition hover:bg-surface-2" @click="goToday">
                            {{ t('scheduling.dayBoard.todayLabel') }}
                        </button>
                        <select v-model="filters.branch_id" :aria-label="t('scheduling.fields.branch')" class="rounded-full border border-line bg-surface/70 px-3.5 py-1.5 text-sm font-medium text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" @change="reload">
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex items-center rounded-full bg-euca-50/70 p-1">
                        <button
                            v-for="view in views"
                            :key="view.key"
                            type="button"
                            class="rounded-full px-3 py-1.5 text-sm font-medium transition"
                            :class="resourceView === view.key ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                            @click="resourceView = view.key"
                        >
                            {{ t(view.label) }}
                        </button>
                    </div>
                    <button type="button" class="btn-glow inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold" @click="quickBookOpen = true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                        {{ t('scheduling.dayBoard.quickBook') }}
                    </button>
                </div>
            </div>

            <ScheduleGrid :resources="visibleResources" :appointments="appointments" @action="transition" @open-encounter="openEncounter" />

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-ink-subtle">
                <span class="font-semibold uppercase tracking-wide">{{ t('scheduling.dayBoard.statusEdges') }}</span>
                <span v-for="item in legend" :key="item.key" class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-full" :class="item.dot"></span>{{ t(`scheduling.status.${item.key}`) }}
                </span>
            </div>

            <!-- Waitlist auto-fill (P.9) -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('scheduling.waitlist.title') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ t('scheduling.waitlist.subtitle') }}</p>
                <div class="mt-4 space-y-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label class="block sm:w-2/3">
                            <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.waitlist.fillSlot') }}</span>
                            <select v-model="fill.appointment_id" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30">
                                <option value="">{{ t('scheduling.waitlist.pickSlot') }}</option>
                                <option v-for="appt in appointments" :key="appt.id" :value="appt.id">
                                    {{ appt.starts_at }} · {{ appt.service }} · {{ appt.patient ?? '—' }} ({{ appt.status }})
                                </option>
                            </select>
                        </label>
                        <button type="button" :disabled="!fill.appointment_id" class="rounded-xl border border-line bg-surface/70 px-4 py-2.5 text-sm font-semibold text-ink transition hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-50" @click="findCandidates">
                            {{ t('scheduling.waitlist.findCandidates') }}
                        </button>
                    </div>

                    <div v-if="candidates.length" class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                                <tr class="border-b border-line">
                                    <th class="py-2 pr-4 font-semibold">{{ t('scheduling.waitlist.patient') }}</th>
                                    <th class="py-2 pr-4 font-semibold">{{ t('scheduling.waitlist.priority') }}</th>
                                    <th class="py-2 pr-4 font-semibold">{{ t('scheduling.waitlist.window') }}</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line/60">
                                <tr v-for="candidate in candidates" :key="candidate.waitlist_entry_id">
                                    <td class="py-2 pr-4 text-ink">{{ candidate.patient }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">{{ candidate.priority }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">
                                        <span v-if="candidate.flexible">{{ t('scheduling.waitlist.flexible') }}</span>
                                        <span v-else>{{ candidate.desired_starts_at }} – {{ candidate.desired_ends_at }}</span>
                                    </td>
                                    <td class="py-2 text-right">
                                        <button type="button" class="font-semibold text-euca-700 transition hover:text-euca-800" @click="offerToCandidate(candidate)">
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
                            <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                                <tr class="border-b border-line">
                                    <th class="py-2 pr-4 font-semibold">{{ t('scheduling.waitlist.patient') }}</th>
                                    <th class="py-2 pr-4 font-semibold">{{ t('scheduling.fields.date') }}</th>
                                    <th class="py-2 pr-4 font-semibold">{{ t('scheduling.waitlist.status') }}</th>
                                    <th class="py-2 pr-4 font-semibold">{{ t('scheduling.waitlist.expires') }}</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line/60">
                                <tr v-for="offer in waitlistOffers" :key="offer.id">
                                    <td class="py-2 pr-4 text-ink">{{ offer.patient }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">{{ offer.starts_at }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">{{ offer.status }}</td>
                                    <td class="py-2 pr-4 text-ink-muted">{{ offer.expires_at }}</td>
                                    <td class="py-2 text-right">
                                        <template v-if="offer.status === 'offered'">
                                            <button type="button" class="mr-3 font-semibold text-euca-700 transition hover:text-euca-800" @click="acceptOffer(offer.id)">{{ t('scheduling.waitlist.accept') }}</button>
                                            <button type="button" class="font-medium text-ink-muted transition hover:text-ink" @click="declineOffer(offer.id)">{{ t('scheduling.waitlist.decline') }}</button>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recurring appointment (P.8) -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('scheduling.series.title') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ t('scheduling.series.subtitle') }}</p>
                <p class="mt-3 text-sm text-ink-muted">{{ t('scheduling.series.hint') }}</p>
                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.frequency') }}</span>
                        <select v-model="series.frequency" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink">
                            <option value="daily">{{ t('scheduling.series.daily') }}</option>
                            <option value="weekly">{{ t('scheduling.series.weekly') }}</option>
                            <option value="monthly">{{ t('scheduling.series.monthly') }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.interval') }}</span>
                        <input v-model.number="series.interval" type="number" min="1" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.startTime') }}</span>
                        <input v-model="series.start_time" type="time" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.startsOn') }}</span>
                        <input v-model="series.starts_on" type="date" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" />
                    </label>
                    <div class="text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('scheduling.series.end') }}</span>
                        <div class="flex items-center gap-2">
                            <select v-model="series.end_type" class="rounded-xl border border-line bg-surface-2 px-3 py-2.5 text-sm text-ink">
                                <option value="count">{{ t('scheduling.series.afterN') }}</option>
                                <option value="until">{{ t('scheduling.series.onDate') }}</option>
                            </select>
                            <input v-if="series.end_type === 'count'" v-model.number="series.count" type="number" min="1" class="w-20 rounded-xl border border-line bg-surface-2 px-3 py-2.5 text-sm text-ink" />
                            <input v-else v-model="series.ends_on" type="date" class="rounded-xl border border-line bg-surface-2 px-3 py-2.5 text-sm text-ink" />
                        </div>
                    </div>
                </div>

                <div v-if="series.frequency === 'weekly'" class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-for="day in weekdays"
                        :key="day"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-sm font-medium transition"
                        :class="series.byday.includes(day) ? 'nav-pill-active text-ink' : 'bg-euca-50 text-ink-muted hover:text-ink'"
                        @click="toggleDay(day)"
                    >{{ day }}</button>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" class="rounded-xl border border-line bg-surface/70 px-4 py-2.5 text-sm font-semibold text-ink transition hover:bg-surface-2" @click="previewSeries">{{ t('scheduling.series.preview') }}</button>
                    <button type="button" :disabled="seriesPreview.length === 0" class="btn-glow inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50" @click="createSeries">{{ t('scheduling.series.confirm') }}</button>
                </div>

                <div v-if="seriesPreview.length" class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-ink-subtle">
                            <tr class="border-b border-line">
                                <th class="py-2 pr-4 font-semibold">{{ t('scheduling.series.date') }}</th>
                                <th class="py-2 pr-4 font-semibold">{{ t('scheduling.series.time') }}</th>
                                <th class="py-2 font-semibold">{{ t('scheduling.series.availability') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line/60">
                            <tr v-for="occ in seriesPreview" :key="occ.date">
                                <td class="py-2 pr-4 text-ink">{{ occ.date }}</td>
                                <td class="py-2 pr-4 text-ink-muted">{{ occ.starts_at }}</td>
                                <td class="py-2">
                                    <span v-if="occ.free" class="text-euca-700">{{ t('scheduling.series.free') }}</span>
                                    <span v-else class="text-danger">{{ t('scheduling.series.conflict') }} ({{ occ.reason }})</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick-book slide-over -->
        <div v-if="quickBookOpen" class="fixed inset-0 z-40" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-euca-900/20 backdrop-blur-sm" @click="quickBookOpen = false"></div>
            <div class="euca-wash absolute inset-y-0 right-0 flex w-full max-w-md flex-col overflow-y-auto border-l border-white/40 p-6 shadow-2xl">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('scheduling.dayBoard.quickBook') }}</h2>
                        <p class="text-sm text-ink-muted">{{ t('scheduling.dayBoard.quickBookHint') }}</p>
                    </div>
                    <button type="button" :aria-label="t('scheduling.dayBoard.close')" class="flex h-9 w-9 items-center justify-center rounded-full text-ink-muted transition hover:bg-euca-50 hover:text-ink" @click="quickBookOpen = false">✕</button>
                </div>
                <form class="mt-5 space-y-4" @submit.prevent="quickBook">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.fields.service') }}</span>
                        <select v-model="quick.service_id" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30">
                            <option v-for="service in services" :key="service.id" :value="service.id">{{ service.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.fields.patient') }}</span>
                        <select v-model="quick.patient_id" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30">
                            <option v-for="patient in patients" :key="patient.id" :value="patient.id">{{ patient.name }} {{ patient.mrn }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('scheduling.fields.notes') }}</span>
                        <input v-model="quick.notes" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                    </label>
                    <div>
                        <p class="mb-2 text-sm font-medium text-ink">{{ t('scheduling.dayBoard.freeSlots') }}</p>
                        <SlotPicker :slots="slots" :selected="selectedSlot" @select="selectSlot" />
                    </div>
                    <button type="submit" :disabled="!quick.starts_at" class="btn-glow inline-flex w-full items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50">
                        {{ t('scheduling.dayBoard.book') }}
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
