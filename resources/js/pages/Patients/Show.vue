<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import DataList from '@/Components/DataList.vue';
import Input from '@/Components/Input.vue';
import Tabs from '@/Components/Tabs.vue';

const { t } = useI18n();

const props = defineProps<{
    patient: {
        id: string;
        mrn: string;
        first_name: string;
        last_name: string;
        date_of_birth: string;
        age: number;
        sex: string;
        gender: string | null;
        preferred_language: string | null;
        status: string;
        contacts: Array<Record<string, string | boolean | null>>;
        identifiers: Array<Record<string, string>>;
        coverages: Array<Record<string, string | number | null>>;
        consents: Array<{
            id: string;
            template_key: string;
            template_title: string;
            template_version: number;
            scope_keys: string[];
            status: string;
            granted_at: string | null;
            withdrawn_at: string | null;
            expires_at: string | null;
            withdraw_url: string;
        }>;
    };
    accessLog: Array<{ actor_type: string; actor_id: string | null; occurred_at: string; resource_type: string }>;
    actions: { can_edit: boolean; grant_consent_url: string };
}>();

const activeTab = ref('demographics');
const consentTemplateKey = ref('portal');
const consentSignature = ref('');
const withdrawReason = ref('');

const tabs = computed(() => [
    { key: 'demographics', label: t('patients.show.tabs.demographics') },
    { key: 'contacts', label: t('patients.show.tabs.contacts') },
    { key: 'coverages', label: t('patients.show.tabs.coverages') },
    { key: 'consents', label: t('patients.show.tabs.consents') },
    { key: 'access', label: t('patients.show.tabs.access') },
]);

const demographics = computed(() => [
    { label: t('patients.fields.mrn'), value: props.patient.mrn },
    { label: t('patients.fields.dateOfBirth'), value: `${props.patient.date_of_birth} (${props.patient.age})` },
    { label: t('patients.fields.sex'), value: props.patient.sex },
    { label: t('patients.fields.gender'), value: props.patient.gender },
    { label: t('patients.fields.language'), value: props.patient.preferred_language },
    { label: t('patients.fields.status'), value: props.patient.status },
]);

function grantConsent(): void {
    router.post(props.actions.grant_consent_url, {
        template_key: consentTemplateKey.value,
        signature: consentSignature.value,
    });
}

function withdrawConsent(url: string): void {
    router.post(url, { reason: withdrawReason.value });
}
</script>

<template>
    <AppLayout>
        <Head :title="`${patient.first_name} ${patient.last_name}`" />
        <div class="space-y-6">
            <Card>
                <div class="flex flex-col justify-between gap-4 md:flex-row md:items-start">
                    <div>
                        <p class="text-sm font-semibold uppercase text-brand-700">{{ patient.mrn }}</p>
                        <h1 class="mt-1 text-3xl font-semibold text-ink">{{ patient.first_name }} {{ patient.last_name }}</h1>
                        <p class="mt-2 text-sm text-ink-muted">{{ patient.date_of_birth }} · {{ patient.age }} · {{ patient.status }}</p>
                    </div>
                    <span class="inline-flex rounded-md border border-line px-3 py-1 text-sm font-semibold text-ink-muted">{{ t('patients.show.headerFlag') }}</span>
                </div>
            </Card>

            <Card>
                <Tabs v-model:active="activeTab" :tabs="tabs">
                    <section v-if="activeTab === 'demographics'">
                        <DataList :items="demographics" />
                    </section>

                    <section v-if="activeTab === 'contacts'" class="grid gap-4 md:grid-cols-2">
                        <div v-for="contact in patient.contacts" :key="String(contact.id)" class="rounded-md border border-line p-4">
                            <p class="text-sm font-semibold text-ink">{{ contact.type }}</p>
                            <p class="mt-1 text-sm text-ink-muted">{{ contact.value || contact.line1 || '-' }}</p>
                            <p class="text-sm text-ink-muted">{{ contact.city }} {{ contact.postal }} {{ contact.country }}</p>
                        </div>
                    </section>

                    <section v-if="activeTab === 'coverages'" class="space-y-3">
                        <div v-for="coverage in patient.coverages" :key="String(coverage.id)" class="rounded-md border border-line p-4 text-sm">
                            <p class="font-semibold text-ink">{{ coverage.payer_name }}</p>
                            <p class="text-ink-muted">{{ coverage.member_id }} · {{ coverage.coverage_type }} · {{ coverage.plan || '-' }}</p>
                        </div>
                        <p v-if="patient.coverages.length === 0" class="text-sm text-ink-muted">{{ t('patients.show.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'consents'" class="space-y-4">
                        <div v-if="actions.can_edit" class="grid gap-3 rounded-md border border-line p-4 md:grid-cols-[1fr_1fr_140px]">
                            <Input id="consent_template_key" v-model="consentTemplateKey" :label="t('patients.show.consentTemplate')" />
                            <Input id="consent_signature" v-model="consentSignature" :label="t('patients.show.consentSignature')" />
                            <div class="flex items-end">
                                <Button @click="grantConsent">{{ t('patients.show.grantConsent') }}</Button>
                            </div>
                        </div>
                        <div v-for="consent in patient.consents" :key="consent.id" class="rounded-md border border-line p-4">
                            <div class="flex flex-col justify-between gap-3 md:flex-row">
                                <div>
                                    <p class="font-semibold text-ink">{{ consent.template_title }}</p>
                                    <p class="text-sm text-ink-muted">{{ consent.template_key }} v{{ consent.template_version }} · {{ consent.status }}</p>
                                    <p class="mt-1 text-xs text-ink-subtle">{{ consent.scope_keys.join(', ') }}</p>
                                </div>
                                <div v-if="actions.can_edit && consent.status === 'granted'" class="flex gap-2">
                                    <Input id="withdraw_reason" v-model="withdrawReason" :label="t('patients.show.withdrawReason')" />
                                    <div class="flex items-end">
                                        <Button variant="secondary" @click="withdrawConsent(consent.withdraw_url)">
                                            {{ t('patients.show.withdraw') }}
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-if="patient.consents.length === 0" class="text-sm text-ink-muted">{{ t('patients.show.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'access'" class="space-y-3">
                        <div v-for="entry in accessLog" :key="`${entry.actor_type}-${entry.actor_id}-${entry.occurred_at}`" class="rounded-md border border-line p-4 text-sm">
                            <p class="font-semibold text-ink">{{ entry.actor_type }} {{ entry.actor_id || '-' }}</p>
                            <p class="text-ink-muted">{{ entry.resource_type }} · {{ entry.occurred_at }}</p>
                        </div>
                    </section>
                </Tabs>
            </Card>
        </div>
    </AppLayout>
</template>
