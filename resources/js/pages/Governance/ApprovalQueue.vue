<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

interface PendingAction {
    id: string;
    agent: string;
    feature: string;
    toolKey: string;
    toolName: string | null;
    category: string | null;
    permission: string | null;
    autonomyLevel: string;
    ceiling: string | null;
    why: string;
    sources: Array<{ type: string; ref: string }>;
    reGroundsDraft: boolean;
    inputPayload: Record<string, unknown> | null;
    proposedOutput: Record<string, unknown> | null;
    diff: Record<string, unknown> | null;
    queuedAt: string | null;
    canReview: boolean;
    bulkEligible: boolean;
    approveUrl: string;
    rejectUrl: string;
}

interface ResolvedAction {
    id: string;
    agent: string;
    toolKey: string;
    toolName: string | null;
    status: string;
    reviewedBy: string | null;
    reviewerName: string | null;
    systemAttributed: boolean;
    rejectionReason: string | null;
    resolvedAt: string | null;
}

const props = defineProps<{
    pending: PendingAction[];
    resolved: ResolvedAction[];
    // Governance stat strip — every value computed server-side from REAL records; approvedPct /
    // avgReviewMinutes are null when there is no real source (no resolved actions in the window),
    // rendered as an honest "—" rather than a fabricated number.
    stats: { pending: number; fenceRefused: number; approvedPct: number | null; avgReviewMinutes: number | null; windowDays: number };
    // Resolved-view: real per-status counts, the real reviewer options, and the active filters —
    // all computed server-side over the RBAC-scoped, tenant-scoped resolved set (never fabricated).
    resolvedCounts: { all: number; executed: number; rejected: number; fence_refused: number };
    resolvedReviewers: Array<{ id: string; name: string }>;
    resolvedFilters: { status: string; q: string; reviewer: string; from: string; to: string };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);
const bulkFlash = computed(
    () => (page.props.flash as { bulk?: { approved: number; excluded: number; skipped: number } } | undefined)?.bulk,
);

// ── Chrome (presentational, client-side over already-loaded data) ─────────────
const view = ref<'pending' | 'resolved'>('pending');

// The REAL agent types present in the loaded pending actions (not a hardcoded set).
const agentTypes = computed(() => Array.from(new Set(props.pending.map((a) => a.agent))));
const agentFilter = ref<string>('all');
const filteredPending = computed(() =>
    agentFilter.value === 'all' ? props.pending : props.pending.filter((a) => a.agent === agentFilter.value),
);
const agentLabel = (agent: string): string => agent.charAt(0).toUpperCase() + agent.slice(1);

// ── Approve / reject — POST straight to the existing gate path ─────────────────
const rejectingId = ref<string | null>(null);
const rejectForm = useForm({ reason: '' });

function approve(action: PendingAction): void {
    router.post(action.approveUrl, {}, { preserveScroll: true });
}
function openReject(id: string): void {
    rejectingId.value = id;
    editingId.value = null;
    rejectForm.reason = '';
    rejectForm.clearErrors();
}
function confirmReject(action: PendingAction): void {
    rejectForm.post(action.rejectUrl, {
        preserveScroll: true,
        onSuccess: () => {
            rejectingId.value = null;
        },
    });
}

// ── Bulk-approve — LOW-RISK only; a loop over the real per-action gate ──────────
// Only bulkEligible (low-risk + canReview) actions are selectable; clinical/financial are excluded
// (individual review only). [Approve selected] posts the ids to the server, which loops the FULL
// per-action approve gate and re-enforces the clinical/financial exclusion server-side.
const selectedIds = ref<Set<string>>(new Set());
const bulkProcessing = ref(false);

const eligiblePending = computed(() => filteredPending.value.filter((a) => a.bulkEligible));
const selectedCount = computed(() => selectedIds.value.size);
const allEligibleSelected = computed(
    () => eligiblePending.value.length > 0 && eligiblePending.value.every((a) => selectedIds.value.has(a.id)),
);

