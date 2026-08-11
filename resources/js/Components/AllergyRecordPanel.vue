<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

type Allergy = {
    id: string;
    substance: string;
    reaction: string | null;
    source: string | null;
    severity: string;
    status: string;
    recorded_at: string;
    verified_at: string | null;
};

const props = defineProps<{
    allergies: Allergy[];
    // The display-only MedicationSafetyProvider seam state. Automated drug-allergy checking is a
    // certified-partner medical-device function CareOS never computes; today the seam is the
    // null-object (no partner), so there is nothing advisory to show.
    medicationSafety: { providerConfigured: boolean; advisories: Array<{ code: string; message: string; source: string }> };
}>();

// Only active allergies get a record card. This is a DISPLAY of recorded facts — it grades nothing.
const active = computed(() => props.allergies.filter((a) => a.status === 'active'));

function severityLabel(severity: string): string {
    return t(`allergyAlert.severity.${severity}`, severity);
}
function formatDate(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString();
}
</script>

<template>
    <section v-if="active.length > 0" class="space-y-4">
        <div>
            <h3 class="text-sm font-semibold uppercase tracking-[0.12em] text-euca-700">{{ t('allergyAlert.title') }}</h3>
            <p class="mt-0.5 text-xs text-ink-muted">{{ t('allergyAlert.subtitle') }}</p>
        </div>

        <!-- One record card per active allergy — every value is a CLINICIAN-RECORDED FACT (displayed,
             not computed). Severity is the recorded severity, not a grade this page derived. -->
        <article
            v-for="allergy in active"
            :key="allergy.id"
            class="rounded-2xl border-2 border-warning/50 bg-warning-soft px-5 py-4"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-warning/20 text-warning">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 4 21 19H3L12 4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                            <path d="M12 10v4M12 16.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-base font-bold text-ink">{{ allergy.substance }}</p>
                        <p v-if="allergy.reaction" class="mt-0.5 text-sm text-ink">{{ allergy.reaction }}</p>
                    </div>
                </div>
                <!-- The recorded severity — a documented fact, shown as-is. -->
                <span class="inline-flex flex-none items-center gap-1.5 rounded-full bg-warning/20 px-3 py-1 text-xs font-semibold text-warning">
                    {{ severityLabel(allergy.severity) }}
                </span>
            </div>

            <dl class="mt-3 grid gap-x-6 gap-y-1.5 border-t border-warning/30 pt-3 text-sm sm:grid-cols-2">
                <div v-if="allergy.source" class="sm:col-span-2">
                    <dt class="text-xs text-ink-subtle">{{ t('allergyAlert.fields.source') }}</dt>
                    <dd class="text-ink">{{ allergy.source }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-subtle">{{ t('allergyAlert.fields.recorded') }}</dt>
                    <dd class="text-ink">{{ formatDate(allergy.recorded_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-subtle">{{ t('allergyAlert.fields.verification') }}</dt>
                    <dd class="text-ink">
                        <span v-if="allergy.verified_at" class="inline-flex items-center gap-1 font-medium text-success">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12l5 5L19 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            {{ t('allergyAlert.confirmed', { date: formatDate(allergy.verified_at) }) }}
                        </span>
                        <span v-else class="text-ink-muted">{{ t('allergyAlert.unconfirmed') }}</span>
                    </dd>
                </div>
            </dl>
        </article>

        <!-- THE DISPLAY-ONLY SEAM SHELL — the MedicationSafetyProvider region. It renders whatever a
             certified partner returns; with the null-object today, nothing. It computes NO conflict,
             blocks NOTHING, suggests NO alternative — there is no control here to do so. -->
        <div class="rounded-2xl border border-line bg-surface-2 px-5 py-4">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 flex-none text-ink-muted" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" /></svg>
                <h4 class="text-sm font-semibold text-ink">{{ t('allergyAlert.seam.title') }}</h4>
            </div>

            <!-- With a certified partner: render its advisory findings (human-owned, non-blocking). -->
            <ul v-if="medicationSafety.providerConfigured && medicationSafety.advisories.length > 0" class="mt-3 space-y-2">
                <li v-for="a in medicationSafety.advisories" :key="a.code" class="rounded-xl border border-line bg-surface px-3 py-2 text-sm">
                    <p class="text-ink">{{ a.message }}</p>
                    <p class="mt-0.5 text-xs text-ink-subtle">{{ t('allergyAlert.seam.provenance', { source: a.source }) }}</p>
                </li>
            </ul>
            <!-- A partner is connected but produced nothing to show here (checks run at prescribing time). -->
            <p v-else-if="medicationSafety.providerConfigured" class="mt-2 text-sm text-ink-muted">{{ t('allergyAlert.seam.configuredNoFindings') }}</p>
            <!-- The honest state today: no certified partner is configured, so nothing is checked. -->
            <p v-else class="mt-2 text-sm text-ink-muted">{{ t('allergyAlert.seam.notConfigured') }}</p>
            <p class="mt-2 text-xs text-ink-subtle">{{ t('allergyAlert.seam.footnote') }}</p>
        </div>
    </section>
</template>
