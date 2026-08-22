<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import AccessLogRow from '@/Components/Clinical/AccessLogRow.vue';

/*
 * The dedicated patient access log (PC.P5).
 *
 * A TRANSPARENCY SURFACE, and it is presentational only: every row is an append-only audit row the
 * server handed over, already ordered newest-first and already filtered. This page groups rows by
 * day for reading and formats times — it derives no fact about any access.
 *
 * IT PASSES NO JUDGMENT ON A READ. There is no "suspicious" marker, no anomaly or risk score, no
 * frequency analysis, and — deliberately — NO STYLING KEYED TO ACTOR TYPE (D-169): an operator read
 * and a receptionist read render with exactly the same classes, and the actor type is stated in
 * WORDS. Marking one kind of reader as visually alarming would be the system telling a reviewer
 * what to think about a fact it merely recorded.
 *
 * WHAT IT DOES NOT SHOW, IT SAYS OUT LOUD. Operator-mode activity is recorded against the CLINIC,
 * not against a patient, so it cannot appear in a patient-scoped log — and on a transparency screen
 * a silent omission is the same failure as an incomplete log. The limitation is printed on the page.
 */

const { t } = useI18n();

const props = defineProps<{
    patient: { id: string; mrn: string; name: string; show_url: string };
    /** Append-only audit rows, newest first, exactly as the server ordered them. */
    rows: Array<{
        occurred_at: string;
        actor_type: string;
        actor_id: string | null;
        actor_name: string;
        resource_type: string | null;
        resource_id: string | null;
        surface: string | null;
        is_agent: boolean;
    }>;
    filters: { days: string; actor_types: string[] };
    /** Built from the actor types actually present — never a hardcoded taxonomy. */
    actorTypeCounts: Array<{ actor_type: string; total: number }>;
    totals: { shown: number; distinct_actors: number };
    actions: { export_url: string };
}>();

const ranges = ['7', '30', '90', 'all'] as const;

/** Group for reading only — the server decided the order; this never re-sorts. */
const grouped = computed(() => {
    const groups: Array<{ day: string; rows: typeof props.rows }> = [];
    for (const row of props.rows) {
        const day = dayLabel(row.occurred_at);
        const last = groups[groups.length - 1];
        if (last && last.day === day) last.rows.push(row);
        else groups.push({ day, rows: [row] });
    }
    return groups;
});

function dayLabel(value: string): string {
    const d = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(d);
    } catch {
        return value;
    }
}

function timeLabel(value: string): string {
    const d = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(d);
    } catch {
        return value;
    }
}

/** The recorded actor type, in words. Same styling for every value — it ranks nothing. */
function actorTypeLabel(actorType: string): string {
    const key = `patients.accessLog.actorTypes.${actorType}`;
    const label = t(key);
    return label === key ? actorType : label;
}

function applyFilters(days: string, actorTypes: string[]): void {
    router.get(
        `/patients/${props.patient.id}/access-log`,
        { days, actor_types: actorTypes.join(',') },
        { preserveScroll: true, preserveState: true },
    );
}

function setRange(days: string): void {
    applyFilters(days, props.filters.actor_types);
}

function toggleActorType(actorType: string): void {
    const next = props.filters.actor_types.includes(actorType)
        ? props.filters.actor_types.filter((a) => a !== actorType)
        : [...props.filters.actor_types, actorType];
    applyFilters(props.filters.days, next);
}

const exportHref = computed(() => {
    const params = new URLSearchParams({ days: props.filters.days });
    if (props.filters.actor_types.length) params.set('actor_types', props.filters.actor_types.join(','));
    return `${props.actions.export_url}?${params.toString()}`;
});
</script>

