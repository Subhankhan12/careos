<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// The ward board (HOSPITAL.G3) — the live bed-occupancy cockpit. PRESENTATIONAL over the G1 bed
// model + G2 ADT domain (P0D.GU): it renders service data and dispatches the EXISTING ADT/bed
// actions; it computes no ADT/occupancy logic. Reuses the day-board tile/status idiom for layout,
// but the data is beds/stays (continuous occupancy), not appointment slots.
//
// ELECTRIC FENCE: operational only — bed housekeeping status, the occupant's name + elapsed LOS,
// and a plain occupancy count. The status COLOUR is the housekeeping state (free/occupied/cleaning/
// blocked), NEVER a clinical acuity/severity/risk. No judgment is computed or rendered.
const { t, locale } = useI18n();

type Occupant = {
    stay_id: string;
    patient: string;
    admitted_at: string;
    show_url: string;
    transfer_url: string;
    discharge_url: string;
};
type BedTile = {
    id: string;
    label: string;
    bed_type: string;
    status: string;
    status_url: string | null;
    occupant: Occupant | null;
};
type WardData = {
    id: string;
    name: string;
    code: string;
    beds: BedTile[];
    summary: { occupied: number; total: number };
};

const props = defineProps<{
    wards: WardData[];
    bedStatuses: string[];
    actions: {
        can_admit: boolean;
        can_manage_beds: boolean;
        admit_url: string;
        admission_types: string[];
        dispositions: string[];
        patients: Array<{ id: string; name: string }>;
        clinicians: Array<{ id: string; name: string }>;
    };
}>();

// Which tile's action panel is open (bed id + which action).
const open = ref<{ bedId: string; kind: 'admit' | 'transfer' | 'discharge' } | null>(null);
const form = reactive({ patient_id: '', admitting_clinician_id: '', admission_type: '', bed_id: '', disposition: '', reason: '' });

// All free beds across the board — the transfer targets.
const freeBeds = computed(() =>
    props.wards.flatMap((w) => w.beds.filter((b) => b.status === 'free').map((b) => ({ id: b.id, label: `${w.name} · ${b.label}` }))),
);

// Housekeeping status → operational colour (NOT a clinical judgment). Eucalyptus Glow tokens only.
function statusClass(status: string): string {
    return (
        {
            free: 'border-euca-200 bg-euca-50/70',
            occupied: 'border-line bg-surface-2',
            cleaning: 'border-warning/30 bg-warning-soft',
            blocked: 'border-danger/30 bg-danger-soft',
        }[status] ?? 'border-line bg-surface-2'
    );
}
function statusDot(status: string): string {
    return { free: 'bg-euca-500', occupied: 'bg-ink-subtle', cleaning: 'bg-warning', blocked: 'bg-danger' }[status] ?? 'bg-ink-subtle';
}

