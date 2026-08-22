<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import PatientClinicalHeader from '@/Components/Clinical/PatientClinicalHeader.vue';
import ClinicalRailCard from '@/Components/Clinical/ClinicalRailCard.vue';
import SignOffBar from '@/Components/Clinical/SignOffBar.vue';

/*
 * Referral Out (PC.P6) — net-new UI over the EXISTING referral backend.
 *
 * PRESENTATIONAL (P0D.GU). Every state change posts to a route that calls `ReferralService`,
 * which owns the state machine and re-checks the permission and the current status. This page
 * writes no status, computes no state, and its buttons are affordances rather than rules (D-168).
 *
 * THE CLINICIAN AUTHORS THE REFERRAL. The reason is their free text and the recipient is what
 * they typed. There is NO urgency, NO priority, NO triage level and NO suggested or ranked
 * specialist anywhere on this screen — and no such thing exists in the backend either. Deciding
 * whom to refer to, and how badly it is needed, is the clinical judgment CareOS does not make.
 *
 * STATUS IS SHOWN AS A WORD, IN ONE CONSTANT STYLE. A declined referral is not painted alarming
 * and a completed one is not painted reassuring: the pill states the recorded lifecycle fact and
 * ranks nothing (D-169). Referrals are ordered by when they were created — a recorded timestamp,
 * never a computed importance.
 */

const { t } = useI18n();

const props = defineProps<{
    patient: {
        id: string;
        mrn: string;
        name: string;
        date_of_birth: string;
        age: number;
        sex: string | null;
        chart_url: string;
    };
    /** RECORDED allergies, shown as facts — identical chip styling, ordered by substance. */
    allergies: Array<{ id: string; substance: string; reaction: string | null; severity: string }>;
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
        created_at: string | null;
        can_send: boolean;
        can_respond: boolean;
        can_complete: boolean;
        urls: { send: string; respond: string; complete: string };
    }>;
    /** Internal branches — a REAL modelled destination. External recipients stay free text. */
    branches: Array<{ id: string; name: string }>;
    actions: { store_url: string; can_write: boolean };
}>();

const form = reactive({
    to_provider_name: '',
    to_branch_id: '',
    specialty: '',
    reason: '',
    notes: '',
});

const respondNotes = ref<Record<string, string>>({});

const headerPatient = computed(() => ({
    name: props.patient.name,
    mrn: props.patient.mrn,
    dateOfBirth: props.patient.date_of_birth,
    age: `${props.patient.age} ${t('patients.index.ageUnit')}`,
    sex: props.patient.sex,
}));

const canSubmit = computed(() => props.actions.can_write && form.reason.trim() !== '');

function branchName(id: string | null): string | null {
    if (!id) return null;
    return props.branches.find((b) => b.id === id)?.name ?? null;
}

function statusLabel(status: string): string {
    const key = `clinical.referrals.statuses.${status}`;
    const label = t(key);
    return label === key ? status : label;
}

function createReferral(): void {
    if (!canSubmit.value) return;
    router.post(props.actions.store_url, { ...form }, {
        preserveScroll: true,
        onSuccess: () => {
            form.to_provider_name = '';
            form.to_branch_id = '';
            form.specialty = '';
            form.reason = '';
            form.notes = '';
        },
    });
}

// Each of these posts to the EXISTING service route; none of them writes a status here.
function sendReferral(url: string): void {
    router.post(url, {}, { preserveScroll: true });
}

function respondReferral(referralId: string, url: string, status: 'accepted' | 'declined'): void {
    router.post(url, { status, notes: respondNotes.value[referralId] ?? '' }, { preserveScroll: true });
}

