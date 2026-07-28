<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// ED disposition + the ED→ADT handoff (ED.G5) — PRESENTATIONAL. The clinician records the DECISION (admit /
// discharge / transfer); ADMIT reuses the existing admission flow to create an inpatient Stay. The system
// computes/suggests NOTHING — nothing auto-decides the disposition (the electric fence).
const { t, locale } = useI18n();

const props = defineProps<{
    visit: { id: string; patient: string; status: string; chief_complaint: string; disposition: string | null; dispositioned_at: string | null };
    can_dispose: boolean;
    dispositions: string[];
    stay: { id: string; ward: string | null; bed: string | null; admitted_at: string; admission_type: string } | null;
    actions: {
        can_admit: boolean;
        can_bill: boolean;
        dispose_url: string;
        record_url: string;
        billing_url: string;
        beds: Array<{ id: string; label: string }>;
        clinicians: Array<{ id: string; name: string }>;
    };
}>();

const choice = ref<'admit' | 'discharge' | 'transfer'>('discharge');
const form = reactive({ note: '', bed_id: props.actions.beds[0]?.id ?? '', clinician_id: props.actions.clinicians[0]?.id ?? '' });

function fmt(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function submit(): void {
    router.post(props.actions.dispose_url, { disposition: choice.value, ...form }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('ed.disposition.title')" />
        <div class="mx-auto max-w-3xl space-y-6 p-6">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('ed.disposition.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ visit.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ visit.chief_complaint }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <span class="inline-block rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-euca-50">{{ t(`ed.status.${visit.status}`) }}</span>
                    <Link :href="actions.record_url" class="text-xs font-semibold text-euca-100 underline">{{ t('ed.disposition.openRecord') }}</Link>
                    <Link v-if="actions.can_bill" :href="actions.billing_url" class="text-xs font-semibold text-euca-100 underline">{{ t('ed.disposition.openBilling') }}</Link>
                </div>
            </div>

            <!-- Already dispositioned — show the decision + (if admitted) the linked inpatient Stay. -->
            <div v-if="visit.disposition" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.disposition.outcome') }}</h2>
                <p class="mt-2 text-sm text-ink">{{ t(`ed.disposition.option.${visit.disposition}`) }} · {{ fmt(visit.dispositioned_at) }}</p>
                <div v-if="stay" class="mt-3 rounded-lg border border-euca-100 p-3 text-sm">
                    <p class="font-semibold text-ink">{{ t('ed.disposition.admittedTo') }}</p>
                    <p class="text-ink-subtle">{{ stay.ward ?? '—' }} · {{ stay.bed ?? '—' }} · {{ t('ed.disposition.emergencyAdmission') }} · {{ fmt(stay.admitted_at) }}</p>
                </div>
            </div>

            <!-- Record the disposition (only when the visit is awaiting disposition). -->
            <div v-if="can_dispose" class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('ed.disposition.record') }}</h2>
                <p class="mt-1 text-xs text-ink-muted">{{ t('ed.disposition.decisionHint') }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button
                        v-for="d in (['discharge', 'transfer', 'admit'] as const)"
                        :key="d"
                        type="button"
                        v-show="d !== 'admit' || actions.can_admit"
                        class="rounded-full px-4 py-1.5 text-sm font-semibold transition"
                        :class="choice === d ? 'bg-euca-600 text-white' : 'bg-surface-2 text-ink'"
                        @click="choice = d"
                    >
                        {{ t(`ed.disposition.option.${d}`) }}
                    </button>
                </div>

                <form class="mt-4 space-y-3" @submit.prevent="submit">
                    <template v-if="choice === 'admit'">
                        <div>
                            <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.disposition.bed') }}</label>
                            <select v-model="form.bed_id" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                                <option v-for="b in actions.beds" :key="b.id" :value="b.id">{{ b.label }}</option>
                            </select>
                            <p v-if="!actions.beds.length" class="mt-1 text-xs text-danger">{{ t('ed.disposition.noBeds') }}</p>
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-wide text-ink-subtle">{{ t('ed.disposition.clinician') }}</label>
                            <select v-model="form.clinician_id" class="mt-1 w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm">
                                <option v-for="c in actions.clinicians" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <p class="text-xs text-ink-muted">{{ t('ed.disposition.admitHint') }}</p>
                    </template>
                    <input v-model="form.note" type="text" maxlength="500" :placeholder="t('ed.disposition.note')" class="w-full rounded-lg border border-euca-200 bg-white px-3 py-2 text-sm" />
                    <button type="submit" class="rounded-full bg-euca-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('ed.disposition.save') }}</button>
                </form>
            </div>
            <p v-else-if="!visit.disposition" class="glass-card p-6 text-sm text-ink-muted">{{ t('ed.disposition.notReady') }}</p>
        </div>
    </AppLayout>
</template>
