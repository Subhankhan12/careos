<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';

const { t } = useI18n();

interface TemplateWindow {
    id: string;
    weekday: number | null;
    startTime: string;
    endTime: string;
}

interface Exception {
    id: string;
    date: string | null;
    isAvailable: boolean;
    startTime: string | null;
    endTime: string | null;
    fullDay: boolean;
    // Displayed exactly as written — never interpreted, categorised or tinted.
    reason: string | null;
}

interface ResourceRow {
    id: string;
    name: string;
    type: string;
    template: TemplateWindow[];
    exceptions: Exception[];
    // Straight from AvailabilityService::windowsFor — the slot finder's own reader. The page
    // performs NO availability arithmetic of its own.
    effective: Array<{ date: string; startTime: string; endTime: string }>;
}

const props = defineProps<{
    resources: ResourceRow[];
    filters: { branch_id: string; type: string | null; week: string };
    branches: Array<{ id: string; name: string }>;
    resourceTypes: string[];
    week: { start: string; end: string };
    counts: { resources: number; withoutTemplate: number; exceptions: number };
    branchOnlineBookings: boolean;
    actions: { storeUrl: string; updateUrl: string; deleteUrl: string; impactUrl: string };
}>();

// The refused affordances, iterated — removing a key removes the rendered line (GOV.P3).
const omittedKeys = ['suggested', 'forecast', 'autoTemplate', 'ranking', 'guardedWithdrawal'] as const;

function setFilter(key: 'type' | 'branch_id' | 'week', value: string | null): void {
    const next: Record<string, string> = {
        branch_id: key === 'branch_id' ? (value ?? '') : props.filters.branch_id,
        week: key === 'week' ? (value ?? '') : props.filters.week,
    };
    const type = key === 'type' ? value : props.filters.type;
    if (type) next.type = type;
    router.get('/scheduling/availability', next, { preserveState: false, replace: true });
}

function weekdayName(weekday: number | null): string {
    if (weekday === null) return '—';
    const names = t('availability.weekdays') as unknown as string[];
    return Array.isArray(names) ? (names[weekday] ?? String(weekday)) : String(weekday);
}

/* ------------------------------------------------------------------------------- the editor */

const blank = () => ({
    id: null as string | null,
    resource_id: '',
    kind: 'template' as 'template' | 'exception',
    weekday: 1 as number | null,
    date: '' as string,
    start_time: '08:00',
    end_time: '17:00',
    is_available: true,
    full_day: false,
    reason: '',
});

const form = reactive(blank());
const open = ref(false);
const errors = ref<Record<string, string>>({});

/**
 * How many booked appointments a withdrawal would sit over.
 *
 * This is a WARNING, not a veto. The server does not block the edit — availability is checked when
 * an appointment is booked and never re-checked afterwards — so the page tells the truth before
 * saving rather than implying a protection that is not there.
 */
const impact = ref<{ state: 'idle' | 'checking' | 'done'; appointments: number | null }>({ state: 'idle', appointments: null });

function startTemplate(resourceId: string): void {
    Object.assign(form, blank(), { resource_id: resourceId, kind: 'template' });
    errors.value = {};
    impact.value = { state: 'idle', appointments: null };
    open.value = true;
}

function startException(resourceId: string): void {
    Object.assign(form, blank(), { resource_id: resourceId, kind: 'exception', is_available: false, date: props.week.start });
    errors.value = {};
    impact.value = { state: 'idle', appointments: null };
    open.value = true;
}

function editRow(resourceId: string, row: TemplateWindow | Exception, kind: 'template' | 'exception'): void {
    Object.assign(form, blank(), {
        id: row.id,
        resource_id: resourceId,
        kind,
        weekday: kind === 'template' ? (row as TemplateWindow).weekday : null,
        date: kind === 'exception' ? ((row as Exception).date ?? '') : '',
        start_time: row.startTime ?? '08:00',
        end_time: row.endTime ?? '17:00',
        is_available: kind === 'template' ? true : (row as Exception).isAvailable,
        full_day: kind === 'exception' ? (row as Exception).fullDay : false,
        reason: kind === 'exception' ? ((row as Exception).reason ?? '') : '',
    });
    errors.value = {};
    impact.value = { state: 'idle', appointments: null };
    open.value = true;
}

