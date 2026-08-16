<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ageFromDateOnly, formatDateOnly } from '@/lib/date';

/*
 * APPT.P1 — the staff Appointment Detail page: the drill-in from a day-board tile.
 *
 * A DISPLAY surface. Everything rendered is a recorded fact the server supplied:
 *  - the status pill shows the appointment's TRUE status, and labels ALL EIGHT states the machine
 *    defines (booked · confirmed · arrived · in_progress · completed · cancelled · no_show ·
 *    rescheduled) — not just the four the wireframe drew;
 *  - the duration is the SERVICE's configured length, never a predicted one;
 *  - resources show only real recorded fields (no capability chips — no such data exists);
 *  - the timeline renders real audit + reminder rows, labelling a reminder's channel exactly as
 *    recorded (email today; the page never claims an SMS), with the real actor as provenance.
 * The view computes NO judgment of any kind, and holds no actions — the action row is APPT.P2 and
 * the reschedule modal APPT.P3.
 */

const { t, te } = useI18n();

type Resource = { id: string; name: string; type: string };
type Allergy = { id: string; substance: string; reaction: string | null; severity: string | null };
type TimelineRow = {
    kind: string;
    action: string;
    from_status?: string | null;
    to_status?: string | null;
    reason?: string | null;
    actor_type?: string | null;
    actor?: string | null;
    reminder_type?: string | null;
    channel?: string | null;
    status?: string | null;
    failure_reason?: string | null;
    occurred_at: string | null;
};

const props = defineProps<{
    appointment: {
        id: string;
        status: string;
        source: string;
        starts_at: string;
        ends_at: string;
        duration_minutes: number | null;
        service: string | null;
        branch: string | null;
        notes: string | null;
        status_reason: string | null;
        status_changed_at: string | null;
        status_changed_by: string | null;
        rescheduled_from_id: string | null;
    };
    resources: Resource[];
    patient: {
        id: string;
        name: string;
        mrn: string | null;
        date_of_birth: string | null;
        chart_url: string;
        allergies: Allergy[];
    } | null;
    timeline: TimelineRow[];
    links: { day_board: string };
}>();

// Presentational grouping of the REAL status — never a new state, just how the pill is tinted.
const SETTLED = ['completed'];
const STOPPED = ['cancelled', 'no_show', 'rescheduled'];
const statusTone = computed(() => {
    if (STOPPED.includes(props.appointment.status)) return 'stopped';
    if (SETTLED.includes(props.appointment.status)) return 'settled';
    return 'active';
});

function statusLabel(status: string): string {
    const key = `scheduling.appointmentDetail.status.${status}`;
    return te(key) ? t(key) : status;
}
function sourceLabel(source: string): string {
    const key = `scheduling.appointmentDetail.source.${source}`;
    return te(key) ? t(key) : source;
}
function resourceTypeLabel(type: string): string {
    const key = `scheduling.appointmentDetail.resourceType.${type}`;
    return te(key) ? t(key) : type;
}
function severityLabel(severity: string | null): string | null {
    if (!severity) return null;
    const key = `scheduling.appointmentDetail.severity.${severity}`;
    return te(key) ? t(key) : severity;
}
/** The recorded reminder channel, labelled exactly as stored (no invented channels). */
function channelLabel(channel: string | null | undefined): string {
    if (!channel) return t('scheduling.appointmentDetail.timeline.channelUnknown');
    const key = `scheduling.appointmentDetail.channel.${channel}`;
    return te(key) ? t(key) : channel;
}
function timelineTitle(row: TimelineRow): string {
    if (row.kind === 'reminder') {
        const key = `scheduling.appointmentDetail.timeline.reminder.${row.status ?? 'pending'}`;
        return te(key) ? t(key, { channel: channelLabel(row.channel) }) : t('scheduling.appointmentDetail.timeline.reminder.pending', { channel: channelLabel(row.channel) });
    }
    // vue-i18n resolves '.' as a path separator, so the audit action key ("appointment.booked") is
    // looked up in its underscore form; an unmapped action falls back to the raw key rather than
    // inventing a label.
    const key = `scheduling.appointmentDetail.timeline.action.${row.action.replace(/\./g, '_')}`;
    return te(key) ? t(key) : row.action;
}
/** Provenance line: the REAL actor, or an honest system attribution. */
function timelineProvenance(row: TimelineRow): string {
    if (row.kind === 'reminder') {
        return t('scheduling.appointmentDetail.timeline.automated');
    }
    if (row.actor) {
        return t('scheduling.appointmentDetail.timeline.by', { who: row.actor });
    }
    // A portal action is recorded against the PATIENT, not a staff user — attribute it honestly
    // rather than resolving a patient id against the staff directory.
    if (row.actor_type === 'patient') {
        return t('scheduling.appointmentDetail.timeline.byPatient');
    }
    return t('scheduling.appointmentDetail.timeline.system');
}
function timeOf(value: string | null): string {
    return value ? value.slice(11, 16) : '—';
}
function dayOf(value: string | null): string {
    return value ? formatDateOnly(value.slice(0, 10)) : '—';
}

