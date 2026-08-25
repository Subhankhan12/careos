<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatCard from '@/Components/StatCard.vue';
import { formatDateOnly } from '@/lib/date';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type ThreadSummary = {
    id: string;
    subject: string;
    type: string;
    status: string;
    patient: string | null;
    assigned_to: number | null;
    last_message_at: string | null;
    unread: number;
};
type Message = {
    id: string;
    author_type: string;
    body: string;
    ai_assisted: boolean;
    // The human whose explicit Send posted this message. Null for patient/system messages.
    sender: string | null;
    sent_at: string;
};

/*
 * The context pane, exactly as the server scopes it. Every element carries its own `visible`
 * flag: false means THIS VIEWER MAY NOT SEE IT, which the page must render as a restriction —
 * never as an empty value, because "none recorded" and "you may not look" are different claims.
 */
type PatientContext = {
    identity: { visible: boolean; name?: string; dateOfBirth?: string | null; mrn?: string };
    allergies: { visible: boolean; items: Array<{ id: string; substance: string; reaction: string | null; severity: string }> };
    nextAppointment: { visible: boolean; appointment: { id: string; startsAt: string; status: string } | null };
    balance: { visible: boolean; formatted: string | null };
    emailContact: { consented: boolean };
    links: { patient?: string };
};

const props = defineProps<{
    filters: { type: string | null; status: string; scope: string; needsHuman: boolean };
    threads: ThreadSummary[];
    counts: { open: number; needsHuman: number };
    activeThread:
        | (ThreadSummary & {
              messages: Message[];
              clinician_attention_at: string | null;
              clinician_attention_reason: string | null;
              aiDraft: { action_id: string; body: string; lines: Array<{ text: string; source: Record<string, string> }> } | null;
              context: PatientContext | null;
          })
        | null;
    staff: Array<{ id: number; name: string }>;
    actions: { replyUrl: string; statusUrl: string; assignUrl: string; aiDraftUrl: string; sendDraftUrl: string };
}>();

const filters = reactive({ ...props.filters });
const reply = ref('');

const assigneeName = computed(
    () => props.staff.find((m) => m.id === props.activeThread?.assigned_to)?.name ?? t('comms.inbox.unassigned'),
);
const showContext = computed(() => props.activeThread?.type === 'patient');

function reload(threadId?: string): void {
    router.get(
        '/comms/inbox',
        // The server owns every filter — the page re-requests and the query re-runs. A client-side
        // re-slice could only narrow what was already fetched, which is not the same answer.
        { ...filters, needs_human: filters.needsHuman ? '1' : undefined, thread_id: threadId ?? props.activeThread?.id },
        { preserveState: false, replace: true },
    );
}
function setFilter(key: 'type' | 'status' | 'scope', value: string | null): void {
    filters[key] = value as never;
    reload();
}
function toggleNeedsHuman(): void {
    filters.needsHuman = !filters.needsHuman;
    reload();
}
function openThread(id: string): void {
    reload(id);
}
function sendReply(): void {
    if (!props.activeThread || reply.value.trim() === '') return;
    router.post(props.actions.replyUrl, { thread_id: props.activeThread.id, body: reply.value }, { preserveScroll: true, onSuccess: () => (reply.value = '') });
}
function setStatus(action: 'close' | 'reopen'): void {
    if (!props.activeThread) return;
    router.post(props.actions.statusUrl, { thread_id: props.activeThread.id, action }, { preserveScroll: true });
}
function assignToMe(): void {
    if (!props.activeThread) return;
    router.post(props.actions.assignUrl, { thread_id: props.activeThread.id, assigned_to: null, assign_self: true }, { preserveScroll: true });
}
function requestAiDraft(): void {
    if (!props.activeThread) return;
    router.post(props.actions.aiDraftUrl, { thread_id: props.activeThread.id }, { preserveScroll: true });
}
function sendAiDraft(): void {
    if (!props.activeThread?.aiDraft) return;
    router.post(props.actions.sendDraftUrl, { action_id: props.activeThread.aiDraft.action_id }, { preserveScroll: true });
}
// Presentational only — moves the draft text into the composer for the human to edit; no server call.
function editAsReply(): void {
    if (props.activeThread?.aiDraft) reply.value = props.activeThread.aiDraft.body;
}

