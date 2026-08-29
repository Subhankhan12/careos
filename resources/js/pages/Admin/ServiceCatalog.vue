<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';

const { t } = useI18n();

interface ServiceRow {
    id: string;
    name: string;
    code: string;
    category: string | null;
    durationMinutes: number;
    bufferBeforeMinutes: number;
    bufferAfterMinutes: number;
    requiresResourceTypes: string[];
    bookableOnline: boolean;
    active: boolean;
    branchIds: string[];
    appointmentCount: number;
}

const props = defineProps<{
    services: ServiceRow[];
    filters: { category: string | null; state: string | null };
    categories: string[];
    counts: { total: number; active: number; online: number };
    branches: Array<{ id: string; name: string }>;
    resourceTypes: string[];
    slotStrideMinutes: number;
    actions: { storeUrl: string; updateUrl: string };
}>();

const stateFilters = ['active', 'archived', 'online'] as const;

// The declined affordances, iterated — removing a key removes the rendered line, so the test that
// asserts the RENDERED output notices (GOV.P3).
const omittedKeys = ['price', 'granularity', 'minNotice', 'providerBuffer', 'suggestedDuration'] as const;

/* ------------------------------------------------------------------ filters (server-side) */

function setFilter(key: 'state' | 'category', value: string | null): void {
    const next: Record<string, string> = {};
    const state = key === 'state' ? value : props.filters.state;
    const category = key === 'category' ? value : props.filters.category;
    if (state) next.state = state;
    if (category) next.category = category;
    router.get('/admin/services', next, { preserveState: false, replace: true });
}

/* ------------------------------------------------------------------------------- the editor */

const blank = () => ({
    id: null as string | null,
    name: '',
    code: '',
    category: '',
    default_duration_minutes: 30,
    buffer_before_minutes: 0,
    buffer_after_minutes: 0,
    requires_resource_types: ['practitioner'] as string[],
    bookable_online: false,
    active: true,
    branch_ids: [] as string[],
});

const form = reactive(blank());
const open = ref(false);
const errors = ref<Record<string, string>>({});

const editing = computed(() => form.id !== null);

function startCreate(): void {
    Object.assign(form, blank());
    errors.value = {};
    open.value = true;
}

function startEdit(row: ServiceRow): void {
    Object.assign(form, {
        id: row.id,
        name: row.name,
        code: row.code,
        category: row.category ?? '',
        default_duration_minutes: row.durationMinutes,
        buffer_before_minutes: row.bufferBeforeMinutes,
        buffer_after_minutes: row.bufferAfterMinutes,
        requires_resource_types: [...row.requiresResourceTypes],
        bookable_online: row.bookableOnline,
        active: row.active,
        branch_ids: [...row.branchIds],
    });
    errors.value = {};
    open.value = true;
}

function toggleIn(list: string[], value: string): void {
    const i = list.indexOf(value);
    if (i === -1) list.push(value);
    else list.splice(i, 1);
}

/**
 * Every write posts to the server; nothing about a service is decided here. The duration and
 * buffers the engine reads are the ones the server persisted — this page never recomputes a slot,
 * a window or a price.
 */
function submit(): void {
    const payload = {
        name: form.name,
        code: form.code,
        category: form.category || null,
        default_duration_minutes: form.default_duration_minutes,
        buffer_before_minutes: form.buffer_before_minutes,
        buffer_after_minutes: form.buffer_after_minutes,
        requires_resource_types: form.requires_resource_types,
        bookable_online: form.bookable_online,
        active: form.active,
        branch_ids: form.branch_ids,
    };

    const url = editing.value ? props.actions.updateUrl.replace('__ID__', String(form.id)) : props.actions.storeUrl;

    router.post(url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onError: (e) => {
            errors.value = e as Record<string, string>;
        },
    });
}

