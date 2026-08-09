<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
    approveUrl: string;
    rejectUrl: string;
}

const props = defineProps<{
    pending: PendingAction[];
    resolved: Array<{ id: string; agent: string; toolKey: string; status: string; reviewedBy: string | null; rejectionReason: string | null; resolvedAt: string | null }>;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

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
                            </div>
                            <p class="mt-1 text-xs text-ink-subtle">
                                {{ t('aiQueue.card.agent', { agent: action.agent }) }} · <span class="font-mono">{{ action.feature }}</span>
                                <span v-if="action.queuedAt"> · {{ t('aiQueue.card.queued', { time: dateTime(action.queuedAt) }) }}</span>
                            </p>
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

            <!-- ── RESOLVED VIEW ────────────────────────────────────────────── -->
            <Card v-else :title="t('aiQueue.resolved.title')" :subtitle="t('aiQueue.resolved.subtitle')">
                <table v-if="resolved.length" class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('aiQueue.resolved.tool') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('aiQueue.resolved.status') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('aiQueue.resolved.reason') }}</th>
                            <th class="py-2 font-medium">{{ t('aiQueue.resolved.when') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="action in resolved" :key="action.id" class="border-b border-line/60">
                            <td class="py-2 pr-4 font-mono text-ink">{{ action.toolKey }}</td>
                            <td class="py-2 pr-4">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="action.status === 'executed' ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'"
                                >
                                    {{ t(`aiQueue.status.${action.status}`) }}
                                </span>
                            </td>
                            <td class="py-2 pr-4 text-ink-muted">{{ action.rejectionReason ?? '—' }}</td>
                            <td class="py-2 text-ink-subtle">{{ dateTime(action.resolvedAt) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-sm text-ink-muted">{{ t('aiQueue.resolved.empty') }}</p>
            </Card>
        </div>
    </AppLayout>
</template>