async function checkImpact(): Promise<void> {
    if (form.kind !== 'exception' || form.is_available) {
        impact.value = { state: 'idle', appointments: null };
        return;
    }
    impact.value = { state: 'checking', appointments: null };
    const response = await fetch(props.actions.impactUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
        },
        body: JSON.stringify({
            resource_id: form.resource_id,
            branch_id: props.filters.branch_id,
            date: form.date || null,
            start_time: form.full_day ? null : form.start_time,
            end_time: form.full_day ? null : form.end_time,
        }),
    });
    const json = await response.json();
    impact.value = { state: 'done', appointments: json.appointments };
}

function payload() {
    const isException = form.kind === 'exception';
    const timed = !(isException && form.full_day);
    return {
        resource_id: form.resource_id,
        branch_id: props.filters.branch_id,
        weekday: isException ? null : form.weekday,
        date: isException ? form.date : null,
        start_time: timed ? form.start_time : null,
        end_time: timed ? form.end_time : null,
        is_available: isException ? form.is_available : true,
        reason: isException ? form.reason || null : null,
    };
}

/** Every write posts to the server; nothing about availability is decided in this page. */
function submit(): void {
    const url = form.id ? props.actions.updateUrl.replace('__ID__', form.id) : props.actions.storeUrl;
    router.post(url, payload(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onError: (e) => {
            errors.value = e as Record<string, string>;
        },
    });
}

