<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
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
    versions: Array<{ id: string; version: number; status: string; author_name: string; created_at: string | null; signed_at: string | null; amendment_reason: string | null; edit_url: string }>;
    actions: { save_url: string; sign_url: string; amend_url: string; chart_url: string; can_write: boolean; can_sign: boolean };
    snippets?: Array<{ trigger: string; title: string; scope: string; body: string }>;
    // Optional chart-sourced allergies — not part of the note-editor payload today; the
    // mini-banner surfaces only when the prop lands (same pattern as the chart/landing).
    allergies?: Array<{ id: string; substance: string; reaction: string | null; severity: string }>;
}>();

type SoapField = 'subjective' | 'objective' | 'assessment' | 'plan';
const sections = reactive({
    subjective: props.note.subjective,
    objective: props.note.objective,
    assessment: props.note.assessment,
    plan: props.note.plan,
});
const amendReason = ref('');
const autosaveState = ref('');
let autosaveTimer: number | undefined;

const snippetTarget = ref<SoapField>('subjective');
const snippetTrigger = ref('');

const signModalOpen = ref(false);
const signConfirmText = ref('');
const signKeyword = computed(() => t('clinical.note.signKeyword'));
const signReady = computed(() => signConfirmText.value.trim().toUpperCase() === signKeyword.value.toUpperCase());

const requiredCount = computed(() => {
    const req = props.template?.required_sections ?? [];
    const missing = req.filter((s) => ((sections[s as SoapField] ?? '') as string).toString().trim() === '');
    return { filled: req.length - missing.length, total: req.length, missing: missing.map((s) => t(`clinical.note.sections.${s}`)) };
});

function insertSnippet(): void {
    if (props.note.is_read_only || !props.actions.can_write) return;
    const match = (props.snippets ?? []).find((s) => s.trigger === snippetTrigger.value);
    if (!match) return;
    const current = sections[snippetTarget.value] ?? '';
    sections[snippetTarget.value] = current ? `${current}\n${match.body}` : match.body;
    snippetTrigger.value = '';
}

function saveDraft(): void {
    if (props.note.is_read_only || !props.actions.can_write) return;
    router.patch(props.actions.save_url, sections, {
        preserveScroll: true,
        onStart: () => (autosaveState.value = t('clinical.note.saving')),
        onSuccess: () => (autosaveState.value = t('clinical.note.saved')),
    });
}