function completeReferral(url: string): void {
    router.post(url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('clinical.referrals.title')" />
        <div class="space-y-5">
            <nav class="flex flex-wrap items-center gap-1.5 text-sm text-ink-subtle">
                <Link href="/patients" class="transition hover:text-ink">{{ t('app.nav.patients') }}</Link>
                <span>›</span>
                <Link :href="patient.chart_url" class="transition hover:text-ink">{{ patient.name }}</Link>
                <span>›</span>
                <span class="text-ink-muted">{{ t('clinical.referrals.title') }}</span>
            </nav>

            <!-- S1, the shared clinical header, in its compact form (PC.P1/PC.P3). -->
            <PatientClinicalHeader
                :patient="headerPatient"
                :eyebrow="t('clinical.referrals.eyebrow')"
                :allergies="allergies"
                :no-allergies-label="t('patients.show.allergiesNone')"
                :chart-href="patient.chart_url"
                :chart-label="t('clinical.referrals.openChart')"
            />

            <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
                <div class="space-y-4">
                    <!-- Compose. Every field is the clinician's own entry; nothing is prefilled,
                         suggested or ranked. -->
                    <div v-if="actions.can_write" class="glass-card space-y-4 p-5">
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('clinical.referrals.newTitle') }}</h2>
                            <p class="mt-1 text-sm text-ink-muted">{{ t('clinical.referrals.newHint') }}</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="text-sm">
                                <span class="mb-1 block font-medium text-ink">{{ t('clinical.referrals.toProvider') }}</span>
                                <input
                                    v-model="form.to_provider_name"
                                    type="text"
                                    class="block w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                                />
                                <span class="mt-1 block text-xs text-ink-subtle">{{ t('clinical.referrals.toProviderHint') }}</span>
                            </label>

                            <label class="text-sm">
                                <span class="mb-1 block font-medium text-ink">{{ t('clinical.referrals.specialty') }}</span>
                                <input
                                    v-model="form.specialty"
                                    type="text"
                                    class="block w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                                />
                            </label>
                        </div>

                        <label class="block text-sm">
                            <span class="mb-1 block font-medium text-ink">{{ t('clinical.referrals.toBranch') }}</span>
                            <select
                                v-model="form.to_branch_id"
                                :aria-label="t('clinical.referrals.toBranch')"
                                class="block w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink"
                            >
                                <option value="">{{ t('clinical.referrals.toBranchNone') }}</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                            </select>
                        </label>

                        <label class="block text-sm">
                            <span class="mb-1 block font-medium text-ink">{{ t('clinical.referrals.reason') }}</span>
                            <textarea
                                v-model="form.reason"
                                rows="5"
                                class="block w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                            ></textarea>
                            <span class="mt-1 block text-xs text-ink-subtle">{{ t('clinical.referrals.reasonHint') }}</span>
                        </label>

                        <label class="block text-sm">
                            <span class="mb-1 block font-medium text-ink">{{ t('clinical.referrals.notes') }}</span>
                            <textarea
                                v-model="form.notes"
                                rows="2"
                                class="block w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                            ></textarea>
                        </label>

                        <div class="flex justify-end">
                            <button
                                type="button"
                                :disabled="!canSubmit"
                                class="btn-glow inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50"
                                @click="createReferral"
                            >{{ t('clinical.referrals.create') }}</button>
                        </div>
                    </div>

                    <!-- The patient's real referrals, newest first. -->
                    <div v-if="referrals.length" class="space-y-4">
                        <article v-for="referral in referrals" :key="referral.id" class="glass-card space-y-3 p-5">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-ink">
                                        {{ referral.specialty || t('clinical.referrals.noSpecialty') }}
                                    </p>
                                    <p class="mt-0.5 text-sm text-ink-muted">
                                        {{ referral.to_provider_name || branchName(referral.to_branch_id) || t('clinical.referrals.noRecipient') }}
                                    </p>
                                </div>
                                <!-- One constant style for every status: the word carries the state. -->
                                <span class="rounded-full bg-surface-2 px-2.5 py-1 text-xs font-semibold text-ink-muted">
                                    {{ statusLabel(referral.status) }}
                                </span>
                            </div>

                            <p class="whitespace-pre-line text-sm text-ink">{{ referral.reason }}</p>

                            <dl class="grid gap-x-4 gap-y-1 text-xs text-ink-subtle sm:grid-cols-2">
                                <div v-if="referral.created_at" class="flex gap-2">
                                    <dt>{{ t('clinical.referrals.created') }}</dt><dd>{{ referral.created_at }}</dd>
                                </div>
                                <div v-if="referral.sent_at" class="flex gap-2">
                                    <dt>{{ t('clinical.referrals.sentAt') }}</dt><dd>{{ referral.sent_at }}</dd>
                                </div>
                                <div v-if="referral.responded_at" class="flex gap-2">
                                    <dt>{{ t('clinical.referrals.respondedAt') }}</dt><dd>{{ referral.responded_at }}</dd>
                                </div>
                            </dl>

                            <p v-if="referral.notes" class="rounded-xl bg-surface-2 px-3 py-2 text-sm text-ink-muted">{{ referral.notes }}</p>

                            <!-- The transitions the SERVICE allows. Each posts to its existing route. -->
                            <template v-if="actions.can_write">
                                <SignOffBar
                                    v-if="referral.can_send"
                                    :label="t('clinical.referrals.sendLabel')"
                                    :readiness="t('clinical.referrals.sendReadiness')"
                                    :note="t('clinical.referrals.sendNote')"
                                >
                                    <button
                                        type="button"
                                        class="rounded-xl bg-euca-400 px-5 py-2.5 text-sm font-semibold text-euca-900 transition hover:bg-euca-300"
                                        @click="sendReferral(referral.urls.send)"
                                    >{{ t('clinical.referrals.send') }}</button>
                                </SignOffBar>

                                <div v-if="referral.can_respond" class="space-y-2 border-t border-line pt-3">
                                    <label class="block text-sm">
                                        <span class="mb-1 block font-medium text-ink">{{ t('clinical.referrals.responseNotes') }}</span>
                                        <textarea
                                            v-model="respondNotes[referral.id]"
                                            rows="2"
                                            class="block w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink"
                                        ></textarea>
                                    </label>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="rounded-xl border border-line bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                                            @click="respondReferral(referral.id, referral.urls.respond, 'accepted')"
                                        >{{ t('clinical.referrals.markAccepted') }}</button>
                                        <button
                                            type="button"
                                            class="rounded-xl border border-line bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                                            @click="respondReferral(referral.id, referral.urls.respond, 'declined')"
                                        >{{ t('clinical.referrals.markDeclined') }}</button>
                                    </div>
                                </div>

                                <div v-if="referral.can_complete" class="border-t border-line pt-3">
                                    <button
                                        type="button"
                                        class="rounded-xl border border-line bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                                        @click="completeReferral(referral.urls.complete)"
                                    >{{ t('clinical.referrals.markCompleted') }}</button>
                                </div>
                            </template>
                        </article>
                    </div>
                    <div v-else class="glass-card p-6 text-sm text-ink-muted">{{ t('clinical.referrals.empty') }}</div>
                </div>

                <!-- The rail states what this screen does NOT do, in words, on the page. -->
                <div class="space-y-4">
                    <ClinicalRailCard :title="t('clinical.referrals.scopeTitle')">
                        <div class="space-y-2 text-xs text-ink-subtle">
                            <p>{{ t('clinical.referrals.scopeTransmit') }}</p>
                            <p>{{ t('clinical.referrals.scopeUrgency') }}</p>
                            <p>{{ t('clinical.referrals.scopePacket') }}</p>
                            <p>{{ t('clinical.referrals.scopeDirectory') }}</p>
                            <p>{{ t('clinical.referrals.scopeAuthor') }}</p>
                        </div>
                    </ClinicalRailCard>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