function remove(): void {
    if (!form.id) return;
    router.post(props.actions.deleteUrl.replace('__ID__', form.id), { branch_id: props.filters.branch_id }, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('availability.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('availability.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('availability.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('availability.subtitle') }}</p>
            </div>

            <!-- Plain counts of rows that exist (D-166/D-174). -->
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard :label="t('availability.counts.resources')" :value="String(counts.resources)" :hint="t('availability.counts.resourcesHint')" />
                <StatCard :label="t('availability.counts.withoutTemplate')" :value="String(counts.withoutTemplate)" :hint="t('availability.counts.withoutTemplateHint')" />
                <StatCard :label="t('availability.counts.exceptions')" :value="String(counts.exceptions)" :hint="t('availability.counts.exceptionsHint')" />
            </div>

            <!-- How templates and exceptions combine, plus the BRANCH.P1 distinction stated plainly. -->
            <div class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">{{ t('availability.engine.title') }}</p>
                <p class="mt-2 text-sm text-ink-muted">{{ t('availability.engine.body') }}</p>
                <p class="mt-2 text-sm text-ink-muted">
                    {{ branchOnlineBookings ? t('availability.engine.onlineOpen') : t('availability.engine.onlineSuspended') }}
                </p>
            </div>

            <!-- Filters over REAL attributes only. -->
            <div class="flex flex-wrap items-center gap-3">
                <select class="rounded-xl border border-line bg-surface-2 px-3 py-2 text-sm text-ink" :value="filters.branch_id" @change="setFilter('branch_id', ($event.target as HTMLSelectElement).value)">
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
                <select class="rounded-xl border border-line bg-surface-2 px-3 py-2 text-sm text-ink" :value="filters.type ?? ''" @change="setFilter('type', ($event.target as HTMLSelectElement).value || null)">
                    <option value="">{{ t('availability.filters.allTypes') }}</option>
                    <option v-for="rt in resourceTypes" :key="rt" :value="rt">{{ rt }}</option>
                </select>
                <label class="flex items-center gap-2 text-sm text-ink-muted">
                    {{ t('availability.filters.week') }}
                    <input type="date" class="rounded-xl border border-line bg-surface-2 px-3 py-2 text-sm text-ink" :value="filters.week" @change="setFilter('week', ($event.target as HTMLInputElement).value)" />
                </label>
            </div>

            <div v-if="resources.length" class="space-y-4">
                <div v-for="resource in resources" :key="resource.id" class="glass-card p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="font-semibold text-ink">{{ resource.name }}</p>
                            <p class="text-xs text-ink-subtle">{{ resource.type }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="rounded-xl border border-line bg-surface/70 px-3 py-1.5 text-xs font-semibold text-ink transition hover:bg-surface-2" @click="startTemplate(resource.id)">
                                {{ t('availability.template.add') }}
                            </button>
                            <button type="button" class="rounded-xl border border-line bg-surface/70 px-3 py-1.5 text-xs font-semibold text-ink transition hover:bg-surface-2" @click="startException(resource.id)">
                                {{ t('availability.exceptions.add') }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-5 lg:grid-cols-3">
                        <!-- Weekly template -->
                        <div>
                            <p class="text-xs font-medium text-ink-muted">{{ t('availability.template.title') }}</p>
                            <ul v-if="resource.template.length" class="mt-1.5 space-y-1 text-sm">
                                <li v-for="w in resource.template" :key="w.id">
                                    <button type="button" class="text-left text-ink transition hover:text-euca-700" @click="editRow(resource.id, w, 'template')">
                                        {{ weekdayName(w.weekday) }} · {{ w.startTime }}–{{ w.endTime }}
                                    </button>
                                </li>
                            </ul>
                            <!-- An empty template is shown honestly, never hidden. -->
                            <p v-else class="mt-1.5 text-sm text-ink-subtle">{{ t('availability.template.none') }}</p>
                        </div>

                        <!-- Dated exceptions, with their recorded reasons -->
                        <div>
                            <p class="text-xs font-medium text-ink-muted">{{ t('availability.exceptions.title') }}</p>
                            <ul v-if="resource.exceptions.length" class="mt-1.5 space-y-2 text-sm">
                                <li v-for="e in resource.exceptions" :key="e.id">
                                    <button type="button" class="text-left transition hover:text-euca-700" @click="editRow(resource.id, e, 'exception')">
                                        <span class="text-ink">
                                            {{ e.date }} ·
                                            {{ e.isAvailable ? t('availability.exceptions.available') : t('availability.exceptions.blocked') }} ·
                                            {{ e.fullDay ? t('availability.exceptions.fullDay') : `${e.startTime}–${e.endTime}` }}
                                        </span>
                                        <!-- The author's own words, printed as recorded. -->
                                        <span class="block text-xs text-ink-subtle">
                                            {{ e.reason ? e.reason : t('availability.exceptions.noReason') }}
                                        </span>
                                    </button>
                                </li>
                            </ul>
                            <p v-else class="mt-1.5 text-sm text-ink-subtle">{{ t('availability.exceptions.none') }}</p>
                        </div>

                        <!-- Effective windows — the slot finder's own answer. -->
                        <div>
                            <p class="text-xs font-medium text-ink-muted">{{ t('availability.effective.title') }}</p>
                            <ul v-if="resource.effective.length" class="mt-1.5 space-y-1 text-sm text-ink">
                                <li v-for="(w, i) in resource.effective" :key="`${resource.id}-${i}`">{{ w.date }} · {{ w.startTime }}–{{ w.endTime }}</li>
                            </ul>
                            <p v-else class="mt-1.5 text-sm text-ink-subtle">{{ t('availability.effective.none') }}</p>
                            <p class="mt-2 text-xs text-ink-subtle">{{ t('availability.effective.note') }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="glass-card p-8 text-center">
                <p class="text-sm font-medium text-ink">{{ t('availability.empty') }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ t('availability.emptyHint') }}</p>
            </div>

            <!-- Editor -->
            <div v-if="open" class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">
                    {{ form.kind === 'template' ? t('availability.editor.newWindow') : t('availability.editor.newException') }}
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label v-if="form.kind === 'template'" class="block">
                        <span class="text-sm font-medium text-ink">{{ t('availability.editor.weekday') }}</span>
                        <select v-model.number="form.weekday" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink">
                            <option v-for="d in [0, 1, 2, 3, 4, 5, 6]" :key="d" :value="d">{{ weekdayName(d) }}</option>
                        </select>
                    </label>
                    <label v-else class="block">
                        <span class="text-sm font-medium text-ink">{{ t('availability.editor.date') }}</span>
                        <input v-model="form.date" type="date" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" @change="checkImpact" />
                    </label>

                    <label v-if="form.kind === 'exception'" class="block">
                        <span class="text-sm font-medium text-ink">{{ t('availability.editor.kind') }}</span>
                        <select v-model="form.is_available" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" @change="checkImpact">
                            <option :value="false">{{ t('availability.editor.kindBlocked') }}</option>
                            <option :value="true">{{ t('availability.editor.kindAvailable') }}</option>
                        </select>
                    </label>

                    <label v-if="!(form.kind === 'exception' && form.full_day)" class="block">
                        <span class="text-sm font-medium text-ink">{{ t('availability.editor.from') }}</span>
                        <input v-model="form.start_time" type="time" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" @change="checkImpact" />
                    </label>
                    <label v-if="!(form.kind === 'exception' && form.full_day)" class="block">
                        <span class="text-sm font-medium text-ink">{{ t('availability.editor.to') }}</span>
                        <input v-model="form.end_time" type="time" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" @change="checkImpact" />
                    </label>
                </div>

                <label v-if="form.kind === 'exception' && !form.is_available" class="mt-3 flex items-center gap-2">
                    <input v-model="form.full_day" type="checkbox" @change="checkImpact" />
                    <span class="text-sm text-ink">{{ t('availability.editor.fullDay') }}</span>
                </label>

                <label v-if="form.kind === 'exception'" class="mt-3 block">
                    <span class="text-sm font-medium text-ink">{{ t('availability.editor.reason') }}</span>
                    <input v-model="form.reason" type="text" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" />
                    <span class="mt-1 block text-xs text-ink-subtle">{{ t('availability.editor.reasonHint') }}</span>
                </label>

                <!-- The consequence, stated before saving. A warning, never a veto. -->
                <div v-if="impact.state !== 'idle'" class="mt-4 rounded-xl border border-line bg-surface-2 p-3 text-sm">
                    <p v-if="impact.state === 'checking'" class="text-ink-muted">{{ t('availability.impact.checking') }}</p>
                    <template v-else>
                        <p v-if="impact.appointments === null" class="text-ink-muted">{{ t('availability.impact.unknown') }}</p>
                        <p v-else-if="impact.appointments === 0" class="text-ink-muted">{{ t('availability.impact.none') }}</p>
                        <template v-else>
                            <p class="font-medium text-ink">{{ t('availability.impact.some', impact.appointments) }}</p>
                            <p class="mt-1 text-ink-muted">{{ t('availability.impact.warning') }}</p>
                        </template>
                    </template>
                </div>

                <p v-if="errors.availability" class="mt-3 text-xs text-danger">{{ errors.availability }}</p>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button type="button" class="btn-glow inline-flex items-center rounded-xl px-5 py-2.5 text-sm font-semibold" @click="submit">
                        {{ t('availability.editor.save') }}
                    </button>
                    <button v-if="form.id" type="button" class="text-sm font-semibold text-danger transition hover:opacity-80" @click="remove">
                        {{ t('availability.editor.remove') }}
                    </button>
                    <button type="button" class="text-sm font-semibold text-ink-muted transition hover:text-ink" @click="open = false">
                        {{ t('availability.editor.cancel') }}
                    </button>
                </div>
            </div>

            <!-- What the design offers that this build refuses. -->
            <div class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">{{ t('availability.omitted.title') }}</p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('availability.omitted.subtitle') }}</p>
                <ul class="mt-3 space-y-1.5 text-sm text-ink-muted">
                    <li v-for="key in omittedKeys" :key="key" class="flex items-start gap-2">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-ink-subtle" />
                        <span>{{ t(`availability.omitted.${key}`) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
