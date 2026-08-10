<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();
const page = usePage();

type DayHours = { weekday: number; is_closed: boolean; open_time: string; close_time: string };
type ResourceRow = {
    id: string;
    name: string;
    type: string;
    active: boolean;
    future_appointments: number;
    updateUrl: string;
    deactivateUrl: string;
    activateUrl: string;
};
type Branch = {
    id: string;
    name: string;
    code: string;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    postal_code: string | null;
    country: string | null;
    timezone: string;
    phone: string | null;
    active: boolean;
    accepts_online_bookings: boolean;
    is_primary: boolean;
    active_resources: number;
    future_appointments: number;
    hours: DayHours[];
    resources: ResourceRow[];
    resourceStoreUrl: string;
    updateUrl: string;
    hoursUrl: string;
    onlineBookingsUrl: string;
    setPrimaryUrl: string;
    deactivateUrl: string;
    activateUrl: string;
};

const props = defineProps<{
    branches: Branch[];
    weekdays: number[];
    timezones: string[];
    resourceTypes: string[];
    storeUrl: string;
    settingsUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);
const errors = computed(() => (page.props.errors as Record<string, string> | undefined) ?? {});

const createForm = useForm({
    name: '',
    code: '',
    address_line1: '',
    address_line2: '',
    city: '',
    postal_code: '',
    country: '',
    timezone: props.timezones[0] ?? 'UTC',
    phone: '',
});

// Per-branch editable details + hours (plain reactive state; submitted via router.post).
const details = reactive<Record<string, Record<string, string>>>({});
const hours = reactive<Record<string, DayHours[]>>({});
// Resource CRUD state: one "add" draft per branch + an edit draft per existing resource.
const newResource = reactive<Record<string, { name: string; type: string }>>({});
const resourceEdits = reactive<Record<string, { name: string; type: string }>>({});
const defaultType = (): string => props.resourceTypes[0] ?? 'room';
props.branches.forEach((branch) => {
    details[branch.id] = {
        name: branch.name,
        code: branch.code,
        address_line1: branch.address_line1 ?? '',
        address_line2: branch.address_line2 ?? '',
        city: branch.city ?? '',
        postal_code: branch.postal_code ?? '',
        country: branch.country ?? '',
        timezone: branch.timezone,
        phone: branch.phone ?? '',
    };
    hours[branch.id] = branch.hours.map((day) => ({ ...day }));
    newResource[branch.id] = { name: '', type: defaultType() };
    branch.resources.forEach((resource) => {
        resourceEdits[resource.id] = { name: resource.name, type: resource.type };
    });
});

function createBranch(): void {
    createForm.post(props.storeUrl, {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            showCreate.value = false;
        },
    });
}
function saveDetails(branch: Branch): void {
    router.post(branch.updateUrl, details[branch.id], { preserveScroll: true });
}
function saveHours(branch: Branch): void {
    router.post(branch.hoursUrl, { days: hours[branch.id] }, { preserveScroll: true });
}
function setActive(branch: Branch, active: boolean): void {
    router.post(active ? branch.activateUrl : branch.deactivateUrl, {}, { preserveScroll: true });
}
// Soft-suspend: turn online bookings on/off. Distinct from deactivate — always allowed, keeps
// existing appointments + the day-board; the server enforces the online-booking gate.
function setOnlineBookings(branch: Branch, accepts: boolean): void {
    router.post(branch.onlineBookingsUrl, { accepts_online_bookings: accepts }, { preserveScroll: true });
}
// Set this branch as the tenant's primary (default). Atomic server-side — exactly one primary always.
function setPrimary(branch: Branch): void {
    router.post(branch.setPrimaryUrl, {}, { preserveScroll: true });
}
function createResource(branch: Branch): void {
    router.post(branch.resourceStoreUrl, newResource[branch.id], {
        preserveScroll: true,
        onSuccess: () => {
            newResource[branch.id] = { name: '', type: defaultType() };
        },
    });
}
function saveResource(resource: ResourceRow): void {
    router.post(resource.updateUrl, resourceEdits[resource.id], { preserveScroll: true });
}
function setResourceActive(resource: ResourceRow, active: boolean): void {
    router.post(active ? resource.activateUrl : resource.deactivateUrl, {}, { preserveScroll: true });
}