function confirmSign(): void {
    if (!signReady.value) return;
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
        <div class="space-y-5">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('clinical.note.eyebrow') }}</p>
                    <h1 class="mt-1 flex flex-wrap items-center gap-2 text-2xl font-semibold tracking-tight text-ink">
                        {{ t('clinical.note.headingFor', { name: patient.name }) }}
                        <span class="rounded-full bg-euca-100 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ t('clinical.note.versionLabel', { version: note.version }) }}</span>
                    </h1>
                </div>
                <p v-if="autosaveState && !note.is_read_only" class="inline-flex items-center gap-1.5 rounded-full bg-euca-50 px-3 py-1 text-sm font-medium text-euca-800">
                    <span class="h-1.5 w-1.5 rounded-full bg-euca-600"></span>{{ autosaveState }}
                </p>
            </div>

            <div class="grid gap-5 lg:grid-cols-[300px_1fr]">
                <!-- Left rail -->
                <div class="space-y-4">
                    <div class="glass-card p-5">
                        <p class="font-semibold text-ink">{{ patient.name }}</p>
                        <p class="mt-1 font-mono text-sm text-ink-muted">{{ patient.mrn }}</p>
                        <Link :href="patient.chart_url" class="mt-3 inline-flex text-sm font-semibold text-euca-700 transition hover:text-euca-800">← {{ t('clinical.note.chart') }}</Link>
                    </div>

                    <!-- Allergies mini-banner — dormant until an allergies prop lands. -->
                    <div v-if="allergies && allergies.length" class="flex items-start gap-2 rounded-xl border-2 border-warning/50 bg-warning-soft px-4 py-3">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 4 21 19H3L12 4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                            <path d="M12 10v4M12 16.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                        <p class="text-sm">
                            <span class="font-bold text-ink">{{ t('clinical.chart.allergies.title') }}</span><br />
                            <span class="text-ink">{{ allergies.map((a) => (a.reaction ? `${a.substance} — ${a.reaction}` : a.substance)).join(' · ') }}</span>
                        </p>
                    </div>

                    <div class="glass-card p-5">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('clinical.note.encounter') }}</p>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('clinical.note.encType') }}</dt><dd class="font-medium text-ink">{{ encounter.type }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('clinical.note.encStarted') }}</dt><dd class="text-ink">{{ encounter.started_at }}</dd></div>
                            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">{{ t('clinical.note.encStatus') }}</dt><dd><span class="rounded-full bg-euca-50 px-2 py-0.5 text-xs font-semibold text-euca-800">{{ encounter.status }}</span></dd></div>
                            <div v-if="template" class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('clinical.note.template') }}</dt><dd class="text-ink">{{ template.name }}</dd></div>
                        </dl>
                    </div>

                    <div class="glass-card p-5">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('clinical.note.history') }}</p>
                        <VersionHistory :versions="versions" />
                    </div>
                </div>

                <!-- Main -->
                <div class="space-y-5">
                    <template v-if="note.is_read_only">
                        <!-- Signed: quiet lock line, plain-text SOAP, no edit/delete affordances. -->
                        <div class="flex items-center gap-2 rounded-xl border border-line bg-surface-2 px-4 py-3 text-sm font-medium text-ink">
                            <svg class="h-4 w-4 text-euca-700" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                                <path d="M8 10V8a4 4 0 0 1 8 0v2" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                            {{ t('clinical.note.signedLock', { name: note.author_name, date: note.signed_at || '—' }) }}
                        </div>
                        <div class="glass-card p-6">
                            <SoapEditor :model-value="sections" readonly :required-sections="template?.required_sections ?? []" />
                        </div>
                        <div class="glass-card p-6">
                            <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('clinical.note.amendTitle') }}</h2>
                            <p class="mt-1 text-sm text-ink-muted">{{ t('clinical.note.amendHint') }}</p>
                            <label class="mt-4 block text-sm font-medium text-ink">
                                {{ t('clinical.note.amendReason') }}
                                <textarea v-model="amendReason" rows="2" class="mt-1.5 block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30"></textarea>
                            </label>
                            <button v-if="actions.can_write" type="button" :disabled="!amendReason.trim()" class="btn-glow mt-3 inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50" @click="amendNote">{{ t('clinical.note.amend') }}</button>
                        </div>
                    </template>

                    <template v-else>
                        <div v-if="actions.can_write && (snippets?.length ?? 0) > 0" class="glass-card flex flex-wrap items-end gap-2 p-4">
                            <label class="text-sm">
                                <span class="mb-1 block text-xs font-medium text-ink-muted">{{ t('clinical.snippets.insertInto') }}</span>
                                <select v-model="snippetTarget" :aria-label="t('clinical.snippets.insertInto')" class="rounded-xl border border-line bg-surface-2 px-3 py-2 text-sm text-ink">
                                    <option value="subjective">{{ t('clinical.note.sections.subjective') }}</option>
                                    <option value="objective">{{ t('clinical.note.sections.objective') }}</option>
                                    <option value="assessment">{{ t('clinical.note.sections.assessment') }}</option>
                                    <option value="plan">{{ t('clinical.note.sections.plan') }}</option>
                                </select>
                            </label>
                            <label class="text-sm">
                                <span class="mb-1 block text-xs font-medium text-ink-muted">{{ t('clinical.snippets.dotPhrase') }}</span>
                                <select v-model="snippetTrigger" :aria-label="t('clinical.snippets.dotPhrase')" class="rounded-xl border border-line bg-surface-2 px-3 py-2 text-sm text-ink">
                                    <option value="">—</option>
                                    <option v-for="s in snippets" :key="s.trigger" :value="s.trigger">.{{ s.trigger }} — {{ s.title }} ({{ s.scope }})</option>
                                </select>
                            </label>
                            <button type="button" class="rounded-xl border border-line bg-surface/70 px-3.5 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2 disabled:opacity-50" :disabled="!snippetTrigger" @click="insertSnippet">{{ t('clinical.snippets.insert') }}</button>
                        </div>

                        <div class="glass-card p-6">
                            <!-- Mutate the reactive object in place (never reassign the const via v-model) so
                                 every SOAP section is preserved and the deep autosave watch keeps firing. -->
                            <SoapEditor
                                :model-value="sections"
                                :required-sections="template?.required_sections ?? []"
                                @update:model-value="Object.assign(sections, $event)"
                            />
                        </div>

                        <!-- The dark moment: sign bar. No red anywhere near a clinical action. -->
                        <div class="euca-tile-dark flex flex-col items-center justify-between gap-3 p-4 sm:flex-row">
                            <p class="text-sm text-euca-100">
                                <template v-if="requiredCount.total > 0">
                                    <span class="font-semibold text-euca-50">{{ t('clinical.note.requiredCount', { filled: requiredCount.filled, total: requiredCount.total }) }}</span>
                                    <template v-if="requiredCount.missing.length"> — {{ t('clinical.note.requiredMissing', { sections: requiredCount.missing.join(', ') }) }}</template>
                                </template>
                                <template v-else>{{ t('clinical.note.draftLabel') }}</template>
                            </p>
                            <div class="flex flex-wrap items-center gap-3">
                                <button v-if="actions.can_write" type="button" class="rounded-xl bg-white/15 px-4 py-2.5 text-sm font-semibold text-euca-50 transition hover:bg-white/25" @click="saveDraft">{{ t('clinical.note.save') }}</button>
                                <button v-if="actions.can_sign" type="button" class="rounded-xl bg-euca-400 px-5 py-2.5 text-sm font-semibold text-euca-900 transition hover:bg-euca-300" @click="signModalOpen = true; signConfirmText = ''">{{ t('clinical.note.sign') }}</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Sign confirmation: permanent-lock, type-to-confirm. Server re-checks sign + required sections. -->
        <div v-if="signModalOpen" class="fixed inset-0 z-40 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-euca-900/30 backdrop-blur-sm" @click="signModalOpen = false"></div>
            <div class="glass-card relative w-full max-w-md p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('clinical.note.signModalTitle') }}</h2>
                <p class="mt-2 text-sm text-ink-muted">{{ t('clinical.note.signConfirm') }} {{ t('clinical.note.signModalBody') }}</p>
                <dl class="mt-4 space-y-2 rounded-xl border border-line bg-surface-2 p-4 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('clinical.note.patient') }}</dt><dd class="font-medium text-ink">{{ patient.name }} · {{ patient.mrn }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-muted">{{ t('clinical.note.required') }}</dt><dd class="font-medium text-ink">{{ t('clinical.note.requiredCount', { filled: requiredCount.filled, total: requiredCount.total }) }}</dd></div>
                </dl>
                <label class="mt-4 block text-sm font-medium text-ink">
                    {{ t('clinical.note.signTypeToConfirm', { keyword: signKeyword }) }}
                    <input v-model="signConfirmText" type="text" autocomplete="off" class="mt-1.5 block w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm uppercase tracking-widest text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                </label>
                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <button type="button" class="rounded-xl border border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink transition hover:bg-surface-2" @click="signModalOpen = false">{{ t('clinical.note.cancel') }}</button>
                    <button type="button" :disabled="!signReady" class="btn-glow inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50" @click="confirmSign">{{ t('clinical.note.signPermanently') }}</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
