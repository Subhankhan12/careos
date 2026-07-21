<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import DentalSectionNav from '@/Components/DentalSectionNav.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();
const page = usePage();

interface DiagnosisRow {
    id: string;
    label: string;
    status: string;
    tooth: string | null;
    surface: string | null;
    findings: string | null;
    reason: string | null;
    is_free_text: boolean;
    diagnosed_by: number;
    diagnosed_at: string;
}

const props = defineProps<{
    patient: { id: string; mrn: string; name: string };
    diagnoses: DiagnosisRow[];
    terms: Array<{ id: string; label: string }>;
    statuses: string[];
    teeth: { permanent: string[]; primary: string[] };
    surfaces: string[];
    actions: { can_record: boolean; store_url: string; term_url: string };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// The dentist authors the diagnosis. Picking a term from THEIR OWN list just fills the label —
// it is a convenience, not a suggestion. Free text is equally valid.
const form = reactive({
    diagnosis_term_id: '',
    label: '',
    status: props.statuses[0] ?? 'provisional',
    tooth: '',
    surface: '',
    findings: '',
    reason: '',
});

function pickTerm(): void {
    const term = props.terms.find((x) => x.id === form.diagnosis_term_id);
    if (term) form.label = term.label;
}

function recordDiagnosis(): void {
    router.post(props.actions.store_url, { ...form }, {
        preserveScroll: true,
        onSuccess: () => {
            form.diagnosis_term_id = '';
            form.label = '';
            form.status = props.statuses[0] ?? 'provisional';
            form.tooth = '';
            form.surface = '';
            form.findings = '';
            form.reason = '';
        },
    });
}

const termForm = useForm({ label: '' });
function addTerm(): void {
    termForm.post(props.actions.term_url, { preserveScroll: true, onSuccess: () => termForm.reset() });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('diagnosis.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('diagnosis.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('diagnosis.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ patient.name }} · <span class="font-mono">{{ patient.mrn }}</span></p>
                <p class="mt-1 max-w-2xl text-sm text-ink-subtle">{{ t('diagnosis.subtitle') }}</p>
            </div>

            <DentalSectionNav :patient-id="patient.id" active="diagnoses" />

            <!-- The fence, stated plainly to the clinician. -->
            <p class="rounded-2xl border border-line bg-surface px-4 py-3 text-xs text-ink-subtle">{{ t('diagnosis.fenceNote') }}</p>

            <p v-if="flash" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">{{ t(`diagnosis.flash.${flash}`) }}</p>

            <!-- Record a diagnosis (dentist-authored). -->
            <Card v-if="actions.can_record" :title="t('diagnosis.record.title')" :subtitle="t('diagnosis.record.subtitle')">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('diagnosis.record.fromList') }}</span>
                        <select v-model="form.diagnosis_term_id" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink" @change="pickTerm">
                            <option value="">{{ t('diagnosis.record.freeTextOption') }}</option>
                            <option v-for="term in terms" :key="term.id" :value="term.id">{{ term.label }}</option>
                        </select>
                    </label>
                    <div class="sm:col-span-2">
                        <Input id="dx-label" v-model="form.label" :label="t('diagnosis.record.label')" />
                    </div>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('diagnosis.record.status') }}</span>
                        <select v-model="form.status" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink">
                            <option v-for="s in statuses" :key="s" :value="s">{{ t(`diagnosis.status.${s}`) }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('diagnosis.record.tooth') }}</span>
                        <select v-model="form.tooth" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink">
                            <option value="">{{ t('diagnosis.record.noTooth') }}</option>
                            <optgroup :label="t('diagnosis.dentition.permanent')">
                                <option v-for="tn in teeth.permanent" :key="tn" :value="tn">{{ tn }}</option>
                            </optgroup>
                            <optgroup :label="t('diagnosis.dentition.primary')">
                                <option v-for="tn in teeth.primary" :key="tn" :value="tn">{{ tn }}</option>
                            </optgroup>
                        </select>
                    </label>
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('diagnosis.record.findings') }}</span>
                        <textarea v-model="form.findings" rows="2" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink"></textarea>
                    </label>
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium text-ink-muted">{{ t('diagnosis.record.reason') }}</span>
                        <input v-model="form.reason" type="text" class="w-full rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink" />
                    </label>
                </div>
                <Button class="mt-3" :block="false" :disabled="!form.label" @click="recordDiagnosis">{{ t('diagnosis.record.submit') }}</Button>
            </Card>

            <!-- The tenant's own diagnosis pick-list — plain list, dentist-authored. -->
            <Card v-if="actions.can_record" :title="t('diagnosis.terms.title')" :subtitle="t('diagnosis.terms.subtitle')">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="addTerm">
                    <div class="flex-1"><Input id="dx-term" v-model="termForm.label" :label="t('diagnosis.terms.add')" /></div>
                    <Button type="submit" :block="false" :disabled="termForm.processing || !termForm.label">{{ t('diagnosis.terms.addButton') }}</Button>
                </form>
                <div v-if="terms.length" class="mt-3 flex flex-wrap gap-2">
                    <span v-for="term in terms" :key="term.id" class="inline-flex items-center rounded-full bg-euca-50 px-3 py-1 text-xs font-medium text-euca-700">{{ term.label }}</span>
                </div>
                <p v-else class="mt-3 text-xs text-ink-subtle">{{ t('diagnosis.terms.empty') }}</p>
            </Card>

            <!-- Diagnosis history: what the dentist recorded, newest first. -->
            <Card :title="t('diagnosis.history.title')">
                <p v-if="!diagnoses.length" class="text-sm text-ink-muted">{{ t('diagnosis.history.empty') }}</p>
                <ul v-else class="space-y-3">
                    <li v-for="d in diagnoses" :key="d.id" class="rounded-2xl border border-line p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-ink">{{ d.label }}</span>
                            <span class="inline-flex items-center rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-700">{{ t(`diagnosis.status.${d.status}`) }}</span>
                        </div>
                        <p class="mt-1 text-xs text-ink-subtle">
                            <span v-if="d.tooth">{{ t('diagnosis.record.tooth') }} {{ d.tooth }}<span v-if="d.surface"> · {{ d.surface }}</span> · </span>
                            {{ new Date(d.diagnosed_at).toLocaleDateString() }}
                            <span v-if="d.is_free_text"> · {{ t('diagnosis.history.freeText') }}</span>
                        </p>
                        <p v-if="d.findings" class="mt-2 text-sm text-ink-muted">{{ d.findings }}</p>
                        <p v-if="d.reason" class="mt-1 text-xs text-ink-subtle">{{ t('diagnosis.history.reason') }}: {{ d.reason }}</p>
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
