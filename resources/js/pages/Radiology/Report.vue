<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// The radiologist report (RAD.G4) — PRESENTATIONAL. THE FENCE GATE. The radiologist AUTHORS the report
// (findings + impression as prose) via the reused sign-and-lock ClinicalNote; signing files it (study →
// reported, Order → resulted) and routes it to the ordering clinician (the shared order → review flow). The
// system computes NO image finding/CAD/abnormality — every word is the radiologist's (the electric fence).
const { t, locale } = useI18n();

type ReportVersion = {
    id: string;
    version: number;
    status: string;
    findings: string | null;
    impression: string | null;
    amendment_reason: string | null;
    signed_at: string | null;
};

const props = defineProps<{
    order: { id: string; patient: string; exam: string | null; code: string | null; modality: string | null; body_part: string | null; priority: string; order_status: string | null; study_url: string };
    study: { id: string; accession_number: string; status: string } | null;
    versions: ReportVersion[];
    actions: { can_write: boolean; can_sign: boolean; save_url: string; sign_url: string; amend_url: string; review_worklist_url: string };
}>();

// The current (head) report version, and whether it is signed (locked → amend, not edit).
const head = computed<ReportVersion | null>(() => props.versions.length ? props.versions[props.versions.length - 1] : null);
const isSigned = computed<boolean>(() => head.value?.status === 'signed');

const form = reactive({
    findings: head.value && head.value.status === 'draft' ? (head.value.findings ?? '') : '',
    impression: head.value && head.value.status === 'draft' ? (head.value.impression ?? '') : '',
});

function fmt(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function saveDraft(): void {
    router.post(props.actions.save_url, { findings: form.findings, impression: form.impression }, { preserveScroll: true });
}
function sign(): void {
    router.post(props.actions.sign_url, {}, { preserveScroll: true });
}
function amend(): void {
    const reason = window.prompt(t('radiology.report.amendReason')) ?? '';
    if (!reason.trim()) return;
    router.post(props.actions.amend_url, { findings: form.findings, impression: form.impression, reason }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('radiology.report.title')" />
        <div class="mx-auto max-w-4xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('radiology.report.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ order.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ order.code }} — {{ order.exam }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-semibold text-euca-50">
                    <span v-if="study" class="rounded-full bg-white/15 px-3 py-1 font-mono">{{ study.accession_number }}</span>
                    <span v-if="study" class="rounded-full bg-white/15 px-3 py-1">{{ study.status }}</span>
                    <Link :href="order.study_url" class="rounded-full bg-white/15 px-3 py-1 hover:bg-white/25">{{ order.modality ?? '—' }}<template v-if="order.body_part"> · {{ order.body_part }}</template> →</Link>
                </div>
            </div>

            <p v-if="!study" class="glass-card p-6 text-sm text-ink-muted">{{ t('radiology.report.noStudy') }}</p>

            <!-- Author / amend the report — the radiologist's prose. Signed reports are read-only (amend → new version). -->
            <div v-if="study && actions.can_write" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.report.author') }}</h2>
                <form class="mt-4 space-y-3" @submit.prevent="saveDraft">
                    <div>
                        <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('radiology.report.findings') }}</label>
                        <textarea v-model="form.findings" rows="4" maxlength="5000" :placeholder="t('radiology.report.findingsPlaceholder')" class="mt-1 w-full rounded-lg border border-euca-200 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('radiology.report.impression') }}</label>
                        <textarea v-model="form.impression" rows="3" maxlength="5000" :placeholder="t('radiology.report.impressionPlaceholder')" class="mt-1 w-full rounded-lg border border-euca-200 px-3 py-2 text-sm"></textarea>
                    </div>
                    <p class="text-xs text-ink-muted">{{ t('radiology.report.fenceHint') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="!isSigned" type="submit" class="rounded-full border border-euca-300 px-5 py-2 text-sm font-semibold text-euca-700 transition hover:bg-euca-50">{{ t('radiology.report.saveDraft') }}</button>
                        <button v-if="!isSigned && actions.can_sign" type="button" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700" @click="sign">{{ t('radiology.report.sign') }}</button>
                        <button v-if="isSigned" type="button" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700" @click="amend">{{ t('radiology.report.amend') }}</button>
                    </div>
                    <p class="text-xs text-ink-muted">{{ t('radiology.report.routedNote') }} <Link :href="actions.review_worklist_url" class="font-semibold text-euca-700 underline">{{ t('radiology.report.openReview') }}</Link></p>
                </form>
            </div>

            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('radiology.report.history') }}</h2>
                <p v-if="!versions.length" class="mt-3 text-sm text-ink-muted">{{ t('radiology.report.empty') }}</p>
                <ol v-else class="mt-4 space-y-4">
                    <li v-for="v in versions" :key="v.id" class="rounded-lg border border-euca-100 p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-ink">{{ t('radiology.report.version') }} {{ v.version }}</span>
                            <span class="rounded-full bg-ink/5 px-2.5 py-1 text-xs font-semibold text-ink">{{ v.status === 'signed' ? t('radiology.report.signed') : t('radiology.report.draft') }}<template v-if="v.signed_at"> · {{ fmt(v.signed_at) }}</template></span>
                        </div>
                        <p class="mt-2 text-sm text-ink"><span class="text-ink-subtle">{{ t('radiology.report.findings') }}:</span> {{ v.findings ?? '—' }}</p>
                        <p class="mt-1 text-sm text-ink"><span class="text-ink-subtle">{{ t('radiology.report.impression') }}:</span> {{ v.impression ?? '—' }}</p>
                        <p v-if="v.amendment_reason" class="mt-1 text-xs text-ink-subtle">{{ v.amendment_reason }}</p>
                    </li>
                </ol>
            </div>
        </div>
    </AppLayout>
</template>