function toggleSelect(id: string): void {
    const next = new Set(selectedIds.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selectedIds.value = next;
}
function toggleSelectAll(): void {
    selectedIds.value = allEligibleSelected.value ? new Set() : new Set(eligiblePending.value.map((a) => a.id));
}
function clearSelection(): void {
    selectedIds.value = new Set();
}
function approveSelected(): void {
    if (!selectedIds.value.size) {
        return;
    }
    bulkProcessing.value = true;
    router.post(
        '/governance/approvals/bulk-approve',
        { ids: Array.from(selectedIds.value) },
        {
            preserveScroll: true,
            onSuccess: () => clearSelection(),
            onFinish: () => {
                bulkProcessing.value = false;
            },
        },
    );
}

// ── Edit before sending — the SAME approve path, carrying an edited payload ─────
// The reviewer edits the tool's INPUT payload (what execute re-grounds). Submitting it as
// edited_payload posts the edited content THROUGH the gate: ApprovalQueue::approve still
// re-authorises + re-grounds + asserts-pending, and records it as human-edited. This is not
// a second path and not a bypass — it changes the content, never the gate.
const editingId = ref<string | null>(null);
const editText = ref('');
const editError = ref('');
const editProcessing = ref(false);

function openEdit(action: PendingAction): void {
    editingId.value = action.id;
    rejectingId.value = null;
    editError.value = '';
    editText.value = JSON.stringify(action.inputPayload ?? {}, null, 2);
}
function submitEdit(action: PendingAction): void {
    let parsed: Record<string, unknown>;
    try {
        parsed = JSON.parse(editText.value);
    } catch {
        editError.value = t('aiQueue.edit.invalidJson');
        return;
    }
    editError.value = '';
    editProcessing.value = true;
    router.post(
        action.approveUrl,
        { edited_payload: parsed },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingId.value = null;
            },
            onFinish: () => {
                editProcessing.value = false;
            },
        },
    );
}

function pretty(value: Record<string, unknown> | null): string {
    return value ? JSON.stringify(value, null, 2) : '—';
}
function dateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}
// Honest render of a metric with no real source: show "—", never a fabricated value.
function orDash(value: number | null): string {
    return value === null ? '—' : String(value);
}
// Resolved outcome badge tint: executed = success, fence_refused = warning (the fence), else danger.
function resolvedStatusClass(status: string): string {
    if (status === 'executed') return 'bg-success-soft text-success';
    if (status === 'fence_refused') return 'bg-warning-soft text-warning';
    return 'bg-danger-soft text-danger';
}

// ── Resolved view — search + status/date/reviewer filters over REAL fields (server-side) ─────────
// Every filter maps to a real column; the server RBAC/tenant-scopes the results and counts. Changing
// a filter partial-reloads only the resolved props, preserving the component (the Resolved tab stays).
const rFilters = reactive({ ...props.resolvedFilters });
let searchTimer: ReturnType<typeof setTimeout> | undefined;

function submitResolvedFilters(): void {
    const params: Record<string, string> = {};
    if (rFilters.status !== 'all') params.rstatus = rFilters.status;
    if (rFilters.q) params.rq = rFilters.q;
    if (rFilters.reviewer) params.rreviewer = rFilters.reviewer;
    if (rFilters.from) params.rfrom = rFilters.from;
    if (rFilters.to) params.rto = rFilters.to;

    router.get('/governance/approvals', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['resolved', 'resolvedCounts', 'resolvedReviewers', 'resolvedFilters'],
    });
}
function setResolvedStatus(status: string): void {
    rFilters.status = status;
    submitResolvedFilters();
}
function onSearchInput(): void {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(submitResolvedFilters, 300);
}

