<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
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
    active: boolean;
    active_resources: number;
    future_appointments: number;
    hours: DayHours[];
    resources: ResourceRow[];
    resourceStoreUrl: string;
    updateUrl: string;
    hoursUrl: string;
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
    };
    hours[branch.id] = branch.hours.map((day) => ({ ...day }));
    newResource[branch.id] = { name: '', type: defaultType() };
    branch.resources.forEach((resource) => {
        resourceEdits[resource.id] = { name: resource.name, type: resource.type };
    });
});

function createBranch(): void {
    createForm.post(props.storeUrl, { preserveScroll: true, onSuccess: () => createForm.reset() });
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
</script>

<template>
    <AppLayout>
        <Head :title="t('branchesAdmin.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('branchesAdmin.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('branchesAdmin.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('branchesAdmin.subtitle') }}</p>
                <Link :href="settingsUrl" class="mt-2 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('branchesAdmin.backToSettings') }}</Link>
            </div>

            <p v-if="flash && ['created', 'updated', 'hoursSaved', 'deactivated', 'activated', 'resourceCreated', 'resourceUpdated', 'resourceDeactivated', 'resourceActivated'].includes(flash)" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t(`branchesAdmin.flash.${flash}`) }}
            </p>
            <p v-if="errors.branch === 'has_appointments'" class="rounded-2xl border border-danger/30 bg-danger-soft p-4 text-sm text-danger">
                {{ t('branchesAdmin.errors.invalidWindow') }}
            </p>
            <p v-if="errors.resource === 'has_appointments'" class="rounded-2xl border border-danger/30 bg-danger-soft p-4 text-sm text-danger">
                {{ t('branchesAdmin.resources.blocked') }}
            </p>

            <!-- Add a branch. -->
            <Card :title="t('branchesAdmin.create.title')" :subtitle="t('branchesAdmin.create.subtitle')">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="createBranch">
                    <Input id="c-name" v-model="createForm.name" :label="t('branchesAdmin.fields.name')" :error="createForm.errors.name" />
                    <Input id="c-code" v-model="createForm.code" :label="t('branchesAdmin.fields.code')" :error="createForm.errors.code === 'taken' ? t('branchesAdmin.errors.codeTaken') : createForm.errors.code" />
                    <Input id="c-addr1" v-model="createForm.address_line1" :label="t('branchesAdmin.fields.addressLine1')" :error="createForm.errors.address_line1" />
                    <Input id="c-city" v-model="createForm.city" :label="t('branchesAdmin.fields.city')" :error="createForm.errors.city" />
                    <Input id="c-postal" v-model="createForm.postal_code" :label="t('branchesAdmin.fields.postalCode')" :error="createForm.errors.postal_code" />
                    <Input id="c-country" v-model="createForm.country" :label="t('branchesAdmin.fields.country')" :error="createForm.errors.country" />
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('branchesAdmin.fields.timezone') }}</span>
                        <select v-model="createForm.timezone" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                    </label>
                    <div class="sm:col-span-2">
                        <Button type="submit" :block="false" :disabled="createForm.processing || !createForm.name || !createForm.code">{{ t('branchesAdmin.create.submit') }}</Button>
                    </div>
                </form>
            </Card>

            <!-- Each branch: details, hours, status. -->
            <Card v-for="branch in branches" :key="branch.id" :title="branch.name">
                <div class="space-y-5">
                    <!-- status row -->
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="branch.active ? 'bg-success-soft text-success' : 'bg-surface-2 text-ink-muted'">
                            {{ branch.active ? t('branchesAdmin.status.active') : t('branchesAdmin.status.inactive') }}
                        </span>
                        <span class="text-ink-muted">{{ t('branchesAdmin.list.resources', { count: branch.active_resources }, branch.active_resources) }}</span>
                        <span class="text-ink-muted">·</span>
                        <span class="text-ink-muted">{{ t('branchesAdmin.list.appointments', { count: branch.future_appointments }, branch.future_appointments) }}</span>
                        <span class="grow"></span>
                        <Button v-if="branch.active" type="button" variant="danger" :block="false" :disabled="branch.future_appointments > 0" @click="setActive(branch, false)">{{ t('branchesAdmin.actions.deactivate') }}</Button>
                        <Button v-else type="button" variant="secondary" :block="false" @click="setActive(branch, true)">{{ t('branchesAdmin.actions.activate') }}</Button>
                    </div>
                    <p v-if="branch.active && branch.future_appointments > 0" class="text-xs text-ink-subtle">
                        {{ t('branchesAdmin.errors.hasAppointments', { count: branch.future_appointments }, branch.future_appointments) }}
                    </p>
                    <p v-if="branch.active_resources === 0" class="text-xs text-ink-subtle">{{ t('branchesAdmin.list.noResources') }}</p>

                    <!-- editable details -->
                    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveDetails(branch)">
                        <Input :id="`${branch.id}-name`" v-model="details[branch.id].name" :label="t('branchesAdmin.fields.name')" />
                        <Input :id="`${branch.id}-code`" v-model="details[branch.id].code" :label="t('branchesAdmin.fields.code')" />
                        <Input :id="`${branch.id}-addr1`" v-model="details[branch.id].address_line1" :label="t('branchesAdmin.fields.addressLine1')" />
                        <Input :id="`${branch.id}-city`" v-model="details[branch.id].city" :label="t('branchesAdmin.fields.city')" />
                        <Input :id="`${branch.id}-postal`" v-model="details[branch.id].postal_code" :label="t('branchesAdmin.fields.postalCode')" />
                        <Input :id="`${branch.id}-country`" v-model="details[branch.id].country" :label="t('branchesAdmin.fields.country')" />
                        <label class="block">
                            <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('branchesAdmin.fields.timezone') }}</span>
                            <select v-model="details[branch.id].timezone" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                            </select>
                        </label>
                        <div class="sm:col-span-2">
                            <Button type="submit" variant="secondary" :block="false">{{ t('branchesAdmin.actions.save') }}</Button>
                        </div>
                    </form>

                    <!-- opening hours -->
                    <div>
                        <p class="text-sm font-semibold text-ink">{{ t('branchesAdmin.hours.title') }}</p>
                        <p class="mt-0.5 text-xs text-ink-muted">{{ t('branchesAdmin.hours.subtitle') }}</p>
                        <div class="mt-3 space-y-2">
                            <div v-for="day in hours[branch.id]" :key="day.weekday" class="flex flex-wrap items-center gap-3 text-sm">
                                <span class="w-24 font-medium text-ink">{{ t(`branchesAdmin.weekday.${day.weekday}`) }}</span>
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
                        <Button type="button" variant="secondary" :block="false" :disabled="hoursInvalid(branch.id)" class="mt-3" @click="saveHours(branch)">{{ t('branchesAdmin.hours.save') }}</Button>
                    </div>

                    <!-- bookable resources (rooms/chairs/vehicles) -->
                    <div>
                        <p class="text-sm font-semibold text-ink">{{ t('branchesAdmin.resources.title') }}</p>
                        <p class="mt-0.5 text-xs text-ink-muted">{{ t('branchesAdmin.resources.subtitle') }}</p>
                        <div class="mt-3 space-y-2">
                            <p v-if="branch.resources.length === 0" class="text-xs text-ink-subtle">{{ t('branchesAdmin.resources.empty') }}</p>
                            <div v-for="resource in branch.resources" :key="resource.id" class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="resource.active ? 'bg-success-soft text-success' : 'bg-surface-2 text-ink-muted'">
                                    {{ resource.active ? t('branchesAdmin.status.active') : t('branchesAdmin.status.inactive') }}
                                </span>
                                <input v-model="resourceEdits[resource.id].name" class="w-40 rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" :aria-label="t('branchesAdmin.resources.name')" />
                                <select v-model="resourceEdits[resource.id].type" class="rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" :aria-label="t('branchesAdmin.resources.type.label')">
                                    <option v-for="rt in resourceTypes" :key="rt" :value="rt">{{ t(`branchesAdmin.resources.type.${rt}`) }}</option>
                                </select>
                                <Button type="button" variant="secondary" :block="false" @click="saveResource(resource)">{{ t('branchesAdmin.actions.save') }}</Button>
                                <Button v-if="resource.active" type="button" variant="danger" :block="false" :disabled="resource.future_appointments > 0" @click="setResourceActive(resource, false)">{{ t('branchesAdmin.actions.deactivate') }}</Button>
                                <Button v-else type="button" variant="secondary" :block="false" @click="setResourceActive(resource, true)">{{ t('branchesAdmin.actions.activate') }}</Button>
                                <span v-if="resource.active && resource.future_appointments > 0" class="text-xs text-ink-subtle">{{ t('branchesAdmin.resources.hasAppointments', { count: resource.future_appointments }, resource.future_appointments) }}</span>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-end gap-2">
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-ink-muted">{{ t('branchesAdmin.resources.name') }}</span>
                                <input v-model="newResource[branch.id].name" class="w-40 rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-ink-muted">{{ t('branchesAdmin.resources.type.label') }}</span>
                                <select v-model="newResource[branch.id].type" class="rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink">
                                    <option v-for="rt in resourceTypes" :key="rt" :value="rt">{{ t(`branchesAdmin.resources.type.${rt}`) }}</option>
                                </select>
                            </label>
                            <Button type="button" variant="secondary" :block="false" :disabled="!newResource[branch.id].name" @click="createResource(branch)">{{ t('branchesAdmin.resources.add') }}</Button>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
