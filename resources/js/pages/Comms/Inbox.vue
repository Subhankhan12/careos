<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

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

const props = defineProps<{
    filters: { type: string | null; status: string; scope: string };
    threads: ThreadSummary[];
    activeThread:
        | (ThreadSummary & {
              messages: Array<{ id: string; author_type: string; body: string; ai_assisted: boolean; sent_at: string }>;
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

function reload(threadId?: string): void {
    router.get(
        '/comms/inbox',
        { ...filters, thread_id: threadId ?? props.activeThread?.id },
        { preserveState: false, replace: true },
    );
}

function openThread(id: string): void {
    reload(id);
}

function sendReply(): void {
    if (!props.activeThread || reply.value.trim() === '') {
        return;
    }

    router.post(
        props.actions.replyUrl,
        { thread_id: props.activeThread.id, body: reply.value },
        { preserveScroll: true, onSuccess: () => (reply.value = '') },
    );
}

function setStatus(action: 'close' | 'reopen'): void {
    if (!props.activeThread) {
        return;
    }

    router.post(props.actions.statusUrl, { thread_id: props.activeThread.id, action }, { preserveScroll: true });
}

function assignToMe(): void {
    if (!props.activeThread) {
        return;
    }

    router.post(
        props.actions.assignUrl,
        { thread_id: props.activeThread.id, assigned_to: null, assign_self: true },
        { preserveScroll: true },
    );
}

function requestAiDraft(): void {
    if (!props.activeThread) {
        return;
    }

    router.post(props.actions.aiDraftUrl, { thread_id: props.activeThread.id }, { preserveScroll: true });
}

function sendAiDraft(): void {
    if (!props.activeThread?.aiDraft) {
        return;
    }

    router.post(props.actions.sendDraftUrl, { action_id: props.activeThread.aiDraft.action_id }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('comms.inbox.title')" />

        <div class="mb-4">
            <h1 class="text-xl font-semibold">{{ t('comms.inbox.title') }}</h1>
            <p class="text-sm text-gray-500">{{ t('comms.inbox.subtitle') }}</p>
        </div>

        <div class="mb-4 flex flex-wrap gap-3">
            <label class="text-sm">
                {{ t('comms.inbox.filters.type') }}
                <select v-model="filters.type" class="ml-1 rounded border px-2 py-1 text-sm" @change="reload()">
                    <option :value="null">{{ t('comms.inbox.filters.all') }}</option>
                    <option value="patient">{{ t('comms.inbox.filters.patient') }}</option>
                    <option value="internal">{{ t('comms.inbox.filters.internal') }}</option>
                </select>
            </label>
            <label class="text-sm">
                {{ t('comms.inbox.filters.status') }}
                <select v-model="filters.status" class="ml-1 rounded border px-2 py-1 text-sm" @change="reload()">
                    <option value="open">{{ t('comms.inbox.filters.open') }}</option>
                    <option value="closed">{{ t('comms.inbox.filters.closed') }}</option>
                </select>
            </label>
            <label class="text-sm">
                {{ t('comms.inbox.filters.scope') }}
                <select v-model="filters.scope" class="ml-1 rounded border px-2 py-1 text-sm" @change="reload()">
                    <option value="all">{{ t('comms.inbox.filters.everyone') }}</option>
                    <option value="mine">{{ t('comms.inbox.filters.mine') }}</option>
                </select>
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <Card>
                <ul v-if="threads.length" class="divide-y">
                    <li
                        v-for="thread in threads"
                        :key="thread.id"
                        class="cursor-pointer py-2"
                        @click="openThread(thread.id)"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium">{{ thread.subject }}</span>
                            <span
                                v-if="thread.unread > 0"
                                class="rounded-full bg-blue-600 px-2 py-0.5 text-xs text-white"
                            >
                                {{ t('comms.inbox.unread', { count: thread.unread }) }}
                            </span>
                        </div>
                        <div class="text-xs text-gray-500">
                            {{ thread.patient ?? t('comms.inbox.filters.internal') }}
                            · {{ thread.last_message_at ?? '—' }}
                        </div>
                    </li>
                </ul>
                <p v-else class="py-4 text-sm text-gray-500">{{ t('comms.inbox.empty') }}</p>
            </Card>

            <Card class="md:col-span-2">
                <template v-if="activeThread">
                    <div class="mb-3 flex items-center justify-between">
                        <div>
                            <h2 class="font-semibold">{{ activeThread.subject }}</h2>
                            <p class="text-xs text-gray-500">
                                {{ t('comms.inbox.assignedTo') }}:
                                {{
                                    staff.find((member) => member.id === activeThread?.assigned_to)?.name ??
                                    t('comms.inbox.unassigned')
                                }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <Button type="button" @click="assignToMe">{{ t('comms.inbox.assignToMe') }}</Button>
                            <Button
                                v-if="activeThread.status === 'open'"
                                type="button"
                                @click="setStatus('close')"
                            >
                                {{ t('comms.inbox.close') }}
                            </Button>
                            <Button v-else type="button" @click="setStatus('reopen')">
                                {{ t('comms.inbox.reopen') }}
                            </Button>
                        </div>
                    </div>

                    <ol class="mb-4 space-y-3">
                        <li v-for="message in activeThread.messages" :key="message.id" class="rounded border p-2">
                            <div class="mb-1 flex items-center gap-2 text-xs text-gray-500">
                                <span>{{ t(`comms.inbox.author.${message.author_type}`) }}</span>
                                <span>{{ message.sent_at }}</span>
                                <span
                                    v-if="message.ai_assisted"
                                    class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-800"
                                >
                                    {{ t('comms.inbox.aiAssisted') }}
                                </span>
                            </div>
                            <p class="whitespace-pre-line text-sm">{{ message.body }}</p>
                        </li>
                    </ol>

                    <p
                        v-if="activeThread.clinician_attention_at"
                        class="mb-3 rounded bg-red-50 px-2 py-1 text-xs text-red-700"
                    >
                        {{ t('comms.inbox.clinicianAttention') }} · {{ activeThread.clinician_attention_reason }}
                    </p>

                    <div v-if="activeThread.aiDraft" class="mb-4 rounded border border-amber-300 bg-amber-50 p-3">
                        <p class="mb-1 text-xs font-semibold text-amber-800">
                            {{ t('comms.inbox.aiDraft.title') }}
                        </p>
                        <p class="mb-2 whitespace-pre-line text-sm">{{ activeThread.aiDraft.body }}</p>
                        <p class="mb-2 text-xs text-gray-600">
                            {{ t('comms.inbox.aiDraft.sources') }}:
                            <span v-for="(line, index) in activeThread.aiDraft.lines" :key="index" class="mr-2">
                                [{{ line.source.type }}{{ line.source.key ? ':' + line.source.key : '' }}]
                            </span>
                        </p>
                        <Button type="button" @click="sendAiDraft">{{ t('comms.inbox.aiDraft.send') }}</Button>
                    </div>
                    <div v-else class="mb-3">
                        <Button type="button" @click="requestAiDraft">{{ t('comms.inbox.aiDraft.request') }}</Button>
                    </div>

                    <form class="space-y-2" @submit.prevent="sendReply">
                        <label class="block text-sm font-medium" for="inbox-reply">
                            {{ t('comms.inbox.reply') }}
                        </label>
                        <textarea
                            id="inbox-reply"
                            v-model="reply"
                            class="w-full rounded border p-2 text-sm"
                            rows="3"
                            :placeholder="t('comms.inbox.replyPlaceholder')"
                        ></textarea>
                        <Button type="submit">{{ t('comms.inbox.send') }}</Button>
                    </form>
                </template>
                <p v-else class="py-4 text-sm text-gray-500">{{ t('comms.inbox.noSelection') }}</p>
            </Card>
        </div>
    </AppLayout>
</template>