// A day with invalid open/close blocks its Save (the server also enforces this).
function hoursInvalid(branchId: string): boolean {
    return hours[branchId].some((day) => !day.is_closed && (!day.open_time || !day.close_time || day.close_time <= day.open_time));
}

// ── Master-detail (presentational, over the already-loaded branches) ───────────
// The left list selects a branch; the right column renders its 4 detail cards. Default to the
// primary branch (else the first). All per-branch reactive state is keyed by id, so switching just
// re-renders the same wired forms for the selected branch — no backend change.
const selectedId = ref<string>(props.branches.find((b) => b.is_primary)?.id ?? props.branches[0]?.id ?? '');
const selectedBranch = computed<Branch | undefined>(() => props.branches.find((b) => b.id === selectedId.value) ?? props.branches[0]);
const showCreate = ref(false);
const typeLabel = (type: string): string => t(`branchesAdmin.resources.type.${type}`);
</script>

<template>
    <AppLayout>
        <Head :title="t('branchesAdmin.title')" />
        <div class="settings-surface space-y-6">
            <!-- Header + Add branch -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('branchesAdmin.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('branchesAdmin.title') }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('branchesAdmin.subtitle') }}</p>
                    <Link :href="settingsUrl" class="mt-2 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('branchesAdmin.backToSettings') }}</Link>
                </div>
                <Button type="button" pill :block="false" @click="showCreate = !showCreate">{{ t('branchesAdmin.create.addAction') }}</Button>
            </div>

            <p v-if="flash && ['created', 'updated', 'hoursSaved', 'deactivated', 'activated', 'onlineBookingsEnabled', 'onlineBookingsSuspended', 'primarySet', 'resourceCreated', 'resourceUpdated', 'resourceDeactivated', 'resourceActivated'].includes(flash)" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t(`branchesAdmin.flash.${flash}`) }}
            </p>
            <p v-if="errors.branch === 'has_appointments'" class="rounded-2xl border border-danger/30 bg-danger-soft p-4 text-sm text-danger">
                {{ t('branchesAdmin.errors.invalidWindow') }}
            </p>
            <p v-if="errors.resource === 'has_appointments'" class="rounded-2xl border border-danger/30 bg-danger-soft p-4 text-sm text-danger">
                {{ t('branchesAdmin.resources.blocked') }}
            </p>

            <!-- Add-a-branch panel (toggled by the header button; the full modal wizard is P5). -->
            <div v-if="showCreate" class="glass-card euca-card-in p-5">
                <p class="text-base font-semibold text-ink">{{ t('branchesAdmin.create.title') }}</p>
                <p class="mt-0.5 text-sm text-ink-muted">{{ t('branchesAdmin.create.subtitle') }}</p>
                <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="createBranch">
                    <Input id="c-name" v-model="createForm.name" :label="t('branchesAdmin.fields.name')" :error="createForm.errors.name" />
                    <Input id="c-code" v-model="createForm.code" :label="t('branchesAdmin.fields.code')" :error="createForm.errors.code === 'taken' ? t('branchesAdmin.errors.codeTaken') : createForm.errors.code" />
                    <Input id="c-addr1" v-model="createForm.address_line1" :label="t('branchesAdmin.fields.addressLine1')" :error="createForm.errors.address_line1" />
                    <Input id="c-city" v-model="createForm.city" :label="t('branchesAdmin.fields.city')" :error="createForm.errors.city" />
                    <Input id="c-postal" v-model="createForm.postal_code" :label="t('branchesAdmin.fields.postalCode')" :error="createForm.errors.postal_code" />
                    <Input id="c-country" v-model="createForm.country" :label="t('branchesAdmin.fields.country')" :error="createForm.errors.country" />
                    <Input id="c-phone" v-model="createForm.phone" :label="t('branchesAdmin.fields.phone')" :error="createForm.errors.phone" />
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('branchesAdmin.fields.timezone') }}</span>
                        <select v-model="createForm.timezone" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                    </label>
                    <div class="flex items-center gap-2 sm:col-span-2">
                        <Button type="submit" pill :block="false" :disabled="createForm.processing || !createForm.name || !createForm.code">{{ t('branchesAdmin.create.submit') }}</Button>
                        <Button type="button" variant="ghost" pill :block="false" @click="showCreate = false">{{ t('branchesAdmin.create.cancel') }}</Button>
                    </div>
                </form>
            </div>

            <!-- ── MASTER-DETAIL: 300px branch list | detail column ─────────────────── -->
            <div class="grid items-start gap-5 lg:grid-cols-[300px_1fr]">
                <!-- LEFT: selectable branch list -->
                <div class="glass-card euca-card-in space-y-2 p-3">
                    <button
                        v-for="branch in branches"
                        :key="branch.id"
                        type="button"
                        class="w-full rounded-xl border border-transparent p-3 text-left transition hover:bg-euca-50/60"
                        :class="branch.id === selectedId ? 'border-l-[3px] border-l-euca-600 bg-euca-50/80' : ''"
                        @click="selectedId = branch.id"
                    >
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-euca-100 text-euca-800">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 21V8l9-5 9 5v13M9 21v-6h6v6" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" /></svg>
                            </span>
                            <span class="min-w-0 truncate font-semibold text-ink">{{ branch.name }}</span>
                        </div>
                        <p class="mt-1 text-xs text-ink-subtle">
                            {{ t('branchesAdmin.list.resources', { count: branch.active_resources }, branch.active_resources) }}
                            <span v-if="branch.is_primary"> · {{ t('branchesAdmin.primary.badge') }}</span>
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="branch.active ? 'bg-success-soft text-success' : 'bg-surface-2 text-ink-muted'">
                                <span class="h-1.5 w-1.5 rounded-full" :class="branch.active ? 'bg-success' : 'bg-ink-subtle'"></span>{{ branch.active ? t('branchesAdmin.status.active') : t('branchesAdmin.status.inactive') }}
                            </span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium" :class="branch.accepts_online_bookings ? 'bg-euca-50 text-euca-800' : 'bg-surface-2 text-ink-muted'">
                                {{ branch.accepts_online_bookings ? t('branchesAdmin.onlineBookings.on') : t('branchesAdmin.onlineBookings.off') }}
                            </span>
                        </div>
                    </button>
                </div>

                <!-- RIGHT: the selected branch's detail (4 stacked cards) -->
                <div v-if="selectedBranch" :key="selectedBranch.id" class="space-y-5">
                    <!-- Card 1 — Branch profile + online booking -->
                    <div class="glass-card euca-card-in p-6" :style="{ '--euca-card-delay': '0.02s' }">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-lg font-semibold text-ink">{{ selectedBranch.name }}</h2>
                                    <span v-if="selectedBranch.is_primary" class="rounded-full bg-euca-100 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ t('branchesAdmin.primary.badge') }}</span>
                                    <Button v-else-if="selectedBranch.active" type="button" variant="secondary" pill :block="false" @click="setPrimary(selectedBranch)">{{ t('branchesAdmin.primary.setAction') }}</Button>
                                </div>
                                <p class="mt-0.5 text-sm text-ink-muted">{{ t('branchesAdmin.profile.subtitle') }}</p>
                            </div>
                            <Button type="button" pill :block="false" @click="saveDetails(selectedBranch)">{{ t('branchesAdmin.actions.save') }}</Button>
                        </div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <Input :id="`${selectedBranch.id}-name`" v-model="details[selectedBranch.id].name" :label="t('branchesAdmin.fields.name')" />
                            <Input :id="`${selectedBranch.id}-code`" v-model="details[selectedBranch.id].code" :label="t('branchesAdmin.fields.code')" />
                            <Input :id="`${selectedBranch.id}-phone`" v-model="details[selectedBranch.id].phone" :label="t('branchesAdmin.fields.phone')" />
                            <Input :id="`${selectedBranch.id}-addr1`" v-model="details[selectedBranch.id].address_line1" :label="t('branchesAdmin.fields.addressLine1')" />
                            <Input :id="`${selectedBranch.id}-city`" v-model="details[selectedBranch.id].city" :label="t('branchesAdmin.fields.city')" />
                            <Input :id="`${selectedBranch.id}-postal`" v-model="details[selectedBranch.id].postal_code" :label="t('branchesAdmin.fields.postalCode')" />
                            <Input :id="`${selectedBranch.id}-country`" v-model="details[selectedBranch.id].country" :label="t('branchesAdmin.fields.country')" />
                            <label class="block">
                                <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('branchesAdmin.fields.timezone') }}</span>
                                <select v-model="details[selectedBranch.id].timezone" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                    <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                                </select>
                            </label>
                        </div>
                    </div>

                    <!-- Card 2 — Opening hours (the W8b per-day editor, re-skinned) -->
                    <div class="glass-card euca-card-in p-6" :style="{ '--euca-card-delay': '0.08s' }">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-ink">{{ t('branchesAdmin.hours.title') }}</h2>
                                <p class="mt-0.5 text-sm text-ink-muted">{{ t('branchesAdmin.hours.subtitle') }}</p>
                            </div>
                            <Button type="button" pill :block="false" :disabled="hoursInvalid(selectedBranch.id)" @click="saveHours(selectedBranch)">{{ t('branchesAdmin.hours.save') }}</Button>
                        </div>
                        <div class="mt-3 space-y-1">
                            <div v-for="day in hours[selectedBranch.id]" :key="day.weekday" class="flex flex-wrap items-center gap-3 border-b border-line/60 py-2 text-sm last:border-0">
                                <span class="w-28 font-medium text-ink">{{ t(`branchesAdmin.weekday.${day.weekday}`) }}</span>
                                <label class="inline-flex items-center gap-1.5 text-ink-muted">
                                    <input v-model="day.is_closed" type="checkbox" class="rounded border-line" />
                                    {{ t('branchesAdmin.hours.closed') }}
                                </label>
                                <template v-if="!day.is_closed">
                                    <label class="inline-flex items-center gap-1.5 text-ink-muted">
                                        {{ t('branchesAdmin.hours.openTime') }}
                                        <input v-model="day.open_time" type="time" class="rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" />
                                    </label>
                                    <label class="inline-flex items-center gap-1.5 text-ink-muted">
                                        {{ t('branchesAdmin.hours.closeTime') }}
                                        <input v-model="day.close_time" type="time" class="rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" />
                                    </label>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Card 3 — Resources (W8c CRUD; practitioner type read-only per P3, re-skinned) -->
                    <div class="glass-card euca-card-in p-6" :style="{ '--euca-card-delay': '0.14s' }">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-ink">{{ t('branchesAdmin.resources.title') }}</h2>
                                <p class="mt-0.5 text-sm text-ink-muted">{{ t('branchesAdmin.resources.subtitle') }}</p>
                            </div>
                        </div>
                        <p v-if="selectedBranch.resources.length === 0" class="mt-3 text-sm text-ink-subtle">{{ t('branchesAdmin.resources.empty') }}</p>
                        <div class="mt-3 space-y-1">
                            <div v-for="resource in selectedBranch.resources" :key="resource.id" class="flex flex-wrap items-center gap-2 border-b border-line/60 py-2 text-sm last:border-0">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="resource.active ? 'bg-success-soft text-success' : 'bg-surface-2 text-ink-muted'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="resource.active ? 'bg-success' : 'bg-ink-subtle'"></span>{{ resource.active ? t('branchesAdmin.status.active') : t('branchesAdmin.status.inactive') }}
                                </span>
                                <input v-model="resourceEdits[resource.id].name" class="w-40 rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" :aria-label="t('branchesAdmin.resources.name')" />
                                <!-- Practitioner: person-backed, type read-only (P3). Facility: editable select. -->
                                <span v-if="resource.type === 'practitioner'" class="rounded-full bg-euca-50 px-2.5 py-1 text-xs font-medium text-euca-800">{{ typeLabel(resource.type) }}</span>
                                <select v-else v-model="resourceEdits[resource.id].type" class="rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" :aria-label="t('branchesAdmin.resources.type.label')">
                                    <option v-for="rt in resourceTypes" :key="rt" :value="rt">{{ typeLabel(rt) }}</option>
                                </select>
                                <Button type="button" variant="secondary" pill :block="false" @click="saveResource(resource)">{{ t('branchesAdmin.actions.save') }}</Button>
                                <Button v-if="resource.active" type="button" variant="danger" pill :block="false" :disabled="resource.future_appointments > 0" @click="setResourceActive(resource, false)">{{ t('branchesAdmin.actions.deactivate') }}</Button>
                                <Button v-else type="button" variant="secondary" pill :block="false" @click="setResourceActive(resource, true)">{{ t('branchesAdmin.actions.activate') }}</Button>
                                <span v-if="resource.active && resource.future_appointments > 0" class="text-xs text-ink-subtle">{{ t('branchesAdmin.resources.hasAppointments', { count: resource.future_appointments }, resource.future_appointments) }}</span>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-end gap-2">
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-ink-muted">{{ t('branchesAdmin.resources.name') }}</span>
                                <input v-model="newResource[selectedBranch.id].name" class="w-40 rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-ink-muted">{{ t('branchesAdmin.resources.type.label') }}</span>
                                <select v-model="newResource[selectedBranch.id].type" class="rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink">
                                    <option v-for="rt in resourceTypes" :key="rt" :value="rt">{{ typeLabel(rt) }}</option>
                                </select>
                            </label>
                            <Button type="button" variant="secondary" pill :block="false" :disabled="!newResource[selectedBranch.id].name" @click="createResource(selectedBranch)">{{ t('branchesAdmin.resources.add') }}</Button>
                        </div>
                    </div>

                    <!-- Card 4 — Lifecycle (terracotta): soft-suspend vs hard-deactivate, DISTINCT actions -->
                    <div class="euca-card-in rounded-2xl border border-danger/25 bg-danger-soft/40 p-6" :style="{ '--euca-card-delay': '0.2s' }">
                        <h2 class="text-lg font-semibold text-ink">{{ t('branchesAdmin.danger.title') }}</h2>
                        <!-- Soft suspend (P1): stops NEW online bookings, keeps existing + the day-board. -->
                        <div class="mt-3 flex flex-wrap items-center gap-3 border-b border-danger/15 pb-4">
                            <div class="min-w-0">
                                <p class="font-medium text-ink">{{ t('branchesAdmin.onlineBookings.title') }}</p>
                                <p class="text-xs text-ink-muted">{{ t('branchesAdmin.onlineBookings.help') }}</p>
                            </div>
                            <span class="grow"></span>
                            <Button v-if="selectedBranch.accepts_online_bookings" type="button" variant="secondary" pill :block="false" @click="setOnlineBookings(selectedBranch, false)">{{ t('branchesAdmin.onlineBookings.suspend') }}</Button>
                            <Button v-else type="button" pill :block="false" @click="setOnlineBookings(selectedBranch, true)">{{ t('branchesAdmin.onlineBookings.enable') }}</Button>
                        </div>
                        <!-- Hard deactivate (W8b/P1): takes the branch offline entirely; BLOCKED while future appts. -->
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-ink">{{ selectedBranch.active ? t('branchesAdmin.danger.deactivateTitle') : t('branchesAdmin.danger.reactivateTitle') }}</p>
                                <p class="text-xs text-ink-muted">{{ t('branchesAdmin.danger.deactivateHelp') }}</p>
                                <p v-if="selectedBranch.active && selectedBranch.future_appointments > 0" class="mt-1 text-xs text-danger">
                                    {{ t('branchesAdmin.errors.hasAppointments', { count: selectedBranch.future_appointments }, selectedBranch.future_appointments) }}
                                </p>
                            </div>
                            <span class="grow"></span>
                            <Button v-if="selectedBranch.active" type="button" variant="danger" pill :block="false" :disabled="selectedBranch.future_appointments > 0" @click="setActive(selectedBranch, false)">{{ t('branchesAdmin.actions.deactivate') }}</Button>
                            <Button v-else type="button" variant="secondary" pill :block="false" @click="setActive(selectedBranch, true)">{{ t('branchesAdmin.actions.activate') }}</Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