const patientAge = computed(() => (props.patient ? ageFromDateOnly(props.patient.date_of_birth) : null));
</script>

<template>
    <AppLayout>
        <Head :title="t('scheduling.appointmentDetail.title', { patient: patient?.name ?? '' })" />
        <div class="space-y-5">
            <!-- Hero — the real appointment: true status, real source, the service's own duration. -->
            <div class="euca-tile-dark p-6">
                <div class="flex items-start justify-between gap-3">
                    <Link :href="links.day_board" class="inline-flex items-center gap-1.5 text-xs font-semibold text-euca-200 transition hover:text-euca-50">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="m14 6-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        {{ t('scheduling.appointmentDetail.backToBoard') }}
                    </Link>
                    <Link v-if="patient" :href="patient.chart_url" class="inline-flex items-center gap-1.5 rounded-xl bg-white/15 px-3 py-1.5 text-xs font-semibold text-euca-50 transition hover:bg-white/25">
                        {{ t('scheduling.appointmentDetail.openChart') }}
                    </Link>
                </div>

                <div class="mt-3 flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('scheduling.appointmentDetail.eyebrow') }}</p>
                        <h1 class="mt-0.5 text-2xl font-semibold tracking-tight text-euca-50">{{ appointment.service ?? t('scheduling.appointmentDetail.noService') }}</h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="statusTone === 'active' ? 'bg-euca-50 text-euca-800' : statusTone === 'settled' ? 'bg-white/15 text-euca-50' : 'bg-warning/20 text-warning'"
                            >
                                <span class="h-1.5 w-1.5 rounded-full" :class="statusTone === 'active' ? 'bg-euca-500' : statusTone === 'settled' ? 'bg-euca-200' : 'bg-warning'"></span>
                                {{ statusLabel(appointment.status) }}
                            </span>
                            <span class="rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-semibold text-euca-100">{{ sourceLabel(appointment.source) }}</span>
                            <span v-if="appointment.branch" class="rounded-full bg-white/10 px-2.5 py-0.5 text-xs text-euca-100">{{ appointment.branch }}</span>
                        </div>
                        <p v-if="appointment.status_reason" class="mt-2 text-xs text-euca-200">{{ appointment.status_reason }}</p>
                    </div>
                    <div class="flex-none text-left sm:text-right">
                        <p class="text-xs font-semibold uppercase tracking-wide text-euca-200">{{ t('scheduling.appointmentDetail.when') }}</p>
                        <p class="mt-1 text-2xl font-semibold leading-none tabular-nums text-euca-50">{{ dayOf(appointment.starts_at) }} · {{ timeOf(appointment.starts_at) }}</p>
                        <p class="mt-1.5 text-xs text-euca-200">
                            {{ t('scheduling.appointmentDetail.untilAndDuration', { end: timeOf(appointment.ends_at), minutes: appointment.duration_minutes ?? '—' }) }}
                        </p>
                        <p class="mt-1 font-mono text-[11px] text-euca-200">{{ appointment.id }}</p>
                    </div>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <!-- Resources — only real recorded fields. No capability chips: no such data exists. -->
                <div class="glass-card p-5">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('scheduling.appointmentDetail.resources.title') }}</h2>
                    <ul v-if="resources.length" class="mt-3 space-y-2">
                        <li v-for="resource in resources" :key="resource.id" class="flex items-center justify-between gap-3 rounded-xl bg-surface-2 px-3 py-2">
                            <span class="font-medium text-ink">{{ resource.name }}</span>
                            <span class="rounded-full bg-white/60 px-2 py-0.5 text-xs font-semibold text-ink-muted">{{ resourceTypeLabel(resource.type) }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-ink-muted">{{ t('scheduling.appointmentDetail.resources.empty') }}</p>
                </div>

                <!-- Patient — recorded identity + recorded allergies (displayed, never graded). -->
                <div class="glass-card p-5">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('scheduling.appointmentDetail.patient.title') }}</h2>
                    <template v-if="patient">
                        <p class="mt-3 text-lg font-semibold text-ink">{{ patient.name }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-ink-muted">
                            <span v-if="patient.mrn" class="rounded-md bg-surface-2 px-2 py-0.5 font-mono">{{ patient.mrn }}</span>
                            <span v-if="patient.date_of_birth">{{ formatDateOnly(patient.date_of_birth) }}</span>
                            <span v-if="patientAge !== null">{{ t('scheduling.appointmentDetail.patient.age', { n: patientAge }) }}</span>
                        </div>
                        <div v-if="patient.allergies.length" class="mt-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('scheduling.appointmentDetail.patient.allergies') }}</p>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <span
                                    v-for="allergy in patient.allergies"
                                    :key="allergy.id"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-2.5 py-0.5 text-xs font-semibold text-warning"
                                    :title="allergy.reaction ?? undefined"
                                >
                                    {{ allergy.substance }}
                                    <span v-if="severityLabel(allergy.severity)" class="font-normal opacity-80">· {{ severityLabel(allergy.severity) }}</span>
                                </span>
                            </div>
                        </div>
                        <Link :href="patient.chart_url" class="mt-4 inline-flex text-xs font-semibold text-euca-700 transition hover:text-euca-900">{{ t('scheduling.appointmentDetail.openChart') }}</Link>
                    </template>
                    <p v-else class="mt-3 text-sm text-ink-muted">{{ t('scheduling.appointmentDetail.patient.none') }}</p>
                </div>
            </div>

            <!-- History — real audit + reminder rows, honest channel labels, real provenance. -->
            <div class="glass-card p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('scheduling.appointmentDetail.timeline.title') }}</h2>
                    <span class="text-xs text-ink-subtle">{{ t('scheduling.appointmentDetail.timeline.subtitle') }}</span>
                </div>

                <ol v-if="timeline.length" class="mt-4 space-y-0">
                    <li v-for="(row, i) in timeline" :key="row.kind + '-' + i + '-' + (row.occurred_at ?? '')" class="flex gap-3">
                        <div class="flex flex-col items-center">
                            <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full" :class="row.kind === 'reminder' ? 'bg-euca-300' : 'bg-euca-500'"></span>
                            <span v-if="i < timeline.length - 1" class="w-px flex-1 bg-line"></span>
                        </div>
                        <div class="flex flex-1 flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 pb-4">
                            <div class="min-w-0">
                                <span class="text-sm font-medium text-ink">{{ timelineTitle(row) }}</span>
                                <span v-if="row.from_status && row.to_status" class="ml-2 text-xs text-ink-subtle">
                                    {{ statusLabel(row.from_status) }} → {{ statusLabel(row.to_status) }}
                                </span>
                                <p class="text-xs text-ink-muted">
                                    {{ timelineProvenance(row) }}
                                    <span v-if="row.reason"> · {{ row.reason }}</span>
                                    <span v-if="row.failure_reason"> · {{ row.failure_reason }}</span>
                                </p>
                            </div>
                            <span class="tabular-nums text-xs text-ink-subtle">{{ dayOf(row.occurred_at) }} {{ timeOf(row.occurred_at) }}</span>
                        </div>
                    </li>
                </ol>
                <p v-else class="mt-4 text-sm text-ink-muted">{{ t('scheduling.appointmentDetail.timeline.empty') }}</p>
            </div>
        </div>
    </AppLayout>
</template>
