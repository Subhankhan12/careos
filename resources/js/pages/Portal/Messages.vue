<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

const props = defineProps<{
    threads: Array<{ id: string; subject: string; status: string; last_message_at: string | null; unread: number }>;
    activeThread:
        | { id: string; subject: string; status: string; messages: Array<{ id: string; author_type: string; body: string; sent_at: string }> }
        | null;
    actions: { storeUrl: string };
}>();

const reply = ref('');

function openThread(id: string): void {
    router.get('/portal/messages', { thread_id: id }, { preserveState: false, replace: true });
}

function send(): void {
    if (!props.activeThread || reply.value.trim() === '') {
        return;
    }

    router.post(
        props.actions.storeUrl,
        { thread_id: props.activeThread.id, body: reply.value },
        { onSuccess: () => (reply.value = '') },
    );
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.messages.title')" />
        <div class="grid gap-4 md:grid-cols-3">
            <Card>
                <ul v-if="threads.length" class="divide-y">
                    <li v-for="thread in threads" :key="thread.id" class="cursor-pointer py-2 text-sm" @click="openThread(thread.id)">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ thread.subject }}</span>
                            <span v-if="thread.unread > 0" class="rounded-full bg-blue-600 px-2 py-0.5 text-xs text-white">
                                {{ t('portal.messages.unread', { count: thread.unread }) }}
                            </span>
                        </div>
                        <span class="text-xs text-gray-500">{{ thread.last_message_at ?? '—' }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-500">{{ t('portal.messages.empty') }}</p>
            </Card>

            <Card class="md:col-span-2">
                <template v-if="activeThread">
                    <h2 class="mb-3 font-semibold">{{ activeThread.subject }}</h2>
                    <ol class="mb-4 space-y-3">
                        <li v-for="message in activeThread.messages" :key="message.id" class="rounded border p-2">
                            <div class="mb-1 text-xs text-gray-500">
                                {{ t(`portal.messages.author.${message.author_type}`) }} · {{ message.sent_at }}
                            </div>
                            <p class="whitespace-pre-line text-sm">{{ message.body }}</p>
                        </li>
                    </ol>
                    <form v-if="activeThread.status === 'open'" class="space-y-2" @submit.prevent="send">
                        <textarea
                            v-model="reply"
                            class="w-full rounded border p-2 text-sm"
                            rows="3"
                            :placeholder="t('portal.messages.reply')"
                        ></textarea>
                        <Button type="submit">{{ t('portal.messages.send') }}</Button>
                    </form>
                </template>
                <p v-else class="text-sm text-gray-500">{{ t('portal.messages.noSelection') }}</p>
            </Card>
        </div>
    </PortalLayout>
</template>
