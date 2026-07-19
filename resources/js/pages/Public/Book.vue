<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
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

const currentStep = ref(0);
const steps = ['scheduling.public.steps.service', 'scheduling.public.steps.branch', 'scheduling.public.steps.time', 'scheduling.public.steps.details'];

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

const selectedService = computed(() => props.services.find((s) => s.id === slotFilters.service_id));
const selectedBranch = computed(() => props.branches.find((b) => b.id === slotFilters.branch_id));

async function loadSlots(): Promise<void> {
    if (!slotFilters.service_id || !slotFilters.branch_id || !slotFilters.date) {
        slots.value = [];
        return;
    }
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(props.slotsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, Accept: 'application/json' },
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

const canContinue = computed(() => (currentStep.value === 2 ? !!selected.value : true));
</script>

<template>
    <GuestLayout>
        <Head :title="t('scheduling.public.title')" />
        <div class="w-full max-w-md">
            <div class="mb-5">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('scheduling.public.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ tenant.name }}</h1>
            </div>

            <!-- The non-emergency notice persists on every step and is never dismissible (D-031). -->
            <div class="mb-5 flex items-start gap-2 rounded-xl border border-info/25 bg-info-soft px-4 py-3 text-sm text-ink">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-info" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                    <path d="M12 11v5M12 8v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                {{ t('scheduling.public.emergencyNotice') }}
            </div>

            <!-- Step indicator -->
            <ol class="mb-5 flex items-center gap-2">
                <li v-for="(step, index) in steps" :key="step" class="flex flex-1 items-center gap-2">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                        :class="index < currentStep ? 'bg-euca-700 text-euca-50' : index === currentStep ? 'bg-euca-800 text-euca-50' : 'bg-euca-100 text-euca-700'"
                    >
                        <svg v-if="index < currentStep" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4 4 10-10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        <template v-else>{{ index + 1 }}</template>
                    </span>
                    <span v-if="index < steps.length - 1" class="h-px flex-1" :class="index < currentStep ? 'bg-euca-400' : 'bg-line'"></span>
                </li>
            </ol>

            <div class="glass-card p-6">
                <!-- Step 1: service -->
                <div v-if="currentStep === 0">
                    <h2 class="mb-4 text-lg font-semibold text-ink">{{ t('scheduling.fields.service') }}</h2>
                    <select v-model="slotFilters.service_id" :aria-label="t('scheduling.fields.service')" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30">
                        <option v-for="service in services" :key="service.id" :value="service.id">{{ service.name }}</option>
                    </select>
                </div>

                <!-- Step 2: branch -->
                <div v-else-if="currentStep === 1">
                    <h2 class="mb-4 text-lg font-semibold text-ink">{{ t('scheduling.fields.branch') }}</h2>
                    <select v-model="slotFilters.branch_id" :aria-label="t('scheduling.fields.branch')" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30">
                        <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                    </select>
                </div>

                <!-- Step 3: time -->
                <div v-else-if="currentStep === 2">
                    <div class="mb-3 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-euca-50 px-2.5 py-1 text-euca-800">{{ selectedService?.name }}</span>
                        <span class="rounded-full bg-euca-50 px-2.5 py-1 text-euca-800">{{ selectedBranch?.name }}</span>
                    </div>
                    <h2 class="text-lg font-semibold text-ink">{{ t('scheduling.public.pickTime') }}</h2>
                    <p class="mb-3 text-sm text-ink-muted">{{ t('scheduling.public.pickTimeHint') }}</p>
                    <Input id="public-date" v-model="slotFilters.date" type="date" :label="t('scheduling.fields.date')" />
                    <div class="mt-4">
                        <SlotPicker :slots="slots" :selected="selected" @select="selectSlot" />
                    </div>
                </div>

                <!-- Step 4: details -->
                <div v-else>
                    <h2 class="text-lg font-semibold text-ink">{{ t('scheduling.public.detailsTitle') }}</h2>
                    <p class="mb-4 text-sm text-ink-muted">{{ t('scheduling.public.detailsHint') }}</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <Input id="public-first-name" v-model="form.first_name" :label="t('patients.fields.firstName')" :error="form.errors.first_name" required />
                        <Input id="public-last-name" v-model="form.last_name" :label="t('patients.fields.lastName')" :error="form.errors.last_name" required />
                        <Input id="public-dob" v-model="form.date_of_birth" type="date" :label="t('patients.fields.dateOfBirth')" :error="form.errors.date_of_birth" required />
                        <Input id="public-sex" v-model="form.sex" :label="t('patients.fields.sex')" :error="form.errors.sex" required />
                        <div class="sm:col-span-2">
                            <Input id="public-email" v-model="form.email" type="email" :label="t('patients.fields.email')" :error="form.errors.email" required />
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-ink-subtle">{{ t('scheduling.public.dedupeNote') }}</p>
                </div>

                <!-- Footer nav -->
                <div class="mt-6 flex items-center justify-between gap-3">
                    <button v-if="currentStep > 0" type="button" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-ink-muted transition hover:bg-euca-50 hover:text-ink" @click="currentStep -= 1">
                        {{ t('scheduling.public.back') }}
                    </button>
                    <span v-else></span>
                    <button v-if="currentStep < steps.length - 1" type="button" :disabled="!canContinue" class="btn-glow inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50" @click="currentStep += 1">
                        {{ t('scheduling.public.continue') }} ›
                    </button>
                    <button v-else type="button" :disabled="form.processing || !selected" class="btn-glow inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50" @click="submit">
                        {{ t('scheduling.public.confirmBooking') }} →
                    </button>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
