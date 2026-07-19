<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type Message = { id: string; author_type: string; body: string; sent_at: string };

const props = defineProps<{
    threads: Array<{ id: string; subject: string; status: string; last_message_at: string | null; unread: number }>;
    activeThread: { id: string; subject: string; status: string; messages: Message[] } | null;
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

function dayLabel(value: string): string {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    try {
        return new Intl.DateTimeFormat(locale.value, { weekday: 'long', day: 'numeric', month: 'long' }).format(d);
    } catch {
        return value;
    }
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

// Interleave day dividers with messages.
const timeline = computed(() => {
    const out: Array<{ type: 'day'; label: string } | { type: 'msg'; message: Message }> = [];
    let lastDay = '';
    for (const m of props.activeThread?.messages ?? []) {
        const day = dayLabel(m.sent_at);
        if (day !== lastDay) {
            out.push({ type: 'day', label: day });
            lastDay = day;
        }
        out.push({ type: 'msg', message: m });
    }
    return out;
});
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.messages.title')" />

        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('portal.messages.eyebrow') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{{ t('portal.messages.title') }}</h1>
        <p class="mt-1 max-w-2xl text-ink-muted">{{ t('portal.messages.subtitle') }}</p>

        <div class="mt-6 grid gap-5 lg:grid-cols-[minmax(260px,340px)_1fr]">
            <div class="glass-card p-3">
                <ul v-if="threads.length" class="space-y-1">
                    <li v-for="thread in threads" :key="thread.id">
                        <button
                            type="button"
                            class="w-full rounded-xl px-3 py-3 text-left transition"
                            :class="activeThread?.id === thread.id ? 'bg-euca-50' : 'hover:bg-euca-50/60'"
                            @click="openThread(thread.id)"
                        >
                            <span class="flex items-center justify-between gap-2">
                                <span class="min-w-0 flex-1 truncate font-semibold text-ink">{{ thread.subject }}</span>
                                <span
                                    v-if="thread.unread > 0"
                                    class="shrink-0 rounded-full bg-euca-700 px-2 py-0.5 text-xs font-semibold text-white"
                                >
                                    {{ t('portal.messages.unread', { count: thread.unread }) }}
                                </span>
                                <span
                                    v-else-if="thread.status !== 'open'"
                                    class="shrink-0 rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium text-ink-muted"
                                >
                                    {{ t('portal.messages.closed') }}
                                </span>
                            </span>
                            <span class="mt-0.5 block text-xs text-ink-subtle">{{ thread.last_message_at ?? '—' }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else class="px-3 py-8 text-center text-sm text-ink-muted">{{ t('portal.messages.empty') }}</p>
                <p v-if="threads.length" class="px-3 pb-1 pt-3 text-xs text-ink-subtle">{{ t('portal.messages.onlyYours') }}</p>
            </div>

            <div class="glass-card flex min-h-[24rem] flex-col p-6">
                <template v-if="activeThread">
                    <div class="flex flex-wrap items-center gap-2 border-b border-line/70 pb-4">
                        <h2 class="text-lg font-semibold text-ink">{{ activeThread.subject }}</h2>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="activeThread.status === 'open' ? 'bg-euca-100 text-euca-800' : 'bg-surface-2 text-ink-muted'"
                        >
                            <span class="h-1.5 w-1.5 rounded-full" :class="activeThread.status === 'open' ? 'bg-euca-600' : 'bg-ink-subtle'"></span>
                            {{ activeThread.status === 'open' ? t('portal.messages.open') : t('portal.messages.closed') }}
                        </span>
                    </div>

                    <div class="flex-1 space-y-4 py-5">
                        <template v-for="(item, i) in timeline" :key="i">
                            <div v-if="item.type === 'day'" class="flex justify-center">
                                <span class="rounded-full bg-euca-50 px-3 py-1 text-xs text-ink-subtle">{{ item.label }}</span>
                            </div>
                            <div
                                v-else
                                class="flex gap-2.5"
                                :class="item.message.author_type === 'patient' ? 'flex-row-reverse' : ''"
                            >
                                <div class="max-w-[80%]">
                                    <div
                                        class="rounded-2xl px-4 py-2.5 text-sm"
                                        :class="item.message.author_type === 'patient' ? 'bg-euca-100 text-ink' : 'bg-surface-2 text-ink'"
                                    >
                                        <p class="whitespace-pre-line">{{ item.message.body }}</p>
                                    </div>
                                    <p
                                        class="mt-1 text-xs text-ink-subtle"
                                        :class="item.message.author_type === 'patient' ? 'text-right' : ''"
                                    >
                                        {{ t(`portal.messages.author.${item.message.author_type}`) }} · {{ timeLabel(item.message.sent_at) }}
                                    </p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <form v-if="activeThread.status === 'open'" class="border-t border-line/70 pt-4" @submit.prevent="send">
                        <div class="flex items-end gap-2">
                            <textarea
                                v-model="reply"
                                class="min-h-[2.75rem] w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink shadow-sm transition placeholder:text-ink-subtle focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                                rows="1"
                                :aria-label="t('portal.messages.reply')"
                                :placeholder="t('portal.messages.reply')"
                            ></textarea>
                            <button type="submit" class="btn-glow inline-flex shrink-0 items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M4 12l16-8-6 16-3-6-7-2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                                </svg>
                                {{ t('portal.messages.send') }}
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-ink-subtle">{{ t('portal.messages.urgentNote') }}</p>
                    </form>
                    <p v-else class="border-t border-line/70 pt-4 text-sm text-ink-subtle">{{ t('portal.messages.closedNote') }}</p>
                </template>
                <p v-else class="flex flex-1 items-center justify-center text-center text-ink-muted">
                    {{ t('portal.messages.noSelection') }}
                </p>
            </div>
        </div>
    </PortalLayout>
</template>
