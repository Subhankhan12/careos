<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import AllergyBanner from '@/Components/AllergyBanner.vue';
import Card from '@/Components/Card.vue';
import Tabs from '@/Components/Tabs.vue';
import Timeline from '@/Components/Timeline.vue';
import VersionHistory from '@/Components/VersionHistory.vue';

const { t } = useI18n();

const props = defineProps<{
    patient: { id: string; mrn: string; name: string; date_of_birth: string; sex: string; status: string };
    encounters: Array<{ id: string; type: string; status: string; started_at: string; ended_at: string | null }>;
    notes: Array<{
        id: string;
        status: string;
        version: number;
        author_name: string;
        created_at: string | null;
        signed_at: string | null;
        edit_url: string;
        versions: Array<{ id: string; version: number; status: string; author_name: string; created_at: string | null; signed_at: string | null; amendment_reason: string | null; edit_url: string }>;
    }>;
    problems: Array<{ id: string; description: string; code: string | null; status: string; recorded_at: string; resolved_at: string | null }>;
    allergies: Array<{ id: string; substance: string; reaction: string | null; severity: string; status: string; verified_at: string | null }>;
    vitals: Array<{ id: string; recorded_at: string; systolic: number | null; diastolic: number | null; heart_rate: number | null; temperature_c: string | null; spo2: number | null; weight_g: number | null; height_mm: number | null; extra: Record<string, unknown> | null }>;
    medications: Array<{ id: string; name: string; dose_text: string | null; route: string | null; frequency_text: string | null; status: string; started_on: string; ended_on: string | null }>;
    documents: Array<{ id: string; category: string; title: string; original_filename: string; uploaded_at: string; shared_with_patient: boolean; download_url: string }>;
    carePlans: Array<{
        id: string;
        title: string;
        status: string;
        started_on: string;
        ended_on: string | null;
        goals: Array<{ id: string; description: string; target_date: string | null; status: string }>;
    }>;
    referrals: Array<{
        id: string;
        direction: string;
        status: string;
        specialty: string | null;
        reason: string;
        to_provider_name: string | null;
        from_provider_name: string | null;
        to_branch_id: string | null;
        sent_at: string | null;
        responded_at: string | null;
        notes: string | null;
    }>;
    recalls: Array<{ id: string; rule_id: string; rule_name: string; due_on: string; status: string }>;
}>();

const activeTab = ref('timeline');

const tabs = computed(() => [
    { key: 'timeline', label: t('clinical.chart.tabs.timeline') },
    { key: 'notes', label: t('clinical.chart.tabs.notes') },
    { key: 'problems', label: t('clinical.chart.tabs.problems') },
    { key: 'vitals', label: t('clinical.chart.tabs.vitals') },
    { key: 'medications', label: t('clinical.chart.tabs.medications') },
    { key: 'documents', label: t('clinical.chart.tabs.documents') },
    { key: 'care', label: t('clinical.chart.tabs.care') },
]);

const encounterTimeline = computed(() =>
    props.encounters.map((encounter) => ({
        id: encounter.id,
        title: encounter.type,
        subtitle: encounter.status,
        at: encounter.started_at,
    })),
);

function rawVital(vital: typeof props.vitals[number]): string {
    return [
        vital.systolic !== null || vital.diastolic !== null ? `${vital.systolic ?? '-'} / ${vital.diastolic ?? '-'}` : null,
        vital.heart_rate !== null ? `${vital.heart_rate}` : null,
        vital.temperature_c !== null ? `${vital.temperature_c} C` : null,
        vital.spo2 !== null ? `${vital.spo2}%` : null,
        vital.weight_g !== null ? `${vital.weight_g} g` : null,
        vital.height_mm !== null ? `${vital.height_mm} mm` : null,
    ].filter(Boolean).join(' | ');
}
</script>