function initials(name: string | null): string {
    if (!name) return '·';
    const p = name.trim().split(/\s+/);
    return ((p[0]?.[0] ?? '') + (p.length > 1 ? (p[p.length - 1][0] ?? '') : '')).toUpperCase();
}
function timeLabel(value: string): string {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }).format(d);
    } catch {
        return value;
    }
}

/**
 * The provenance line under a message. Two recorded facts, stated together: a draft was involved,
 * and THIS PERSON sent it. Where the sender was not recorded the copy falls back to the practice
 * rather than inventing a name (D-179 — never assert an action by someone who may not have taken it).
 */
function provenanceLabel(message: Message): string {
    if (message.ai_assisted) {
        return message.sender ? t('comms.inbox.provenance.assisted', { name: message.sender }) : t('comms.inbox.provenance.assistedUnknown');
    }
    return message.sender ? t('comms.inbox.provenance.sentBy', { name: message.sender }) : '';
}

/** A full timestamp (not date-only) — safe to parse directly; see lib/date.ts. */
function dateTimeLabel(value: string): string {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(d);
    } catch {
        return value;
    }
}

// The declined affordances, named on the page. Iterated so a key that is removed from the copy
// disappears from the render too, and the test that asserts the RENDERED text notices (GOV.P3).
const omittedKeys = ['channels', 'delivered', 'undo', 'topics'] as const;

const typeFilters = [
    { key: 'type', value: null, label: 'comms.inbox.filters.all' },
    { key: 'type', value: 'patient', label: 'comms.inbox.filters.patient' },
    { key: 'type', value: 'internal', label: 'comms.inbox.filters.internal' },
] as const;
</script>