<template>
    <AppLayout>
        <Head :title="t('patients.accessLog.title')" />
        <div class="space-y-5">
            <nav class="flex flex-wrap items-center gap-1.5 text-sm text-ink-subtle">
                <Link href="/patients" class="transition hover:text-ink">{{ t('app.nav.patients') }}</Link>
                <span>›</span>
                <Link :href="patient.show_url" class="transition hover:text-ink">{{ patient.name }}</Link>
                <span>›</span>
                <span class="text-ink-muted">{{ t('patients.accessLog.title') }}</span>
            </nav>

            <div class="euca-tile-dark rounded-2xl p-5 text-white">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/70">{{ t('patients.accessLog.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ patient.name }}</h1>
                <p class="mt-1 font-mono text-sm text-white/70">{{ patient.mrn }}</p>
                <p class="mt-3 text-sm text-white/80">
                    {{ t('patients.accessLog.summary', { accesses: totals.shown, actors: totals.distinct_actors }) }}
                </p>
            </div>

            <div class="glass-card space-y-4 p-5">
                <!-- Filters over REAL recorded attributes only: a calendar window, and the actor
                     types that actually occur in this log. No "notable" or "suspicious" filter —
                     the system does not decide which reads deserve attention. -->
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('patients.accessLog.range') }}</span>
                    <button
                        v-for="range in ranges"
                        :key="range"
                        type="button"
                        class="rounded-full border px-3 py-1 text-sm font-medium transition"
                        :class="filters.days === range ? 'border-euca-600 bg-euca-50 text-euca-800' : 'border-line bg-surface text-ink-muted hover:bg-surface-2'"
                        @click="setRange(range)"
                    >
                        {{ range === 'all' ? t('patients.accessLog.rangeAll') : t('patients.accessLog.rangeDays', { days: range }) }}
                    </button>
                </div>

                <div v-if="actorTypeCounts.length" class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('patients.accessLog.actorType') }}</span>
                    <!-- Every chip carries the SAME classes whichever actor type it names; only
                         selection changes the outline. An operator read is not painted as alarming. -->
                    <button
                        v-for="entry in actorTypeCounts"
                        :key="entry.actor_type"
                        type="button"
                        class="rounded-full border px-3 py-1 text-sm font-medium transition"
                        :class="filters.actor_types.includes(entry.actor_type) ? 'border-euca-600 bg-euca-50 text-euca-800' : 'border-line bg-surface text-ink-muted hover:bg-surface-2'"
                        @click="toggleActorType(entry.actor_type)"
                    >
                        {{ actorTypeLabel(entry.actor_type) }} · {{ entry.total }}
                    </button>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                    <p class="text-sm text-ink-muted">{{ t('patients.accessLog.exportHint') }}</p>
                    <!-- A plain link: the export is a GET the server gates and audits like any read. -->
                    <a
                        :href="exportHref"
                        class="btn-glow inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold"
                    >{{ t('patients.accessLog.export') }}</a>
                </div>
            </div>

            <div v-if="grouped.length" class="space-y-4">
                <div v-for="group in grouped" :key="group.day" class="glass-card p-5">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                        {{ group.day }} · {{ t('patients.accessLog.dayCount', { count: group.rows.length }) }}
                    </p>
                    <ul class="space-y-2">
                        <AccessLogRow
                            v-for="(row, index) in group.rows"
                            :key="`${row.occurred_at}-${index}`"
                            :actor="row.actor_name"
                            :action="t('patients.accessLog.readAction', { resource: row.resource_type ?? '—' })"
                            :at="timeLabel(row.occurred_at)"
                            :surface="row.surface"
                            :basis="actorTypeLabel(row.actor_type)"
                            :is-agent="row.is_agent"
                        />
                    </ul>
                </div>
            </div>
            <div v-else class="glass-card p-6 text-sm text-ink-muted">{{ t('patients.accessLog.empty') }}</div>

            <!-- STATED, NOT HIDDEN. What this log covers, and the one thing it cannot. -->
            <div class="glass-card space-y-2 p-5 text-xs text-ink-subtle">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('patients.accessLog.scopeTitle') }}</p>
                <p>{{ t('patients.accessLog.scopeAll') }}</p>
                <p>{{ t('patients.accessLog.scopeSelf') }}</p>
                <p>{{ t('patients.accessLog.scopeOperator') }}</p>
                <p>{{ t('patients.accessLog.scopeImmutable') }}</p>
            </div>
        </div>
    </AppLayout>
</template>
