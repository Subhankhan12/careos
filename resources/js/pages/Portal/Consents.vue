<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

const props = defineProps<{
    consents: Array<{
        id: string;
        template_key: string;
        title: string;
        scope_keys: string[];
        status: string;
        granted_at: string | null;
        withdrawn_at: string | null;
    }>;
    actions: { withdrawUrl: string };
}>();

const withdrawReasons = reactive<Record<string, string>>({});

function withdraw(consentId: string): void {
    const reason = withdrawReasons[consentId]?.trim();
    if (!reason) {
        return;
    }

    router.post(props.actions.withdrawUrl, { consent_id: consentId, reason });
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.consents.title')" />
        <Card>
            <h1 class="mb-3 font-semibold">{{ t('portal.consents.title') }}</h1>
            <ul v-if="consents.length" class="divide-y">
                <li v-for="consent in consents" :key="consent.id" class="py-3 text-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-medium">{{ consent.title }}</span>
                            <span class="ml-2 text-xs text-gray-500">{{ consent.scope_keys.join(', ') }}</span>
                        </div>
                        <span class="text-xs">{{ t(`portal.consents.status.${consent.status}`) }}</span>
                    </div>
                    <form
                        v-if="consent.status === 'granted'"
                        class="mt-2 flex gap-2"
                        @submit.prevent="withdraw(consent.id)"
                    >
                        <input
                            v-model="withdrawReasons[consent.id]"
                            class="w-64 rounded border px-2 py-1 text-sm"
                            :placeholder="t('portal.consents.withdrawReason')"
                        />
                        <Button type="submit">{{ t('portal.consents.withdraw') }}</Button>
                    </form>
                </li>
            </ul>
            <p v-else class="text-sm text-gray-500">{{ t('portal.consents.empty') }}</p>
        </Card>
    </PortalLayout>
</template>