<template>
    <AppLayout>
        <Head :title="t('comms.inbox.title')" />
        <div class="space-y-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('comms.inbox.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('comms.inbox.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('comms.inbox.subtitle') }}</p>
            </div>

            <!-- Plain counts of rows that exist, counted server-side over the whole record (D-174).
                 The closed StatCard takes a value and a hint and computes nothing (D-166). -->
            <div class="grid gap-4 sm:grid-cols-2">
                <StatCard :label="t('comms.inbox.counts.open')" :value="String(counts.open)" :hint="t('comms.inbox.counts.openHint')" />
                <StatCard :label="t('comms.inbox.counts.needsHuman')" :value="String(counts.needsHuman)" :hint="t('comms.inbox.counts.needsHumanHint')" />
            </div>

            <div class="grid gap-5" :class="showContext ? 'xl:grid-cols-[300px_1fr_290px]' : 'lg:grid-cols-[300px_1fr]'">
                <!-- Left: filters + thread list -->
                <div class="glass-card flex flex-col gap-3 p-3">
                    <div class="space-y-2 px-1">
                        <div class="inline-flex flex-wrap items-center gap-1 rounded-full bg-euca-50/70 p-1">
                            <button type="button" class="rounded-full px-3 py-1.5 text-xs font-medium transition" :class="filters.status === 'open' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setFilter('status', 'open')">{{ t('comms.inbox.filters.open') }}</button>
                            <button type="button" class="rounded-full px-3 py-1.5 text-xs font-medium transition" :class="filters.status === 'closed' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setFilter('status', 'closed')">{{ t('comms.inbox.filters.closed') }}</button>
                        </div>
                        <div class="inline-flex flex-wrap items-center gap-1 rounded-full bg-euca-50/70 p-1">
                            <button v-for="f in typeFilters" :key="String(f.value)" type="button" class="rounded-full px-3 py-1.5 text-xs font-medium transition" :class="filters.type === f.value ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setFilter('type', f.value)">{{ t(f.label) }}</button>
                        </div>
                        <div class="inline-flex flex-wrap items-center gap-1 rounded-full bg-euca-50/70 p-1">
                            <button type="button" class="rounded-full px-3 py-1.5 text-xs font-medium transition" :class="filters.scope === 'all' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setFilter('scope', 'all')">{{ t('comms.inbox.filters.everyone') }}</button>
                            <button type="button" class="rounded-full px-3 py-1.5 text-xs font-medium transition" :class="filters.scope === 'mine' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="setFilter('scope', 'mine')">{{ t('comms.inbox.filters.mine') }}</button>
                        </div>
                        <!-- "Still needs a human" — the conjunction, not the raw flag. The flag is
                             never cleared, so filtering on it alone would offer a list no reply
                             could ever shorten (GOV.P2). -->
                        <button
                            type="button"
                            class="inline-flex w-full items-center justify-between gap-2 rounded-full px-3 py-1.5 text-xs font-medium transition"
                            :class="filters.needsHuman ? 'nav-pill-active text-ink' : 'bg-euca-50/70 text-ink-muted hover:text-ink'"
                            @click="toggleNeedsHuman"
                        >
                            <span>{{ t('comms.inbox.needsHuman') }}</span>
                            <span class="rounded-full bg-white/70 px-1.5 py-0.5 text-[0.65rem] font-semibold text-ink">{{ counts.needsHuman }}</span>
                        </button>
                        <p class="px-1 text-xs text-ink-subtle">{{ t('comms.inbox.listNote') }}</p>
                    </div>

                    <ul v-if="threads.length" class="space-y-1">
                        <li v-for="thread in threads" :key="thread.id">
                            <button type="button" class="flex w-full items-start gap-2.5 rounded-xl px-3 py-2.5 text-left transition" :class="activeThread?.id === thread.id ? 'bg-euca-50' : 'hover:bg-euca-50/60'" @click="openThread(thread.id)">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-euca-200 text-xs font-semibold text-euca-900">{{ initials(thread.patient) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">{{ thread.subject }}</span>
                                        <span v-if="thread.unread > 0" class="h-2 w-2 shrink-0 rounded-full bg-euca-600" :title="t('comms.inbox.unread', { count: thread.unread })"></span>
                                    </span>
                                    <span class="block truncate text-xs text-ink-subtle">
                                        {{ thread.patient ?? t('comms.inbox.filters.internal') }} · {{ thread.last_message_at ?? '—' }}
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
                    <EmptyState v-else :title="t('comms.inbox.emptyTitle')" :message="t('comms.inbox.empty')" />
                </div>

                <!-- Center: thread -->
                <div class="glass-card flex min-h-[28rem] flex-col p-0">
                    <template v-if="activeThread">
                        <!-- Dark thread header (this screen's one dark tile) -->
                        <div class="euca-tile-dark flex flex-wrap items-center justify-between gap-3 rounded-b-none p-5">
                            <div class="min-w-0">
                                <h2 class="truncate text-lg font-semibold text-euca-50">{{ activeThread.subject }}</h2>
                                <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-euca-200">
                                    <span>{{ activeThread.patient ?? t('comms.inbox.filters.internal') }}</span>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-white/10 px-2 py-0.5">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="activeThread.status === 'open' ? 'bg-euca-200' : 'bg-euca-400'"></span>
                                        {{ activeThread.status === 'open' ? t('comms.inbox.filters.open') : t('comms.inbox.filters.closed') }}
                                    </span>
                                    <span>{{ t('comms.inbox.assignedTo') }}: {{ assigneeName }}</span>
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="rounded-xl bg-white/15 px-3.5 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25" @click="assignToMe">{{ t('comms.inbox.assignToMe') }}</button>
                                <button v-if="activeThread.status === 'open'" type="button" class="rounded-xl bg-white/15 px-3.5 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25" @click="setStatus('close')">{{ t('comms.inbox.close') }}</button>
                                <button v-else type="button" class="rounded-xl bg-white/15 px-3.5 py-2 text-sm font-semibold text-euca-50 transition hover:bg-white/25" @click="setStatus('reopen')">{{ t('comms.inbox.reopen') }}</button>
                            </div>
                        </div>

                        <div class="flex-1 space-y-4 p-5">
                            <!-- Internal-thread chip: not visible to the patient -->
                            <div v-if="activeThread.type === 'internal'" class="inline-flex items-center gap-1.5 rounded-full border border-info/25 bg-info-soft px-3 py-1 text-xs font-medium text-ink">
                                <svg class="h-3.5 w-3.5 text-info" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                                    <path d="M8 10V8a4 4 0 0 1 8 0v2" stroke="currentColor" stroke-width="1.6" />
                                </svg>
                                {{ t('comms.inbox.internalChip') }}
                            </div>

                            <!-- Clinician-attention (electric fence handoff) banner -->
                            <div v-if="activeThread.clinician_attention_at" class="flex items-start gap-2 rounded-xl border border-danger/30 bg-danger-soft p-3 text-sm text-danger">
                                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 4 21 19H3L12 4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                    <path d="M12 10v4M12 16.5v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                </svg>
                                <span><span class="font-semibold">{{ t('comms.inbox.clinicianAttention') }}</span> · {{ activeThread.clinician_attention_reason }}</span>
                            </div>

                            <!-- Message stream: patient left · staff right · system centered -->
                            <div v-for="message in activeThread.messages" :key="message.id">
                                <div v-if="message.author_type === 'system'" class="text-center text-xs text-ink-subtle">
                                    {{ message.body }} · {{ timeLabel(message.sent_at) }}
                                </div>
                                <div v-else class="flex gap-2.5" :class="message.author_type === 'patient' ? '' : 'flex-row-reverse'">
                                    <div class="max-w-[80%]">
                                        <div class="rounded-2xl px-4 py-2.5 text-sm" :class="message.author_type === 'patient' ? 'bg-surface-2 text-ink' : 'bg-euca-100 text-ink'">
                                            <p class="whitespace-pre-line">{{ message.body }}</p>
                                        </div>
                                        <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-subtle" :class="message.author_type === 'patient' ? '' : 'justify-end'">
                                            {{ t(`comms.inbox.author.${message.author_type}`) }} · {{ timeLabel(message.sent_at) }}
                                            <span v-if="message.ai_assisted" class="rounded-full bg-warning-soft px-1.5 py-0.5 text-warning">✦ {{ t('comms.inbox.aiAssisted') }}</span>
                                        </p>
                                        <!-- Recorded provenance, staff-facing: a draft was involved AND this
                                             person sent it. PT.P4 left the PORTAL-side wording an open
                                             decision; this is the staff side, where naming the colleague who
                                             pressed Send is simply the accountability record. -->
                                        <p
                                            v-if="provenanceLabel(message)"
                                            class="mt-0.5 text-xs text-ink-subtle"
                                            :class="message.author_type === 'patient' ? '' : 'text-right'"
                                        >
                                            {{ provenanceLabel(message) }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- AI-assisted draft — never sends itself -->
                            <div v-if="activeThread.aiDraft" class="rounded-xl border border-dashed border-warning/50 bg-warning-soft p-4">
                                <div class="flex items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg euca-tile-dark text-xs text-euca-50">✦</span>
                                    <span class="text-sm font-semibold text-ink">{{ t('comms.inbox.aiDraft.title') }}</span>
                                </div>
                                <p class="mt-3 whitespace-pre-line text-sm text-ink">{{ activeThread.aiDraft.body }}</p>
                                <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                                    <span class="text-ink-muted">{{ t('comms.inbox.aiDraft.sources') }}:</span>
                                    <span v-for="(line, index) in activeThread.aiDraft.lines" :key="index" class="rounded-md bg-surface px-1.5 py-0.5 font-mono text-ink-muted">
                                        {{ line.source.type }}{{ line.source.key ? ':' + line.source.key : '' }}
                                    </span>
                                </div>
                                <div class="mt-4 flex flex-wrap items-center gap-3">
                                    <button type="button" class="btn-glow inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold" @click="sendAiDraft">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12l16-8-6 16-3-6-7-2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" /></svg>
                                        {{ t('comms.inbox.aiDraft.send') }}
                                    </button>
                                    <button type="button" class="text-sm font-semibold text-ink-muted transition hover:text-ink" @click="editAsReply">{{ t('comms.inbox.aiDraft.editAsReply') }}</button>
                                </div>
                                <p class="mt-2 text-xs text-ink-subtle">{{ t('comms.inbox.aiDraft.footnote') }}</p>
                            </div>
                            <!-- Request-draft is absent on flagged threads (a clinician replies in the plain composer). -->
                            <div v-else-if="!activeThread.clinician_attention_at">
                                <button type="button" class="rounded-xl border border-line bg-surface/70 px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2" @click="requestAiDraft">{{ t('comms.inbox.aiDraft.request') }}</button>
                            </div>
                        </div>

                        <form class="border-t border-line/70 p-5" @submit.prevent="sendReply">
                            <label class="mb-1.5 block text-sm font-medium text-ink" for="inbox-reply">{{ t('comms.inbox.reply') }}</label>
                            <div class="flex items-end gap-2">
                                <textarea id="inbox-reply" v-model="reply" rows="2" class="w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink shadow-sm transition placeholder:text-ink-subtle focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" :placeholder="t('comms.inbox.replyPlaceholder')"></textarea>
                                <button type="submit" class="btn-glow inline-flex shrink-0 items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold">{{ t('comms.inbox.send') }}</button>
                            </div>
                        </form>
                    </template>
                    <p v-else class="flex flex-1 items-center justify-center py-16 text-center text-sm text-ink-muted">{{ t('comms.inbox.noSelection') }}</p>
                </div>

                <!-- Right: context (patient threads only) -->
                <div v-if="showContext && activeThread" class="glass-card h-fit p-5">
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-euca-200 text-sm font-semibold text-euca-900">{{ initials(activeThread.patient) }}</span>
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-ink">{{ activeThread.patient }}</p>
                            <p class="text-xs text-ink-subtle">{{ t('comms.inbox.patientThread') }}</p>
                        </div>
                    </div>
                    <dl class="mt-4 space-y-3 border-t border-line/70 pt-4 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('comms.inbox.filters.status') }}</dt>
                            <dd class="font-medium text-ink">{{ activeThread.status === 'open' ? t('comms.inbox.filters.open') : t('comms.inbox.filters.closed') }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-ink-muted">{{ t('comms.inbox.assignedTo') }}</dt>
                            <dd class="font-medium text-ink">{{ assigneeName }}</dd>
                        </div>
                    </dl>

                    <!-- RECORDED FACTS ONLY. Each element is scoped by the SERVER; `visible: false`
                         means this viewer may not see it, and is rendered as a restriction rather
                         than as an empty value. -->
                    <template v-if="activeThread.context">
                        <div class="mt-5 border-t border-line/70 pt-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('comms.inbox.context.title') }}</p>

                            <!-- Identity -->
                            <dl class="mt-3 space-y-2 text-sm">
                                <template v-if="activeThread.context.identity.visible">
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-ink-muted">{{ t('comms.inbox.context.dateOfBirth') }}</dt>
                                        <dd class="font-medium text-ink">{{ formatDateOnly(activeThread.context.identity.dateOfBirth, locale) }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-ink-muted">{{ t('comms.inbox.context.mrn') }}</dt>
                                        <dd class="font-mono text-xs font-medium text-ink">{{ activeThread.context.identity.mrn }}</dd>
                                    </div>
                                </template>
                                <p v-else class="text-xs italic text-ink-subtle">{{ t('comms.inbox.context.identity') }}: {{ t('comms.inbox.context.restricted') }}</p>
                            </dl>

                            <!-- Recorded allergies. The clinician's own words; ordered by substance,
                                 never by severity, and never tinted by it (D-169/D-173). -->
                            <div class="mt-4">
                                <p class="text-xs font-medium text-ink-muted">{{ t('comms.inbox.context.allergies') }}</p>
                                <template v-if="activeThread.context.allergies.visible">
                                    <ul v-if="activeThread.context.allergies.items.length" class="mt-1.5 flex flex-wrap gap-1.5">
                                        <li
                                            v-for="allergy in activeThread.context.allergies.items"
                                            :key="allergy.id"
                                            class="rounded-full border border-warning/40 bg-warning-soft px-2 py-0.5 text-xs text-ink"
                                        >
                                            {{ allergy.substance }}<span v-if="allergy.reaction"> — {{ allergy.reaction }}</span>
                                            <span class="text-ink-muted"> ({{ allergy.severity }})</span>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-1 text-xs text-ink-subtle">{{ t('comms.inbox.context.allergiesNone') }}</p>
                                </template>
                                <p v-else class="mt-1 text-xs italic text-ink-subtle">{{ t('comms.inbox.context.restricted') }}</p>
                            </div>

                            <!-- Next appointment -->
                            <div class="mt-4">
                                <p class="text-xs font-medium text-ink-muted">{{ t('comms.inbox.context.nextAppointment') }}</p>
                                <template v-if="activeThread.context.nextAppointment.visible">
                                    <p v-if="activeThread.context.nextAppointment.appointment" class="mt-1 text-sm font-medium text-ink">
                                        {{ dateTimeLabel(activeThread.context.nextAppointment.appointment.startsAt) }}
                                    </p>
                                    <p v-else class="mt-1 text-xs text-ink-subtle">{{ t('comms.inbox.context.nextAppointmentNone') }}</p>
                                </template>
                                <p v-else class="mt-1 text-xs italic text-ink-subtle">{{ t('comms.inbox.context.restricted') }}</p>
                            </div>

                            <!-- Open balance, from the engine reader. The page never sums or divides. -->
                            <div class="mt-4">
                                <p class="text-xs font-medium text-ink-muted">{{ t('comms.inbox.context.balance') }}</p>
                                <p v-if="activeThread.context.balance.visible" class="mt-1 text-sm font-medium text-ink">{{ activeThread.context.balance.formatted }}</p>
                                <p v-else class="mt-1 text-xs italic text-ink-subtle">{{ t('comms.inbox.context.restricted') }}</p>
                            </div>

                            <!-- Email contact. The "no" case states the LEGAL carve-out, because
                                 non-consent does NOT mean this patient is never emailed (D-184). -->
                            <div class="mt-4">
                                <p class="text-xs font-medium text-ink-muted">{{ t('comms.inbox.context.email') }}</p>
                                <p class="mt-1 text-xs text-ink-muted">
                                    {{ activeThread.context.emailContact.consented ? t('comms.inbox.context.emailYes') : t('comms.inbox.context.emailNo') }}
                                </p>
                            </div>

                            <a
                                v-if="activeThread.context.links.patient"
                                :href="activeThread.context.links.patient"
                                class="mt-4 inline-block text-sm font-semibold text-euca-700 transition hover:text-euca-900"
                            >
                                {{ t('comms.inbox.context.openRecord') }} →
                            </a>
                            <p class="mt-3 text-xs text-ink-subtle">{{ t('comms.inbox.context.note') }}</p>
                        </div>
                    </template>

                    <p class="mt-4 text-xs text-ink-subtle">{{ t('comms.inbox.contextNote') }}</p>
                </div>
            </div>

            <!-- What the design offers that this build cannot honestly back. Naming the gap is the
                 alternative to drawing a channel with no driver or a state nothing reports
                 (D-176) — a reader who expected them learns they have no source, not that the
                 screen is broken. Same card as the governance dashboard. -->
            <div class="glass-card p-5">
                <p class="text-sm font-semibold text-ink">{{ t('comms.inbox.omitted.title') }}</p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('comms.inbox.omitted.subtitle') }}</p>
                <ul class="mt-3 space-y-1.5 text-sm text-ink-muted">
                    <li v-for="key in omittedKeys" :key="key" class="flex items-start gap-2">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-ink-subtle" />
                        <span>{{ t(`comms.inbox.omitted.${key}`) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
