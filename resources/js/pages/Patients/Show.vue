<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import DataList from '@/Components/DataList.vue';
import Input from '@/Components/Input.vue';
import Tabs from '@/Components/Tabs.vue';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

// The dental cross-link shows only for a dental-capable user (dental.chart) — the same
// gate as the top-nav Dental entry (DENTAL.G9). Non-dental staff never see a dead link.
const canDental = computed(
    () => (page.props.auth as { user?: { permissions?: Record<string, boolean> } } | undefined)?.user?.permissions?.['dental.chart'] === true,
);

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
    // Optional chart-sourced allergies — not part of the Patients/Show payload today;
    // the banner renders only when the prop lands (same pattern as the landing KPIs).
    allergies?: Array<{ id: string; substance: string; reaction: string | null; severity: string; status: string }>;
    accessLog: Array<{ actor_type: string; actor_id: string | null; occurred_at: string; resource_type: string }>;
    actions: { can_edit: boolean; grant_consent_url: string };
}>();

const activeTab = ref('demographics');
const consentTemplateKey = ref('portal');
const consentSignature = ref('');
const withdrawReason = ref('');

const initials = computed(() =>
    `${props.patient.first_name?.[0] ?? ''}${props.patient.last_name?.[0] ?? ''}`.toUpperCase(),
);

const tabs = computed(() => [
    { key: 'demographics', label: t('patients.show.tabs.demographics') },
    { key: 'contacts', label: t('patients.show.tabs.contacts'), count: props.patient.contacts.length },
    { key: 'coverages', label: t('patients.show.tabs.coverages'), count: props.patient.coverages.length },
    { key: 'consents', label: t('patients.show.tabs.consents'), count: props.patient.consents.length },
    { key: 'access', label: t('patients.show.tabs.access'), count: props.accessLog.length },
]);

const demographics = computed(() => [
    { label: t('patients.fields.mrn'), value: props.patient.mrn },
    { label: t('patients.fields.dateOfBirth'), value: `${props.patient.date_of_birth} (${props.patient.age})` },
    { label: t('patients.fields.sex'), value: props.patient.sex },
    { label: t('patients.fields.gender'), value: props.patient.gender },
    { label: t('patients.fields.language'), value: props.patient.preferred_language },
    { label: t('patients.fields.status'), value: props.patient.status },
]);

function timePart(value: string): string {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }).format(d);
    } catch {
        return value;
    }
}