// LOS-so-far = plain elapsed time (a fact), rendered client-side from admitted_at. No judgment.
function elapsed(iso: string): string {
    const mins = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000));
    const days = Math.floor(mins / 1440);
    const hours = Math.floor((mins % 1440) / 60);
    if (days > 0) return t('hospital.board.losDaysHours', { days, hours });
    if (hours > 0) return t('hospital.board.losHours', { hours });
    return t('hospital.board.losMinutes', { minutes: mins });
}
function admittedDate(iso: string): string {
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

// The legal manual bed-status targets per current status (mirrors the server's Bed::TRANSITIONS,
// minus free->occupied which only the admission claim performs). Presentational hint — the server
// (BedService::setStatus) remains authoritative and rejects anything illegal.
function statusTargets(status: string): string[] {
    return { free: ['blocked'], occupied: [], cleaning: ['free', 'blocked'], blocked: ['free'] }[status] ?? [];
}

function resetForm(): void {
    open.value = null;
    Object.assign(form, { patient_id: '', admitting_clinician_id: '', admission_type: '', bed_id: '', disposition: '', reason: '' });
}
function openPanel(bedId: string, kind: 'admit' | 'transfer' | 'discharge'): void {
    resetForm();
    open.value = { bedId, kind };
}

function submitAdmit(bed: BedTile): void {
    router.post(
        props.actions.admit_url,
        { bed_id: bed.id, patient_id: form.patient_id, admitting_clinician_id: form.admitting_clinician_id, admission_type: form.admission_type, reason: form.reason },
        { preserveScroll: true, onSuccess: resetForm },
    );
}
function submitTransfer(bed: BedTile): void {
    if (!bed.occupant) return;
    router.post(bed.occupant.transfer_url, { bed_id: form.bed_id, reason: form.reason }, { preserveScroll: true, onSuccess: resetForm });
}
function submitDischarge(bed: BedTile): void {
    if (!bed.occupant) return;
    router.post(bed.occupant.discharge_url, { disposition: form.disposition, reason: form.reason }, { preserveScroll: true, onSuccess: resetForm });
}
function setStatus(bed: BedTile, status: string): void {
    if (!bed.status_url) return;
    router.post(bed.status_url, { status }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('hospital.board.title')" />
        <div class="space-y-5">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('hospital.board.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('hospital.board.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('hospital.board.subtitle') }}</p>
            </div>

            <p v-if="wards.length === 0" class="glass-card p-8 text-center text-sm text-ink-muted">{{ t('hospital.board.empty') }}</p>

            <section v-for="ward in wards" :key="ward.id" class="glass-card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ ward.name }}</h2>
                    <span class="rounded-full bg-euca-50 px-3 py-1 text-xs font-semibold text-euca-800">
                        {{ t('hospital.board.occupancy', { occupied: ward.summary.occupied, total: ward.summary.total }) }}
                    </span>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <div v-for="bed in ward.beds" :key="bed.id" class="rounded-2xl border p-4" :class="statusClass(bed.status)">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-ink">{{ bed.label }}</p>
                                <p class="text-xs text-ink-subtle">{{ t(`hospital.board.bedType.${bed.bed_type}`) }}</p>
                            </div>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/70 px-2 py-0.5 text-[11px] font-semibold text-ink-muted">
                                <span class="h-1.5 w-1.5 rounded-full" :class="statusDot(bed.status)"></span>
                                {{ t(`hospital.board.bedStatus.${bed.status}`) }}
                            </span>
                        </div>

                        <!-- Occupant (operational: name + elapsed LOS — no judgment) -->
                        <div v-if="bed.occupant" class="mt-3 rounded-xl bg-white/60 p-2.5">
                            <p class="truncate text-sm font-medium text-ink">{{ bed.occupant.patient }}</p>
                            <p class="text-xs text-ink-muted" :title="admittedDate(bed.occupant.admitted_at)">
                                {{ t('hospital.board.admittedSince', { elapsed: elapsed(bed.occupant.admitted_at) }) }}
                            </p>
                        </div>

                        <!-- Actions (through the EXISTING G2 services) -->
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <button v-if="!bed.occupant && bed.status === 'free' && actions.can_admit" type="button" class="rounded-md bg-euca-700 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-euca-800" @click="openPanel(bed.id, 'admit')">
                                {{ t('hospital.board.admit') }}
                            </button>
                            <template v-if="bed.occupant && actions.can_admit">
                                <button type="button" class="rounded-md border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition hover:bg-euca-50" @click="openPanel(bed.id, 'transfer')">{{ t('hospital.board.transfer') }}</button>
                                <button type="button" class="rounded-md border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition hover:bg-euca-50" @click="openPanel(bed.id, 'discharge')">{{ t('hospital.board.discharge') }}</button>
                            </template>
                            <button v-for="target in (actions.can_manage_beds ? statusTargets(bed.status) : [])" :key="target" type="button" class="rounded-md border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink-muted transition hover:bg-euca-50" @click="setStatus(bed, target)">
                                {{ t(`hospital.board.setStatus.${target}`) }}
                            </button>
                        </div>

                        <!-- Inline action panels -->
                        <div v-if="open && open.bedId === bed.id" class="mt-3 space-y-2 rounded-xl bg-white/70 p-3">
                            <template v-if="open.kind === 'admit'">
                                <select v-model="form.patient_id" class="w-full rounded-lg border border-line bg-surface px-2 py-1.5 text-xs text-ink">
                                    <option value="">{{ t('hospital.board.patient') }}</option>
                                    <option v-for="p in actions.patients" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                                <select v-model="form.admitting_clinician_id" class="w-full rounded-lg border border-line bg-surface px-2 py-1.5 text-xs text-ink">
                                    <option value="">{{ t('hospital.board.clinician') }}</option>
                                    <option v-for="c in actions.clinicians" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                                <select v-model="form.admission_type" class="w-full rounded-lg border border-line bg-surface px-2 py-1.5 text-xs text-ink">
                                    <option value="">{{ t('hospital.board.type') }}</option>
                                    <option v-for="ty in actions.admission_types" :key="ty" :value="ty">{{ t(`hospital.admission.types.${ty}`) }}</option>
                                </select>
                                <div class="flex gap-2">
                                    <button type="button" class="btn-glow rounded-lg px-3 py-1 text-xs font-semibold" @click="submitAdmit(bed)">{{ t('hospital.board.confirm') }}</button>
                                    <button type="button" class="rounded-lg border border-line px-3 py-1 text-xs font-medium text-ink-muted" @click="resetForm">{{ t('hospital.board.cancel') }}</button>
                                </div>
                            </template>
                            <template v-else-if="open.kind === 'transfer'">
                                <select v-model="form.bed_id" class="w-full rounded-lg border border-line bg-surface px-2 py-1.5 text-xs text-ink">
                                    <option value="">{{ t('hospital.board.targetBed') }}</option>
                                    <option v-for="fb in freeBeds" :key="fb.id" :value="fb.id">{{ fb.label }}</option>
                                </select>
                                <div class="flex gap-2">
                                    <button type="button" class="btn-glow rounded-lg px-3 py-1 text-xs font-semibold" @click="submitTransfer(bed)">{{ t('hospital.board.confirm') }}</button>
                                    <button type="button" class="rounded-lg border border-line px-3 py-1 text-xs font-medium text-ink-muted" @click="resetForm">{{ t('hospital.board.cancel') }}</button>
                                </div>
                            </template>
                            <template v-else>
                                <select v-model="form.disposition" class="w-full rounded-lg border border-line bg-surface px-2 py-1.5 text-xs text-ink">
                                    <option value="">{{ t('hospital.board.disposition') }}</option>
                                    <option v-for="d in actions.dispositions" :key="d" :value="d">{{ t(`hospital.board.dispositions.${d}`) }}</option>
                                </select>
                                <div class="flex gap-2">
                                    <button type="button" class="btn-glow rounded-lg px-3 py-1 text-xs font-semibold" @click="submitDischarge(bed)">{{ t('hospital.board.confirm') }}</button>
                                    <button type="button" class="rounded-lg border border-line px-3 py-1 text-xs font-medium text-ink-muted" @click="resetForm">{{ t('hospital.board.cancel') }}</button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
