<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

defineProps<{
    sessions: Array<{ id: string; provider: string; status: string; created_at: string | null; token_url: string }>;
}>();

// Tokens live only in memory for the moment of joining — never persisted.
const joined = reactive<Record<string, { token: string; room: string; expires_at: string } | undefined>>({});

async function join(session: { id: string; token_url: string }): Promise<void> {
    const response = await fetch(session.token_url, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
        },
    });

    if (response.ok) {
        joined[session.id] = await response.json();
    }
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.telehealth.title')" />
        <Card>
            <h1 class="mb-1 font-semibold">{{ t('portal.telehealth.title') }}</h1>
            <p class="mb-3 text-xs text-gray-500">{{ t('portal.telehealth.notice') }}</p>
            <ul v-if="sessions.length" class="divide-y">
                <li v-for="session in sessions" :key="session.id" class="py-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span>{{ session.created_at ?? '—' }} · {{ session.status }}</span>
                        <Button type="button" @click="join(session)">{{ t('portal.telehealth.join') }}</Button>
                    </div>
                    <p v-if="joined[session.id]" class="mt-2 rounded bg-green-50 p-2 text-xs">
                        {{ t('portal.telehealth.joined') }}
                    </p>
                </li>
            </ul>
            <p v-else class="text-sm text-gray-500">{{ t('portal.telehealth.empty') }}</p>
        </Card>
    </PortalLayout>
</template>