const groupedAccess = computed(() => {
    const groups: Record<string, typeof props.accessLog> = {};
    const order: string[] = [];
    for (const entry of props.accessLog) {
        const d = new Date(entry.occurred_at);
        let key = entry.occurred_at;
        if (!Number.isNaN(d.getTime())) {
            try {
                key = new Intl.DateTimeFormat(locale.value, { weekday: 'long', day: 'numeric', month: 'long' }).format(d);
            } catch {
                key = entry.occurred_at;
            }
        }
        if (!(key in groups)) {
            groups[key] = [];
            order.push(key);
        }
        groups[key].push(entry);
    }
    return order.map((k) => ({ date: k, entries: groups[k] }));
});

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
        <div class="space-y-5">
            <nav class="flex items-center gap-1.5 text-sm text-ink-subtle">
                <Link href="/patients" class="transition hover:text-ink">{{ t('app.nav.patients') }}</Link>
                <span>›</span>
                <span class="text-ink-muted">{{ patient.first_name }} {{ patient.last_name }}</span>
            </nav>

            <!-- The screen's one deep-eucalyptus accent tile: the patient band. -->
            <div class="euca-tile-dark relative overflow-hidden p-6 sm:p-7">
                <svg
                    class="pointer-events-none absolute -right-8 -top-8 h-40 w-40 text-euca-50 opacity-10"
                    viewBox="0 0 200 200"
                    fill="none"
                    aria-hidden="true"
                >
                    <path d="M40 170C40 100 70 55 160 45c-4 78-44 118-120 125z" fill="currentColor" />
                </svg>
                <div class="relative flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
                    <div class="flex items-start gap-4">
                        <span
                            class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-white/15 text-lg font-semibold text-euca-50"
                        >
                            {{ initials }}
                        </span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-3xl font-semibold tracking-tight text-euca-50">
                                    {{ patient.first_name }} {{ patient.last_name }}
                                </h1>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full bg-euca-300/25 px-2.5 py-1 text-xs font-semibold text-euca-50"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full bg-euca-200"></span>
                                    {{ patient.status }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1 rounded-full border border-white/25 px-2.5 py-1 text-xs font-medium text-euca-100"
                                >
                                    ⚑ {{ t('patients.show.headerFlag') }}
                                </span>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                                <span class="rounded-md bg-white/10 px-2.5 py-1 font-mono text-euca-100">{{ patient.mrn }}</span>
                                <span class="rounded-md bg-white/10 px-2.5 py-1 text-euca-100">
                                    {{ patient.date_of_birth }} · {{ patient.age }} {{ t('patients.index.ageUnit') }}
                                </span>
                                <span v-if="patient.sex" class="rounded-md bg-white/10 px-2.5 py-1 text-euca-100">{{ patient.sex }}</span>
                                <span v-if="patient.preferred_language" class="rounded-md bg-white/10 px-2.5 py-1 text-euca-100">
                                    {{ patient.preferred_language }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <Link
                        v-if="canDental"
                        :href="`/dental/chart/${patient.id}`"
                        class="shrink-0 self-start rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25"
                    >
                        {{ t('patients.show.viewInDental') }} →
                    </Link>
                </div>
            </div>

            <!-- Allergy banner, pinned under the header — dormant until an allergies prop lands. -->
            <div
                v-if="allergies && allergies.length"
                class="flex flex-col gap-2 rounded-xl border border-warning/40 bg-warning-soft px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="flex items-start gap-2 text-sm text-ink">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 4 21 19H3L12 4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        <path d="M12 10v4M12 16.5v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <span>
                        <span class="font-semibold">{{ t('patients.show.allergiesLabel') }}:</span>
                        {{ allergies.map((a) => (a.reaction ? `${a.substance} — ${a.reaction}` : a.substance)).join(' · ') }}
                    </span>
                </p>
                <Link
                    :href="`/clinical/chart/${patient.id}`"
                    class="shrink-0 text-sm font-medium text-euca-700 transition hover:text-euca-800"
                >
                    {{ t('patients.show.viewInChart') }} →
                </Link>
            </div>

            <div class="glass-card p-6">
                <Tabs v-model:active="activeTab" :tabs="tabs">
                    <section v-if="activeTab === 'demographics'">
                        <DataList :items="demographics" />
                    </section>

                    <section v-if="activeTab === 'contacts'" class="grid gap-4 md:grid-cols-2">
                        <div
                            v-for="contact in patient.contacts"
                            :key="String(contact.id)"
                            class="rounded-xl border border-line bg-surface-2 p-4"
                        >
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ contact.type }}</p>
                            <p class="mt-1.5 text-sm font-medium text-ink">{{ contact.value || contact.line1 || '—' }}</p>
                            <p class="text-sm text-ink-muted">{{ contact.city }} {{ contact.postal }} {{ contact.country }}</p>
                        </div>
                        <p v-if="patient.contacts.length === 0" class="text-sm text-ink-muted">{{ t('patients.show.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'coverages'" class="grid gap-4 md:grid-cols-2">
                        <div
                            v-for="coverage in patient.coverages"
                            :key="String(coverage.id)"
                            class="rounded-xl border border-line bg-surface-2 p-4 text-sm"
                        >
                            <p class="font-semibold text-ink">{{ coverage.payer_name }}</p>
                            <p class="mt-1 text-ink-muted">{{ coverage.member_id }} · {{ coverage.coverage_type }} · {{ coverage.plan || '—' }}</p>
                        </div>
                        <p v-if="patient.coverages.length === 0" class="text-sm text-ink-muted">{{ t('patients.show.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'consents'" class="space-y-4">
                        <div
                            v-if="actions.can_edit"
                            class="rounded-xl border border-line bg-surface-2 p-4"
                        >
                            <p class="mb-3 text-sm font-semibold text-ink">{{ t('patients.show.grantTitle') }}</p>
                            <div class="grid gap-3 md:grid-cols-[1fr_1fr_auto] md:items-end">
                                <Input id="consent_template_key" v-model="consentTemplateKey" :label="t('patients.show.consentTemplate')" />
                                <Input id="consent_signature" v-model="consentSignature" :label="t('patients.show.consentSignature')" />
                                <Button :block="false" @click="grantConsent">{{ t('patients.show.grantConsent') }}</Button>
                            </div>
                            <p class="mt-2 text-xs text-ink-subtle">{{ t('patients.show.grantHint') }}</p>
                        </div>

                        <div
                            v-for="consent in patient.consents"
                            :key="consent.id"
                            class="rounded-xl border border-line p-4"
                            :class="consent.status === 'granted' ? 'border-l-4 border-l-euca-600' : ''"
                        >
                            <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold text-ink">{{ consent.template_title }}</p>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="
                                                consent.status === 'granted'
                                                    ? 'bg-euca-100 text-euca-800'
                                                    : 'bg-surface-2 text-ink-muted'
                                            "
                                        >
                                            {{ consent.status }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm text-ink-muted">{{ consent.template_key }} · v{{ consent.template_version }}</p>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <span
                                            v-for="scope in consent.scope_keys"
                                            :key="scope"
                                            class="rounded-full bg-euca-50 px-2 py-0.5 text-xs text-euca-800"
                                        >
                                            {{ scope }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="actions.can_edit && consent.status === 'granted'" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div class="flex-1">
                                    <Input id="withdraw_reason" v-model="withdrawReason" :label="t('patients.show.withdrawReason')" />
                                </div>
                                <button
                                    type="button"
                                    class="rounded-xl px-4 py-2.5 text-sm font-semibold text-danger transition hover:bg-danger-soft"
                                    @click="withdrawConsent(consent.withdraw_url)"
                                >
                                    {{ t('patients.show.withdraw') }}
                                </button>
                            </div>
                        </div>
                        <p v-if="patient.consents.length === 0" class="text-sm text-ink-muted">{{ t('patients.show.empty') }}</p>
                    </section>

                    <section v-if="activeTab === 'access'" class="space-y-6">
                        <p class="text-sm text-ink-muted">{{ t('patients.show.accessHint') }}</p>
                        <div v-for="group in groupedAccess" :key="group.date" class="space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ group.date }}</p>
                            <div
                                v-for="entry in group.entries"
                                :key="`${entry.actor_type}-${entry.actor_id}-${entry.occurred_at}`"
                                class="flex items-center gap-3 rounded-xl border border-line/70 bg-surface-2 px-4 py-3"
                            >
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-euca-100 text-xs font-semibold uppercase text-euca-800">
                                    {{ (entry.actor_type?.[0] ?? '·') }}
                                </span>
                                <span class="min-w-0 flex-1 text-sm">
                                    <span class="font-medium text-ink">{{ entry.actor_type }}</span>
                                    <span class="text-ink-muted"> {{ entry.actor_id || '—' }}</span>
                                </span>
                                <span class="rounded-md bg-euca-50 px-2 py-0.5 font-mono text-xs text-euca-800">{{ entry.resource_type }}</span>
                                <span class="shrink-0 text-xs text-ink-subtle">{{ timePart(entry.occurred_at) }}</span>
                            </div>
                        </div>
                        <p v-if="accessLog.length === 0" class="text-sm text-ink-muted">{{ t('patients.show.empty') }}</p>
                    </section>
                </Tabs>
            </div>
        </div>
    </AppLayout>
</template>