/** Archive / restore — never delete; see the controller's note and the archive caption. */
function setActive(row: ServiceRow, active: boolean): void {
    router.post(`/admin/services/${row.id}/active`, { active }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('serviceCatalog.title')" />
        <div class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('serviceCatalog.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('serviceCatalog.title') }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('serviceCatalog.subtitle') }}</p>
                </div>
                <button type="button" class="btn-glow inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold" @click="startCreate">
                    {{ t('serviceCatalog.editor.newTitle') }}
                </button>
            </div>

            <!-- Plain counts over the whole catalog, counted server-side (D-166/D-174). -->
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard :label="t('serviceCatalog.counts.total')" :value="String(counts.total)" :hint="t('serviceCatalog.counts.totalHint')" />
                <StatCard :label="t('serviceCatalog.counts.active')" :value="String(counts.active)" :hint="t('serviceCatalog.counts.activeHint')" />
                <StatCard :label="t('serviceCatalog.counts.online')" :value="String(counts.online)" :hint="t('serviceCatalog.counts.onlineHint')" />
            </div>

            <!-- What the engine actually reads. An admin shortening a duration is editing the
                 booking engine, and is told so in words. -->
            <div class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">{{ t('serviceCatalog.engine.title') }}</p>
                <p class="mt-2 text-sm text-ink-muted">{{ t('serviceCatalog.engine.body') }}</p>
                <p class="mt-2 text-sm text-ink-muted">{{ t('serviceCatalog.engine.stride', { minutes: slotStrideMinutes }) }}</p>
                <p class="mt-2 text-sm text-ink-muted">{{ t('serviceCatalog.engine.exposure') }}</p>
            </div>

            <!-- Filters over REAL columns only. -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex flex-wrap items-center gap-1 rounded-full bg-euca-50/70 p-1">
                    <button type="button" class="rounded-full px-3 py-1.5 text-xs font-medium transition" :class="filters.state === null ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setFilter('state', null)">
                        {{ t('serviceCatalog.filters.all') }}
                    </button>
                    <button v-for="s in stateFilters" :key="s" type="button" class="rounded-full px-3 py-1.5 text-xs font-medium transition" :class="filters.state === s ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setFilter('state', s)">
                        {{ t(`serviceCatalog.filters.${s}`) }}
                    </button>
                </div>
                <select
                    class="rounded-xl border border-line bg-surface-2 px-3 py-2 text-sm text-ink"
                    :value="filters.category ?? ''"
                    @change="setFilter('category', ($event.target as HTMLSelectElement).value || null)"
                >
                    <option value="">{{ t('serviceCatalog.filters.anyCategory') }}</option>
                    <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                </select>
            </div>

            <!-- The catalog. -->
            <div v-if="services.length" class="glass-card overflow-x-auto p-0">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead>
                        <tr class="border-b border-line/70 text-left text-xs uppercase tracking-wide text-ink-subtle">
                            <th class="px-5 py-3 font-medium">{{ t('serviceCatalog.table.service') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('serviceCatalog.table.duration') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('serviceCatalog.table.buffers') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('serviceCatalog.table.resources') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('serviceCatalog.table.online') }}</th>
                            <th class="px-3 py-3 font-medium">{{ t('serviceCatalog.table.usage') }}</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in services" :key="row.id" class="border-b border-line/40 last:border-0">
                            <td class="px-5 py-3">
                                <button type="button" class="text-left font-semibold text-ink transition hover:text-euca-700" @click="startEdit(row)">{{ row.name }}</button>
                                <p class="text-xs text-ink-subtle">
                                    <span class="font-mono">{{ row.code }}</span>
                                    <template v-if="row.category"> · {{ row.category }}</template>
                                    <!-- A recorded state, stated as a word — no colour carries the meaning (D-169). -->
                                    <template v-if="!row.active"> · {{ t('serviceCatalog.archived') }}</template>
                                </p>
                            </td>
                            <td class="px-3 py-3 text-ink">{{ row.durationMinutes }} {{ t('serviceCatalog.editor.minutes') }}</td>
                            <td class="px-3 py-3 text-ink-muted">{{ row.bufferBeforeMinutes }} / {{ row.bufferAfterMinutes }}</td>
                            <td class="px-3 py-3 text-ink-muted">{{ row.requiresResourceTypes.join(', ') }}</td>
                            <td class="px-3 py-3 text-ink-muted">{{ row.bookableOnline ? '✓' : '—' }}</td>
                            <td class="px-3 py-3 text-ink-muted">
                                {{ row.appointmentCount > 0 ? t('serviceCatalog.inUse', row.appointmentCount) : t('serviceCatalog.notUsed') }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button v-if="row.active" type="button" class="text-xs font-semibold text-ink-muted transition hover:text-ink" @click="setActive(row, false)">
                                    {{ t('serviceCatalog.editor.archive') }}
                                </button>
                                <button v-else type="button" class="text-xs font-semibold text-euca-700 transition hover:text-euca-900" @click="setActive(row, true)">
                                    {{ t('serviceCatalog.editor.restore') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="glass-card p-8 text-center">
                <p class="text-sm font-medium text-ink">{{ filters.state || filters.category ? t('serviceCatalog.emptyFiltered') : t('serviceCatalog.empty') }}</p>
                <p v-if="!filters.state && !filters.category" class="mt-1 text-sm text-ink-muted">{{ t('serviceCatalog.emptyHint') }}</p>
            </div>

            <p class="text-xs text-ink-subtle">{{ t('serviceCatalog.archiveNote') }}</p>

            <!-- What the design offers that this build cannot honestly back. -->
            <div class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">{{ t('serviceCatalog.omitted.title') }}</p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('serviceCatalog.omitted.subtitle') }}</p>
                <ul class="mt-3 space-y-1.5 text-sm text-ink-muted">
                    <li v-for="key in omittedKeys" :key="key" class="flex items-start gap-2">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-ink-subtle" />
                        <span>{{ t(`serviceCatalog.omitted.${key}`) }}</span>
                    </li>
                </ul>
            </div>

            <!-- Editor -->
            <div v-if="open" class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">{{ editing ? t('serviceCatalog.editor.editTitle') : t('serviceCatalog.editor.newTitle') }}</p>
                <p v-if="!editing" class="mt-1 text-sm text-ink-muted">{{ t('serviceCatalog.editor.newSubtitle') }}</p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.name') }}</span>
                        <input v-model="form.name" type="text" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" />
                        <span v-if="errors.name" class="mt-1 block text-xs text-danger">{{ errors.name }}</span>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.code') }}</span>
                        <input v-model="form.code" type="text" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 font-mono text-sm text-ink" />
                        <span class="mt-1 block text-xs text-ink-subtle">{{ t('serviceCatalog.editor.codeHint') }}</span>
                        <span v-if="errors.code" class="mt-1 block text-xs text-danger">{{ errors.code }}</span>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.category') }}</span>
                        <input v-model="form.category" type="text" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" />
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.duration') }}</span>
                        <input v-model.number="form.default_duration_minutes" type="number" min="1" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" />
                        <span v-if="errors.default_duration_minutes" class="mt-1 block text-xs text-danger">{{ errors.default_duration_minutes }}</span>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.bufferBefore') }}</span>
                        <input v-model.number="form.buffer_before_minutes" type="number" min="0" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" />
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.bufferAfter') }}</span>
                        <input v-model.number="form.buffer_after_minutes" type="number" min="0" class="mt-1 w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink" />
                    </label>
                </div>

                <div class="mt-4">
                    <p class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.resourceTypes') }}</p>
                    <p class="text-xs text-ink-subtle">{{ t('serviceCatalog.editor.resourceTypesHint') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button
                            v-for="rt in resourceTypes"
                            :key="rt"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition"
                            :class="form.requires_resource_types.includes(rt) ? 'border-euca-600 bg-euca-50 text-ink' : 'border-line text-ink-muted hover:text-ink'"
                            @click="toggleIn(form.requires_resource_types, rt)"
                        >
                            {{ rt }}
                        </button>
                    </div>
                    <span v-if="errors.requires_resource_types" class="mt-1 block text-xs text-danger">{{ errors.requires_resource_types }}</span>
                </div>

                <div class="mt-4">
                    <p class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.branches') }}</p>
                    <p class="text-xs text-ink-subtle">{{ t('serviceCatalog.editor.branchesHint') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button
                            v-for="b in branches"
                            :key="b.id"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition"
                            :class="form.branch_ids.includes(b.id) ? 'border-euca-600 bg-euca-50 text-ink' : 'border-line text-ink-muted hover:text-ink'"
                            @click="toggleIn(form.branch_ids, b.id)"
                        >
                            {{ b.name }}
                        </button>
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    <label class="flex items-start gap-2">
                        <input v-model="form.active" type="checkbox" class="mt-1" />
                        <span>
                            <span class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.activeLabel') }}</span>
                            <span class="block text-xs text-ink-subtle">{{ t('serviceCatalog.editor.activeHint') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input v-model="form.bookable_online" type="checkbox" class="mt-1" />
                        <span>
                            <span class="text-sm font-medium text-ink">{{ t('serviceCatalog.editor.onlineLabel') }}</span>
                            <span class="block text-xs text-ink-subtle">{{ t('serviceCatalog.editor.onlineHint') }}</span>
                        </span>
                    </label>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button type="button" class="btn-glow inline-flex items-center rounded-xl px-5 py-2.5 text-sm font-semibold" @click="submit">
                        {{ editing ? t('serviceCatalog.editor.save') : t('serviceCatalog.editor.create') }}
                    </button>
                    <button type="button" class="text-sm font-semibold text-ink-muted transition hover:text-ink" @click="open = false">
                        {{ t('serviceCatalog.editor.cancel') }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
