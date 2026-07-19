<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import StepNav from '@/Components/StepNav.vue';

const { t, te } = useI18n();

const props = defineProps<{
    duplicateCheckUrl: string;
    storeUrl: string;
}>();

const currentStep = ref(0);
const duplicateLoading = ref(false);
const duplicates = ref<
    Array<{
        id: string;
        name: string;
        mrn: string;
        date_of_birth: string;
        score: number;
        confidence: string;
        reasons?: string[];
        show_url: string;
    }>
>([]);

const form = useForm({
    first_name: '',
    last_name: '',
    date_of_birth: '',
    sex: '',
    gender: '',
    preferred_language: '',
    contacts: [
        { type: 'phone', value: '', is_primary: true },
        { type: 'email', value: '', is_primary: true },
        { type: 'address', line1: '', line2: '', city: '', postal: '', country: '', is_primary: true },
    ],
    identifiers: [{ system: '', value: '' }],
    coverages: [{ payer_name: '', member_id: '', plan: '', coverage_type: 'self_pay', priority: 1 }],
});

const address = computed(() => form.contacts[2]);
const steps = computed(() => [
    t('patients.register.steps.identity'),
    t('patients.register.steps.contacts'),
    t('patients.register.steps.optional'),
    t('patients.register.steps.review'),
]);

const duplicatePayload = reactive({
    first_name: '',
    last_name: '',
    date_of_birth: '',
    line1: '',
    city: '',
    postal: '',
});

let duplicateTimer: number | undefined;

watch(
    () => [form.first_name, form.last_name, form.date_of_birth, address.value.line1, address.value.city, address.value.postal],
    () => {
        duplicatePayload.first_name = form.first_name;
        duplicatePayload.last_name = form.last_name;
        duplicatePayload.date_of_birth = form.date_of_birth;
        duplicatePayload.line1 = String(address.value.line1 ?? '');
        duplicatePayload.city = String(address.value.city ?? '');
        duplicatePayload.postal = String(address.value.postal ?? '');
        window.clearTimeout(duplicateTimer);
        duplicateTimer = window.setTimeout(checkDuplicates, 300);
    },
);

async function checkDuplicates(): Promise<void> {
    if (!form.first_name || !form.last_name || !form.date_of_birth) {
        duplicates.value = [];
        return;
    }

    duplicateLoading.value = true;
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(props.duplicateCheckUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            Accept: 'application/json',
        },
        body: JSON.stringify(duplicatePayload),
    });
    const json = (await response.json()) as { duplicates: typeof duplicates.value };
    duplicates.value = json.duplicates;
    duplicateLoading.value = false;
}

function reasonLabel(reason: string): string {
    const key = `patients.register.reasons.${reason}`;
    return te(key) ? t(key) : reason.replace(/_/g, ' ');
}

function confidenceClass(confidence: string): string {
    if (confidence === 'high') return 'bg-warning-soft text-warning';
    if (confidence === 'medium') return 'bg-euca-100 text-euca-800';
    return 'bg-surface-2 text-ink-muted';
}

const reviewRows = computed(() => [
    {
        label: t('patients.register.steps.identity'),
        value: [
            `${form.first_name} ${form.last_name}`.trim(),
            form.date_of_birth,
            form.sex,
        ]
            .filter(Boolean)
            .join(' · '),
    },
    { label: t('patients.fields.phone'), value: form.contacts[0].value },
    { label: t('patients.fields.email'), value: form.contacts[1].value },
    {
        label: t('patients.fields.address'),
        value: [form.contacts[2].line1, `${form.contacts[2].postal} ${form.contacts[2].city}`.trim()]
            .filter((s) => s && String(s).trim())
            .join(' · '),
    },
    {
        label: t('patients.register.identifiers'),
        value: `${form.identifiers[0].system} ${form.identifiers[0].value}`.trim(),
    },
    {
        label: t('patients.register.coverages'),
        value: [form.coverages[0].payer_name, form.coverages[0].coverage_type].filter(Boolean).join(' · '),
    },
]);

function submit(): void {
    form.post(props.storeUrl);
}
</script>

