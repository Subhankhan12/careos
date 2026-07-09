<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import SoapEditor from '@/Components/SoapEditor.vue';
import VersionHistory from '@/Components/VersionHistory.vue';

const { t } = useI18n();

const props = defineProps<{
    note: {
        id: string;
        encounter_id: string;
        patient_id: string;
        author_name: string;
        subjective: string | null;
        objective: string | null;
        assessment: string | null;
        plan: string | null;
        status: string;
        signed_at: string | null;
        version: number;
        amendment_reason: string | null;
        is_read_only: boolean;
    };
    encounter: { id: string; status: string; type: string; started_at: string };
    patient: { id: string; mrn: string; name: string; chart_url: string };
    template: { id: string; name: string; required_sections: string[] } | null;
    versions: Array<{
        id: string;
        version: number;
        status: string;
        author_name: string;
        created_at: string | null;
        signed_at: string | null;
        amendment_reason: string | null;
        edit_url: string;
    }>;
    actions: {
        save_url: string;
        sign_url: string;
        amend_url: string;
        chart_url: string;
        can_write: boolean;
        can_sign: boolean;
    };
}>();

const sections = reactive({
    subjective: props.note.subjective,
    objective: props.note.objective,
    assessment: props.note.assessment,
    plan: props.note.plan,
});
const amendReason = ref('');
const autosaveState = ref('');
let autosaveTimer: number | undefined;

function saveDraft(): void {
    if (props.note.is_read_only || !props.actions.can_write) {
        return;
    }

    router.patch(props.actions.save_url, sections, {
        preserveScroll: true,
        onStart: () => {
            autosaveState.value = t('clinical.note.saving');
        },
        onSuccess: () => {
            autosaveState.value = t('clinical.note.saved');
        },
    });
}

function signNote(): void {
    if (!window.confirm(t('clinical.note.signConfirm'))) {
        return;
    }

    router.post(props.actions.sign_url);
}

function amendNote(): void {
    router.post(props.actions.amend_url, { reason: amendReason.value });
}

watch(
    sections,
    () => {
        window.clearTimeout(autosaveTimer);
        if (!props.note.is_read_only && props.actions.can_write) {
            autosaveTimer = window.setTimeout(saveDraft, 900);
        }
    },
    { deep: true },
);
</script>

<template>
    <AppLayout>
        <Head :title="t('clinical.note.title')" />
        <div class="space-y-6">
            <Card>
                <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                    <div>
                        <p class="text-sm font-semibold uppercase text-brand-700">{{ patient.mrn }}</p>
                        <h1 class="mt-1 text-2xl font-semibold text-ink">{{ patient.name }}</h1>
                        <p class="mt-2 text-sm text-ink-muted">
                            {{ encounter.type }} | {{ encounter.status }} | {{ encounter.started_at }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="patient.chart_url" class="inline-flex items-center rounded-md border border-line px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted">
                            {{ t('clinical.note.chart') }}
                        </Link>
                        <span class="inline-flex items-center rounded-md border border-line px-3 py-1 text-sm font-semibold text-ink-muted">
                            {{ t('clinical.note.versionLabel', { version: note.version }) }}
                        </span>
                    </div>
                </div>
            </Card>

            <Card :title="t('clinical.note.title')" :subtitle="template?.name || undefined">
                <div class="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <p class="text-sm font-semibold text-ink">{{ note.status }}</p>
                        <p class="text-xs text-ink-muted">{{ note.author_name }} | {{ note.signed_at || '-' }}</p>
                    </div>
                    <p class="text-sm text-ink-muted">{{ autosaveState }}</p>
                </div>

                <SoapEditor
                    v-model="sections"
                    :readonly="note.is_read_only"
                    :required-sections="template?.required_sections ?? []"
                />

                <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <div v-if="!note.is_read_only && actions.can_write" class="sm:w-36">
                        <Button variant="secondary" @click="saveDraft">{{ t('clinical.note.save') }}</Button>
                    </div>
                    <div v-if="!note.is_read_only && actions.can_sign" class="sm:w-36">
                        <Button @click="signNote">{{ t('clinical.note.sign') }}</Button>
                    </div>
                </div>
            </Card>

            <Card v-if="note.is_read_only" :title="t('clinical.note.amendTitle')">
                <div class="grid gap-3 md:grid-cols-[1fr_160px]">
                    <Input id="amend-reason" v-model="amendReason" :label="t('clinical.note.amendReason')" />
                    <div v-if="actions.can_write" class="flex items-end">
                        <Button @click="amendNote">{{ t('clinical.note.amend') }}</Button>
                    </div>
                </div>
            </Card>

            <Card :title="t('clinical.note.history')">
                <VersionHistory :versions="versions" />
            </Card>
        </div>
    </AppLayout>
</template>
