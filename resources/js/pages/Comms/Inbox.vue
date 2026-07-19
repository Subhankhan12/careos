<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

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
type Message = { id: string; author_type: string; body: string; ai_assisted: boolean; sent_at: string };

const props = defineProps<{
    filters: { type: string | null; status: string; scope: string };
    threads: ThreadSummary[];
    activeThread:
        | (ThreadSummary & {
              messages: Message[];
              clinician_attention_at: string | null;
              clinician_attention_reason: string | null;
              aiDraft: { action_id: string; body: string; lines: Array<{ text: string; source: Record<string, string> }> } | null;
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
    router.get('/comms/inbox', { ...filters, thread_id: threadId ?? props.activeThread?.id }, { preserveState: false, replace: true });
}
function setFilter(key: 'type' | 'status' | 'scope', value: string | null): void {
    filters[key] = value as never;
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
                    <p v-else class="px-3 py-8 text-center text-sm text-ink-muted">{{ t('comms.inbox.empty') }}</p>
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
                                        <p class="mt-1 flex items-center gap-1.5 text-xs text-ink-subtle" :class="message.author_type === 'patient' ? '' : 'justify-end'">
                                            {{ t(`comms.inbox.author.${message.author_type}`) }} · {{ timeLabel(message.sent_at) }}
                                            <span v-if="message.ai_assisted" class="rounded-full bg-warning-soft px-1.5 py-0.5 text-warning">✦ {{ t('comms.inbox.aiAssisted') }}</span>
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
                    <p class="mt-4 text-xs text-ink-subtle">{{ t('comms.inbox.contextNote') }}</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
