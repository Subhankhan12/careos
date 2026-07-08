<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import StepNav from '@/Components/StepNav.vue';

const { t } = useI18n();

const props = defineProps<{
    duplicateCheckUrl: string;
    storeUrl: string;
}>();

const currentStep = ref(0);
const duplicateLoading = ref(false);
const duplicates = ref<Array<{ id: string; name: string; mrn: string; date_of_birth: string; score: number; confidence: string; show_url: string }>>([]);

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

function submit(): void {
    form.post(props.storeUrl);
}
</script>

<template>
    <AppLayout>
        <Head :title="t('patients.register.title')" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ t('patients.register.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('patients.register.subtitle') }}</p>
            </div>

            <StepNav :steps="steps" :current="currentStep" @select="currentStep = $event" />

            <form class="space-y-6" @submit.prevent="submit">
                <Card v-if="currentStep === 0">
                    <div class="grid gap-4 md:grid-cols-2">
                        <Input id="first_name" v-model="form.first_name" :label="t('patients.fields.firstName')" :error="form.errors.first_name" required />
                        <Input id="last_name" v-model="form.last_name" :label="t('patients.fields.lastName')" :error="form.errors.last_name" required />
                        <Input id="date_of_birth" v-model="form.date_of_birth" type="date" :label="t('patients.fields.dateOfBirth')" :error="form.errors.date_of_birth" required />
                        <Input id="sex" v-model="form.sex" :label="t('patients.fields.sex')" :error="form.errors.sex" required />
                        <Input id="gender" v-model="form.gender" :label="t('patients.fields.gender')" :error="form.errors.gender" />
                        <Input id="preferred_language" v-model="form.preferred_language" :label="t('patients.fields.language')" :error="form.errors.preferred_language" />
                    </div>
                    <div v-if="duplicates.length > 0 || duplicateLoading" class="mt-6 rounded-md border border-brand-200 bg-brand-50 p-4">
                        <p class="text-sm font-semibold text-brand-900">{{ t('patients.register.duplicatesTitle') }}</p>
                        <p class="mt-1 text-sm text-brand-800">{{ duplicateLoading ? t('patients.register.duplicatesChecking') : t('patients.register.duplicatesHint') }}</p>
                        <ul class="mt-3 space-y-2">
                            <li v-for="candidate in duplicates" :key="candidate.id" class="flex items-center justify-between gap-3 rounded-md bg-surface px-3 py-2 text-sm">
                                <span>
                                    <span class="font-semibold text-ink">{{ candidate.name }}</span>
                                    <span class="ml-2 text-ink-muted">{{ candidate.mrn }} · {{ candidate.date_of_birth }} · {{ candidate.confidence }}</span>
                                </span>
                                <Link :href="candidate.show_url" class="font-semibold text-brand-700 hover:text-brand-900">
                                    {{ t('patients.register.openDuplicate') }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </Card>

                <Card v-if="currentStep === 1">
                    <div class="grid gap-4 md:grid-cols-2">
                        <Input id="phone" v-model="form.contacts[0].value" :label="t('patients.fields.phone')" />
                        <Input id="email" v-model="form.contacts[1].value" type="email" :label="t('patients.fields.email')" />
                        <Input id="line1" v-model="form.contacts[2].line1" :label="t('patients.fields.address')" />
                        <Input id="city" v-model="form.contacts[2].city" :label="t('patients.fields.city')" />
                        <Input id="postal" v-model="form.contacts[2].postal" :label="t('patients.fields.postal')" />
                        <Input id="country" v-model="form.contacts[2].country" :label="t('patients.fields.country')" />
                    </div>
                </Card>

                <Card v-if="currentStep === 2">
                    <div class="grid gap-6 md:grid-cols-2">
                        <div class="space-y-4">
                            <h2 class="text-lg font-semibold text-ink">{{ t('patients.register.identifiers') }}</h2>
                            <Input id="identifier_system" v-model="form.identifiers[0].system" :label="t('patients.fields.identifierSystem')" />
                            <Input id="identifier_value" v-model="form.identifiers[0].value" :label="t('patients.fields.identifierValue')" />
                        </div>
                        <div class="space-y-4">
                            <h2 class="text-lg font-semibold text-ink">{{ t('patients.register.coverages') }}</h2>
                            <Input id="payer_name" v-model="form.coverages[0].payer_name" :label="t('patients.fields.payer')" />
                            <Input id="member_id" v-model="form.coverages[0].member_id" :label="t('patients.fields.memberId')" />
                            <Input id="plan" v-model="form.coverages[0].plan" :label="t('patients.fields.plan')" />
                        </div>
                    </div>
                </Card>

                <Card v-if="currentStep === 3">
                    <div class="space-y-3 text-sm text-ink">
                        <p><strong>{{ t('patients.fields.name') }}:</strong> {{ form.first_name }} {{ form.last_name }}</p>
                        <p><strong>{{ t('patients.fields.dateOfBirth') }}:</strong> {{ form.date_of_birth }}</p>
                        <p><strong>{{ t('patients.fields.phone') }}:</strong> {{ form.contacts[0].value || '-' }}</p>
                        <p><strong>{{ t('patients.fields.address') }}:</strong> {{ form.contacts[2].line1 || '-' }}</p>
                    </div>
                </Card>

                <div class="flex justify-between gap-3">
                    <Button v-if="currentStep > 0" variant="secondary" @click="currentStep -= 1">{{ t('patients.register.back') }}</Button>
                    <span v-else></span>
                    <Button v-if="currentStep < 3" @click="currentStep += 1">{{ t('patients.register.next') }}</Button>
                    <Button v-else type="submit" :disabled="form.processing">{{ t('patients.register.create') }}</Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