<template>
    <AppLayout>
        <Head :title="t('patients.register.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">
                    {{ t('patients.register.eyebrow') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('patients.register.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('patients.register.subtitle') }}</p>
            </div>

            <StepNav :steps="steps" :current="currentStep" @select="currentStep = $event" />

            <form class="space-y-6" @submit.prevent="submit">
                <div v-if="currentStep === 0" class="glass-card p-6">
                    <h2 class="mb-5 text-lg font-semibold tracking-tight text-ink">{{ t('patients.register.steps.identity') }}</h2>
                    <div class="grid gap-4 md:grid-cols-2">
                        <Input id="first_name" v-model="form.first_name" :label="t('patients.fields.firstName')" :error="form.errors.first_name" required />
                        <Input id="last_name" v-model="form.last_name" :label="t('patients.fields.lastName')" :error="form.errors.last_name" required />
                        <Input id="date_of_birth" v-model="form.date_of_birth" type="date" :label="t('patients.fields.dateOfBirth')" :error="form.errors.date_of_birth" required />
                        <Input id="sex" v-model="form.sex" :label="t('patients.fields.sex')" :error="form.errors.sex" required />
                        <Input id="gender" v-model="form.gender" :label="t('patients.fields.gender')" :error="form.errors.gender" />
                        <Input id="preferred_language" v-model="form.preferred_language" :label="t('patients.fields.language')" :error="form.errors.preferred_language" />
                    </div>

                    <!-- The signature moment: live duplicate detection. -->
                    <div
                        v-if="duplicates.length > 0 || duplicateLoading"
                        class="mt-6 rounded-xl border border-warning/40 bg-warning-soft p-4"
                    >
                        <div v-if="duplicateLoading && duplicates.length === 0" class="flex items-center gap-2 text-sm text-ink-muted">
                            <svg class="h-4 w-4 animate-spin text-warning" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.3" stroke-width="2.5" />
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                            </svg>
                            {{ t('patients.register.duplicatesChecking') }}
                        </div>
                        <template v-else>
                            <div class="flex items-start justify-between gap-3">
                                <p class="flex items-center gap-2 text-sm font-semibold text-ink">
                                    <svg class="h-4 w-4 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 4 21 19H3L12 4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                        <path d="M12 10v4M12 16.5v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                    </svg>
                                    {{ t('patients.register.duplicatesTitle') }}
                                </p>
                                <span class="shrink-0 text-xs font-medium text-ink-muted">
                                    {{ t('patients.register.duplicatesCount', { count: duplicates.length }) }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-ink-muted">{{ t('patients.register.duplicatesHint') }}</p>
                            <ul class="mt-3 space-y-2">
                                <li
                                    v-for="candidate in duplicates"
                                    :key="candidate.id"
                                    class="flex flex-col gap-2 rounded-lg bg-surface px-3 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <span class="min-w-0">
                                        <span class="font-semibold text-ink">{{ candidate.name }}</span>
                                        <span v-if="candidate.reasons?.length" class="ml-2 text-ink-subtle">
                                            {{ candidate.reasons.map(reasonLabel).join(' · ') }}
                                        </span>
                                    </span>
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-ink-muted">{{ candidate.date_of_birth }}</span>
                                        <span class="rounded-md bg-surface-2 px-2 py-0.5 font-mono text-xs text-ink-muted">{{ candidate.mrn }}</span>
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="confidenceClass(candidate.confidence)">
                                            {{ te(`patients.register.confidence.${candidate.confidence}`) ? t(`patients.register.confidence.${candidate.confidence}`) : candidate.confidence }}
                                        </span>
                                        <Link :href="candidate.show_url" class="font-semibold text-euca-700 transition hover:text-euca-800">
                                            {{ t('patients.register.openDuplicate') }} →
                                        </Link>
                                    </span>
                                </li>
                            </ul>
                            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-xl border border-line bg-surface px-3.5 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                                    @click="currentStep = 1"
                                >
                                    {{ t('patients.register.notADuplicate') }}
                                </button>
                                <span class="text-xs text-ink-subtle">{{ t('patients.register.duplicateDiscardHint') }}</span>
                            </div>
                        </template>
                    </div>
                </div>

                <div v-if="currentStep === 1" class="glass-card p-6">
                    <h2 class="mb-5 text-lg font-semibold tracking-tight text-ink">{{ t('patients.register.steps.contacts') }}</h2>
                    <div class="grid gap-4 md:grid-cols-2">
                        <Input id="phone" v-model="form.contacts[0].value" :label="t('patients.fields.phone')" />
                        <Input id="email" v-model="form.contacts[1].value" type="email" :label="t('patients.fields.email')" />
                        <Input id="line1" v-model="form.contacts[2].line1" :label="t('patients.fields.address')" />
                        <Input id="city" v-model="form.contacts[2].city" :label="t('patients.fields.city')" />
                        <Input id="postal" v-model="form.contacts[2].postal" :label="t('patients.fields.postal')" />
                        <Input id="country" v-model="form.contacts[2].country" :label="t('patients.fields.country')" />
                    </div>
                </div>

                <div v-if="currentStep === 2" class="glass-card p-6">
                    <h2 class="mb-5 text-lg font-semibold tracking-tight text-ink">{{ t('patients.register.steps.optional') }}</h2>
                    <div class="grid gap-6 md:grid-cols-2">
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-ink">{{ t('patients.register.identifiers') }}</h3>
                            <Input id="identifier_system" v-model="form.identifiers[0].system" :label="t('patients.fields.identifierSystem')" />
                            <Input id="identifier_value" v-model="form.identifiers[0].value" :label="t('patients.fields.identifierValue')" />
                        </div>
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-ink">{{ t('patients.register.coverages') }}</h3>
                            <Input id="payer_name" v-model="form.coverages[0].payer_name" :label="t('patients.fields.payer')" />
                            <Input id="member_id" v-model="form.coverages[0].member_id" :label="t('patients.fields.memberId')" />
                            <Input id="plan" v-model="form.coverages[0].plan" :label="t('patients.fields.plan')" />
                        </div>
                    </div>
                </div>

                <div v-if="currentStep === 3" class="glass-card p-6">
                    <div class="mb-5 flex items-center justify-between">
                        <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('patients.register.steps.review') }}</h2>
                        <span class="text-xs text-ink-subtle">{{ t('patients.register.reviewSummary') }}</span>
                    </div>
                    <dl class="divide-y divide-line/70">
                        <div v-for="row in reviewRows" :key="row.label" class="flex items-start justify-between gap-4 py-3">
                            <dt class="text-sm text-ink-muted">{{ row.label }}</dt>
                            <dd class="text-right text-sm font-medium text-ink">{{ row.value || '—' }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <button
                        v-if="currentStep > 0"
                        type="button"
                        class="rounded-xl px-4 py-2.5 text-sm font-semibold text-ink-muted transition hover:bg-euca-50 hover:text-ink"
                        @click="currentStep -= 1"
                    >
                        {{ t('patients.register.back') }}
                    </button>
                    <span v-else></span>
                    <span class="text-xs text-ink-subtle">{{ t('patients.register.stepCounter', { current: currentStep + 1, total: steps.length }) }}</span>
                    <Button v-if="currentStep < 3" :block="false" @click="currentStep += 1">{{ t('patients.register.next') }} ›</Button>
                    <Button v-else :block="false" type="submit" :disabled="form.processing">{{ t('patients.register.create') }}</Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