<template>
    <AppLayout>
        <Head :title="t('clinical.chart.title')" />
        <div class="space-y-6">
            <Card>
                <p class="text-sm font-semibold uppercase text-brand-700">{{ patient.mrn }}</p>
                <h1 class="mt-1 text-3xl font-semibold text-ink">{{ patient.name }}</h1>
                <p class="mt-2 text-sm text-ink-muted">{{ patient.date_of_birth }} | {{ patient.sex }} | {{ patient.status }}</p>
            </Card>

            <AllergyBanner :allergies="allergies" />

            <Card>
                <Tabs v-model:active="activeTab" :tabs="tabs">
                    <section v-if="activeTab === 'timeline'">
                        <Timeline :items="encounterTimeline" />
                    </section>

                    <section v-if="activeTab === 'notes'" class="space-y-4">
                        <div v-for="note in notes" :key="note.id" class="rounded-md border border-line p-4">
                            <div class="flex flex-col justify-between gap-2 sm:flex-row">
                                <div>
                                    <p class="font-semibold text-ink">{{ t('clinical.note.versionLabel', { version: note.version }) }}</p>
                                    <p class="text-sm text-ink-muted">{{ note.author_name }} | {{ note.status }} | {{ note.signed_at || note.created_at || '-' }}</p>
                                </div>
                                <Link :href="note.edit_url" class="text-sm font-semibold text-brand-700 hover:text-brand-900">
                                    {{ t('clinical.note.open') }}
                                </Link>
                            </div>
                            <div class="mt-4">
                                <VersionHistory :versions="note.versions" />
                            </div>
                        </div>
                        <p v-if="notes.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'problems'" class="space-y-3">
                        <div v-for="problem in problems" :key="problem.id" class="rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ problem.description }}</p>
                            <p class="text-sm text-ink-muted">{{ problem.code || '-' }} | {{ problem.status }} | {{ problem.recorded_at }}</p>
                        </div>
                        <p v-if="problems.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'vitals'" class="space-y-3">
                        <div v-for="vital in vitals" :key="vital.id" class="rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ vital.recorded_at }}</p>
                            <p class="text-sm text-ink-muted">{{ rawVital(vital) || '-' }}</p>
                        </div>
                        <p v-if="vitals.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'medications'" class="space-y-3">
                        <div v-for="medication in medications" :key="medication.id" class="rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ medication.name }}</p>
                            <p class="text-sm text-ink-muted">{{ medication.dose_text || '-' }} | {{ medication.route || '-' }} | {{ medication.frequency_text || '-' }} | {{ medication.status }}</p>
                        </div>
                        <p v-if="medications.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'documents'" class="space-y-3">
                        <div v-for="document in documents" :key="document.id" class="rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ document.title }}</p>
                            <p class="text-sm text-ink-muted">{{ document.category }} | {{ document.original_filename }} | {{ document.uploaded_at }}</p>
                            <Link :href="document.download_url" class="mt-2 inline-flex text-sm font-semibold text-brand-700 hover:text-brand-900">
                                {{ t('clinical.chart.download') }}
                            </Link>
                        </div>
                        <p v-if="documents.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'care'" class="grid gap-4 lg:grid-cols-3">
                        <div class="space-y-3 rounded-md border border-line p-4 lg:col-span-2">
                            <p class="font-semibold text-ink">{{ t('clinical.chart.carePlans') }}</p>
                            <div v-for="plan in carePlans" :key="plan.id" class="rounded-md border border-line p-3">
                                <p class="font-semibold text-ink">{{ plan.title }}</p>
                                <p class="text-sm text-ink-muted">{{ plan.status }} | {{ plan.started_on }} | {{ plan.ended_on || '-' }}</p>
                                <div class="mt-3 space-y-2">
                                    <p class="text-xs font-semibold uppercase text-ink-subtle">{{ t('clinical.chart.carePlanGoals') }}</p>
                                    <p v-for="goal in plan.goals" :key="goal.id" class="text-sm text-ink-muted">
                                        {{ goal.description }} | {{ goal.status }} | {{ goal.target_date || '-' }}
                                    </p>
                                    <p v-if="plan.goals.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                                </div>
                            </div>
                            <p v-if="carePlans.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                        </div>
                        <div class="space-y-3 rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ t('clinical.chart.referrals') }}</p>
                            <div v-for="referral in referrals" :key="referral.id" class="rounded-md border border-line p-3">
                                <p class="font-semibold text-ink">{{ referral.specialty || referral.direction }}</p>
                                <p class="text-sm text-ink-muted">
                                    {{ referral.direction }} | {{ referral.status }} | {{ referral.to_provider_name || referral.from_provider_name || referral.to_branch_id || '-' }}
                                </p>
                                <p class="mt-2 text-sm text-ink-muted">{{ referral.reason }}</p>
                            </div>
                            <p v-if="referrals.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                        </div>
                        <div class="space-y-3 rounded-md border border-line p-4">
                            <p class="font-semibold text-ink">{{ t('clinical.chart.recalls') }}</p>
                            <div v-for="recall in recalls" :key="recall.id" class="rounded-md border border-line p-3">
                                <p class="font-semibold text-ink">{{ recall.rule_name }}</p>
                                <p class="text-sm text-ink-muted">{{ recall.status }} | {{ recall.due_on }}</p>
                            </div>
                            <p v-if="recalls.length === 0" class="text-sm text-ink-muted">{{ t('clinical.chart.empty') }}</p>
                        </div>
                    </section>
                </Tabs>
            </Card>
        </div>
    </AppLayout>
</template>