// Group the (already resolved-time ordered) rows by their local resolved date — presentational.
const groupedResolved = computed(() => {
    const groups: Array<{ label: string; rows: ResolvedAction[] }> = [];
    const index = new Map<string, ResolvedAction[]>();
    for (const row of props.resolved) {
        const label = row.resolvedAt ? new Date(row.resolvedAt).toLocaleDateString() : '—';
        if (!index.has(label)) {
            const rows: ResolvedAction[] = [];
            index.set(label, rows);
            groups.push({ label, rows });
        }
        index.get(label)!.push(row);
    }
    return groups;
});
function timeOnly(iso: string | null): string {
    return iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—';
}
const hasResolvedFilters = computed(
    () => rFilters.status !== 'all' || !!rFilters.q || !!rFilters.reviewer || !!rFilters.from || !!rFilters.to,
);
function clearResolvedFilters(): void {
    rFilters.status = 'all';
    rFilters.q = '';
    rFilters.reviewer = '';
    rFilters.from = '';
    rFilters.to = '';
    submitResolvedFilters();
}
</script>

<template>
    <AppLayout>
        <Head :title="t('aiQueue.title')" />
        <div class="settings-surface space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('aiQueue.eyebrow') }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('aiQueue.title') }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('aiQueue.subtitle') }}</p>
                </div>

                <!-- Pending / Resolved view toggle (a view switch over existing data). -->
                <div class="inline-flex flex-none items-center gap-1 rounded-full bg-euca-50/80 p-1" role="tablist" :aria-label="t('aiQueue.title')">
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="view === 'pending'"
                        class="rounded-full px-3.5 py-1.5 text-sm font-medium transition"
                        :class="view === 'pending' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                        @click="view = 'pending'"
                    >
                        {{ t('aiQueue.chrome.pending', { count: pending.length }) }}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="view === 'resolved'"
                        class="rounded-full px-3.5 py-1.5 text-sm font-medium transition"
                        :class="view === 'resolved' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                        @click="view = 'resolved'"
                    >
                        {{ t('aiQueue.chrome.resolved') }}
                    </button>
                </div>
            </div>

            <p v-if="flash === 'approved' || flash === 'rejected' || flash === 'approved_edited'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t(`aiQueue.flash.${flash}`) }}
            </p>
            <!-- A fence-refusal is not the reviewer's error — the electric fence refused it and it was recorded. -->
            <p v-else-if="flash === 'fence_refused'" class="rounded-2xl border border-warning/30 bg-warning-soft p-4 text-sm text-warning">
                {{ t('aiQueue.flash.fence_refused') }}
            </p>
            <!-- Bulk-approve summary: real per-outcome counts (approved / excluded clinical+financial / skipped). -->
            <p v-else-if="flash === 'bulk' && bulkFlash" class="rounded-2xl border border-euca-300 bg-euca-50/60 p-4 text-sm text-ink">
                {{ t('aiQueue.bulk.summary', { approved: bulkFlash.approved, excluded: bulkFlash.excluded, skipped: bulkFlash.skipped }) }}
            </p>

            <!-- ── Governance stat strip — REAL data only (honest "—" where no source) ────────── -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="glass-card p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('aiQueue.stats.pending') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-ink">{{ stats.pending }}</p>
                </div>
                <div class="glass-card p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('aiQueue.stats.approvedPct', { days: stats.windowDays }) }}</p>
                    <p class="mt-1 text-2xl font-semibold text-ink">{{ stats.approvedPct === null ? '—' : stats.approvedPct + '%' }}</p>
                </div>
                <div class="glass-card p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('aiQueue.stats.avgReview', { days: stats.windowDays }) }}</p>
                    <p class="mt-1 text-2xl font-semibold text-ink">{{ stats.avgReviewMinutes === null ? '—' : t('aiQueue.stats.minutes', { value: stats.avgReviewMinutes }) }}</p>
                </div>
                <!-- Danger-tint on the fence count (per the wireframe) — the real fence_refused count. -->
                <div class="glass-card border-danger/30 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-danger">{{ t('aiQueue.stats.fenceRefused') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-danger">{{ stats.fenceRefused }}</p>
                </div>
            </div>

            <!-- ── PENDING VIEW ─────────────────────────────────────────────── -->
            <template v-if="view === 'pending'">
                <!-- Agent-type filter pills (client-side over the loaded actions; real agents only). -->
                <div v-if="agentTypes.length > 1" class="inline-flex flex-wrap items-center gap-1 rounded-full bg-euca-50/80 p-1">
                    <button
                        type="button"
                        class="rounded-full px-3 py-1.5 text-sm font-medium transition"
                        :class="agentFilter === 'all' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                        @click="agentFilter = 'all'"
                    >
                        {{ t('aiQueue.chrome.allAgents') }}
                    </button>
                    <button
                        v-for="agent in agentTypes"
                        :key="agent"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-sm font-medium transition"
                        :class="agentFilter === agent ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                        @click="agentFilter = agent"
                    >
                        {{ agentLabel(agent) }}
                    </button>
                </div>

                <!-- Bulk-action bar — LOW-RISK only. Clinical/financial are never selectable (individual
                     review only); [Approve selected] loops the full per-action gate server-side. -->
                <div v-if="eligiblePending.length" class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-euca-200 bg-euca-50/50 p-3 text-sm">
                    <label class="inline-flex items-center gap-2 font-medium text-ink">
                        <input type="checkbox" :checked="allEligibleSelected" class="h-4 w-4 rounded border-line text-euca-600" @change="toggleSelectAll" />
                        {{ t('aiQueue.bulk.selectAll', { count: eligiblePending.length }) }}
                    </label>
                    <span class="text-xs text-ink-subtle">{{ t('aiQueue.bulk.note') }}</span>
                    <div v-if="selectedCount" class="ml-auto flex items-center gap-2">
                        <span class="text-xs font-semibold text-euca-800">{{ t('aiQueue.bulk.selected', { count: selectedCount }) }}</span>
                        <Button type="button" pill :block="false" :disabled="bulkProcessing" @click="approveSelected">{{ t('aiQueue.bulk.approveSelected') }}</Button>
                        <Button type="button" variant="ghost" pill :block="false" @click="clearSelection">{{ t('aiQueue.bulk.clear') }}</Button>
                    </div>
                </div>

                <p v-if="!pending.length" class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-muted">{{ t('aiQueue.empty') }}</p>
                <p v-else-if="!filteredPending.length" class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-muted">{{ t('aiQueue.chrome.noneForFilter') }}</p>

                <!-- Pending action cards — dashed glass, eucardIn entrance; the review controls
                     are UNCHANGED (approve/reject route through the existing ApprovalQueue path). -->
                <div
                    v-for="(action, index) in filteredPending"
                    :key="action.id"
                    class="glass-card euca-card-in border-dashed border-euca-300 p-5"
                    :style="{ '--euca-card-delay': (0.02 + index * 0.04) + 's' }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <!-- Bulk-select: LOW-RISK only. Clinical/financial show an individual-review note instead. -->
                            <input
                                v-if="action.bulkEligible"
                                type="checkbox"
                                :checked="selectedIds.has(action.id)"
                                :aria-label="t('aiQueue.bulk.selectOne')"
                                class="mt-1 h-4 w-4 flex-none rounded border-line text-euca-600"
                                @change="toggleSelect(action.id)"
                            />
                            <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full bg-euca-100 px-2 py-0.5 text-[11px] font-medium text-euca-800">{{ agentLabel(action.agent) }}</span>
                                <!-- Tool-permission chip: the tool_key + the REAL permission approve re-authorises against. -->
                                <span class="inline-flex items-center gap-1 rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-medium text-euca-700">
                                    <span class="font-mono">{{ action.toolKey }}</span>
                                    <span v-if="action.permission" class="text-euca-800/70">· {{ t('aiQueue.card.requires', { permission: action.permission }) }}</span>
                                </span>
                                <span v-if="action.category" class="inline-flex items-center rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-medium text-euca-700">{{ action.category }}</span>
                                <!-- Autonomy: the PROPOSED level. Ceiling: the AutonomyPolicy CAP (distinct). -->
                                <span class="inline-flex items-center rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-medium text-euca-700">{{ t('aiQueue.card.autonomy', { level: action.autonomyLevel }) }}</span>
                                <span v-if="action.ceiling" class="inline-flex items-center rounded-full border border-euca-300 bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-800">{{ t('aiQueue.card.ceiling', { level: action.ceiling }) }}</span>
                                <!-- Clinical/financial can never be bulk-approved — individual review only (server-enforced). -->
                                <span v-if="!action.bulkEligible && (action.category === 'clinical' || action.category === 'financial')" class="inline-flex items-center rounded-full border border-warning/40 bg-warning-soft px-2.5 py-0.5 text-xs font-semibold text-warning">{{ t('aiQueue.bulk.individualOnly') }}</span>
                            </div>
                            <p class="mt-1 text-xs text-ink-subtle">
                                {{ t('aiQueue.card.agent', { agent: action.agent }) }} · <span class="font-mono">{{ action.feature }}</span>
                                <span v-if="action.queuedAt"> · {{ t('aiQueue.card.queued', { time: dateTime(action.queuedAt) }) }}</span>
                            </p>
                            </div>
                        </div>
                        <!-- Fence discipline: AI content is always badged, never presented as authoritative judgment. -->
                        <span class="inline-flex flex-none items-center rounded-full border border-warning/30 bg-warning-soft px-2.5 py-1 text-xs font-semibold text-warning">{{ t('aiQueue.badge') }}</span>
                    </div>

                    <div class="mt-4 space-y-3 text-sm">
                        <!-- What / Why — the action's real intent (the tool's declared name) + its recorded reason. -->
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('aiQueue.card.what') }}</p>
                            <p class="mt-1 font-medium text-ink">{{ action.toolName ?? action.toolKey }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('aiQueue.card.why') }}</p>
                            <p class="mt-1 text-ink">{{ action.why }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ t('aiQueue.card.grounding') }}</p>
                            <!-- The full inspectable payload stays (correctly-more-real). -->
                            <pre class="mt-1 max-h-56 overflow-auto rounded-xl border border-line bg-surface-2/60 p-3 text-xs text-ink">{{ pretty(action.proposedOutput) }}</pre>
                            <!-- Sources — the REAL recorded grounding refs, or an honest absence (never fabricated). -->
                            <p class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                                <span class="font-semibold text-ink-muted">{{ t('aiQueue.card.sources') }}:</span>
                                <template v-if="action.sources.length">
                                    <span v-for="(source, i) in action.sources" :key="i" :title="source.type + ' · ' + source.ref" class="inline-flex max-w-full items-center gap-1 truncate rounded-full bg-euca-50 px-2 py-0.5 font-mono text-euca-700">↳ {{ source.type }} · {{ source.ref }}</span>
                                </template>
                                <span v-else class="text-ink-subtle">{{ t('aiQueue.card.noSources') }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Act-through-existing-path controls. Hidden when this reviewer lacks the tool's
                         permission — a UX hint; the server denies regardless (the cap binds server-side). -->
                    <!-- The approve contract, made visible: this states exactly what the server already
                         does on approve (re-authorise against the tool's REAL permission, then re-run
                         the tool — which re-derives from live state — before it takes effect). The
                         permission is interpolated from the action; the wording adapts to whether the
                         action carries a draft (honest per-action, never a hardcoded claim). -->
                    <p v-if="action.canReview && action.permission" class="mt-4 flex items-start gap-1.5 text-xs text-ink-subtle">
                        <svg class="mt-0.5 h-3.5 w-3.5 flex-none text-euca-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1.5 3 4.3v4.4c0 4 2.7 7.7 7 9.3 4.3-1.6 7-5.3 7-9.3V4.3L10 1.5Zm3.03 6.34-3.5 4.5a.75.75 0 0 1-1.12.07l-2-2a.75.75 0 1 1 1.06-1.06l1.4 1.4 2.98-3.83a.75.75 0 1 1 1.18.92Z" clip-rule="evenodd" /></svg>
                        <span>{{ t(action.reGroundsDraft ? 'aiQueue.card.contractDraft' : 'aiQueue.card.contractAction', { permission: action.permission }) }}</span>
                    </p>

                    <div v-if="action.canReview" class="mt-3 flex flex-wrap items-center gap-2">
                        <!-- Default controls: approve as-is, edit-before-sending, or reject. -->
                        <template v-if="rejectingId !== action.id && editingId !== action.id">
                            <Button type="button" pill :block="false" @click="approve(action)">{{ t('aiQueue.actions.approve') }}</Button>
                            <Button type="button" variant="secondary" pill :block="false" @click="openEdit(action)">{{ t('aiQueue.edit.action') }}</Button>
                            <Button type="button" variant="secondary" pill :block="false" @click="openReject(action.id)">{{ t('aiQueue.actions.reject') }}</Button>
                        </template>

                        <!-- Edit-before-sending panel: edit the tool's input payload, then approve. The
                             edited content posts THROUGH the same gate (re-authorise + re-ground +
                             still-pending) and is recorded as human-edited — see the approve contract above. -->
                        <div v-else-if="editingId === action.id" class="w-full space-y-2 rounded-2xl border border-euca-300 bg-euca-50/40 p-3">
                            <p class="text-xs font-semibold text-euca-800">{{ t('aiQueue.edit.title') }}</p>
                            <p class="text-xs text-ink-subtle">{{ t('aiQueue.edit.help', { permission: action.permission ?? '—' }) }}</p>
                            <textarea
                                v-model="editText"
                                rows="8"
                                spellcheck="false"
                                class="block w-full rounded-xl border border-line bg-surface px-3 py-2 font-mono text-xs text-ink"
                            ></textarea>
                            <p v-if="editError" class="text-xs text-danger">{{ editError }}</p>
                            <div class="flex items-center gap-2">
                                <Button type="button" pill :block="false" :disabled="editProcessing" @click="submitEdit(action)">{{ t('aiQueue.edit.submit') }}</Button>
                                <Button type="button" variant="ghost" pill :block="false" @click="editingId = null">{{ t('aiQueue.edit.cancel') }}</Button>
                            </div>
                        </div>

                        <!-- Reject panel. -->
                        <div v-else class="w-full space-y-2 rounded-2xl border border-danger/20 bg-danger-soft/50 p-3">
                            <p class="text-xs font-semibold text-danger">{{ t('aiQueue.actions.reasonLabel') }}</p>
                            <textarea
                                v-model="rejectForm.reason"
                                :placeholder="t('aiQueue.actions.reasonPlaceholder')"
                                rows="2"
                                class="block w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink"
                            ></textarea>
                            <p v-if="rejectForm.errors.reason" class="text-xs text-danger">{{ rejectForm.errors.reason }}</p>
                            <div class="flex items-center gap-2">
                                <Button type="button" variant="danger" pill :block="false" :disabled="rejectForm.processing" @click="confirmReject(action)">{{ t('aiQueue.actions.confirmReject') }}</Button>
                                <Button type="button" variant="ghost" pill :block="false" @click="rejectingId = null">{{ t('aiQueue.actions.cancel') }}</Button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-4 text-xs text-ink-subtle">{{ t('aiQueue.actions.noPermission') }}</p>
                </div>
            </template>

            <!-- ── RESOLVED VIEW — search + status/date/reviewer filters + grouping ─────────── -->
            <Card v-else :title="t('aiQueue.resolved.title')" :subtitle="t('aiQueue.resolved.subtitle')">
                <div class="space-y-4">
                    <!-- Search over REAL fields (tool / agent / feature / why / reason). -->
                    <div class="flex items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2">
                        <svg class="h-4 w-4 flex-none text-ink-subtle" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="1.7" /><path d="m20 20-3.8-3.8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
                        <input
                            v-model="rFilters.q"
                            type="search"
                            :placeholder="t('aiQueue.resolved.search')"
                            class="w-full bg-transparent text-sm text-ink outline-none"
                            @input="onSearchInput"
                        />
                    </div>

                    <!-- Status filter pills with REAL counts + reviewer / date sub-filters. -->
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex flex-wrap items-center gap-1 rounded-full bg-euca-50/80 p-1">
                            <button type="button" class="rounded-full px-3 py-1.5 text-sm font-medium transition" :class="rFilters.status === 'all' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setResolvedStatus('all')">
                                {{ t('aiQueue.resolved.filterAll', { count: resolvedCounts.all }) }}
                            </button>
                            <button type="button" class="rounded-full px-3 py-1.5 text-sm font-medium transition" :class="rFilters.status === 'executed' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setResolvedStatus('executed')">
                                {{ t('aiQueue.resolved.filterApproved', { count: resolvedCounts.executed }) }}
                            </button>
                            <button type="button" class="rounded-full px-3 py-1.5 text-sm font-medium transition" :class="rFilters.status === 'rejected' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setResolvedStatus('rejected')">
                                {{ t('aiQueue.resolved.filterRejected', { count: resolvedCounts.rejected }) }}
                            </button>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition" :class="rFilters.status === 'fence_refused' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setResolvedStatus('fence_refused')">
                                <span class="h-1.5 w-1.5 rounded-full bg-danger"></span>{{ t('aiQueue.resolved.filterFenceRefused', { count: resolvedCounts.fence_refused }) }}
                            </button>
                        </div>

                        <select v-model="rFilters.reviewer" class="rounded-full border border-line bg-surface px-3 py-1.5 text-sm text-ink" @change="submitResolvedFilters">
                            <option value="">{{ t('aiQueue.resolved.anyReviewer') }}</option>
                            <option v-for="r in resolvedReviewers" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                        <input v-model="rFilters.from" type="date" :aria-label="t('aiQueue.resolved.dateFrom')" class="rounded-full border border-line bg-surface px-3 py-1.5 text-sm text-ink" @change="submitResolvedFilters" />
                        <input v-model="rFilters.to" type="date" :aria-label="t('aiQueue.resolved.dateTo')" class="rounded-full border border-line bg-surface px-3 py-1.5 text-sm text-ink" @change="submitResolvedFilters" />
                        <button v-if="hasResolvedFilters" type="button" class="text-xs font-medium text-euca-700 hover:text-euca-800" @click="clearResolvedFilters">{{ t('aiQueue.resolved.clear') }}</button>
                    </div>

                    <!-- Grouped-by-day list — real resolved actions, real attribution + reasons. -->
                    <p v-if="!resolved.length" class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-muted">
                        {{ hasResolvedFilters ? t('aiQueue.resolved.noneForFilters') : t('aiQueue.resolved.empty') }}
                    </p>
                    <div v-for="group in groupedResolved" v-else :key="group.label" class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ group.label }}</p>
                        <div
                            v-for="action in group.rows"
                            :key="action.id"
                            class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-line/60 bg-surface p-3"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">
                                    {{ action.toolName ?? action.toolKey }} <span class="text-ink-subtle">· {{ agentLabel(action.agent) }}</span>
                                </p>
                                <!-- Real attribution: the human reviewer, or the system (fence). -->
                                <p class="mt-0.5 text-xs text-ink-subtle">
                                    <template v-if="action.systemAttributed">{{ t('aiQueue.resolved.bySystem', { time: timeOnly(action.resolvedAt) }) }}</template>
                                    <template v-else>{{ t('aiQueue.resolved.byReviewer', { reviewer: action.reviewerName ?? '—', time: timeOnly(action.resolvedAt) }) }}</template>
                                    <span v-if="action.rejectionReason" class="text-ink-muted"> · “{{ action.rejectionReason }}”</span>
                                </p>
                            </div>
                            <span class="inline-flex flex-none items-center rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="resolvedStatusClass(action.status)">
                                {{ t(`aiQueue.status.${action.status}`) }}
                            </span>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
