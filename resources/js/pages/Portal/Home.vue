<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

defineProps<{
    nextAppointment: { id: string; service: string | null; starts_at: string; status: string } | null;
    unreadMessages: number;
    outstandingBalanceMinor: number;
}>();
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.nav.home')" />
        <div class="grid gap-4 md:grid-cols-3">
            <Card>
                <h2 class="mb-1 text-sm font-medium text-gray-500">{{ t('portal.home.nextAppointment') }}</h2>
                <p v-if="nextAppointment" class="text-sm">
                    {{ nextAppointment.service ?? '—' }}<br />
                    <span class="font-semibold">{{ nextAppointment.starts_at }}</span>
                </p>
                <p v-else class="text-sm text-gray-500">{{ t('portal.home.none') }}</p>
            </Card>
            <Card>
                <h2 class="mb-1 text-sm font-medium text-gray-500">{{ t('portal.home.unreadMessages') }}</h2>
                <p class="text-2xl font-semibold">{{ unreadMessages }}</p>
            </Card>
            <Card>
                <h2 class="mb-1 text-sm font-medium text-gray-500">{{ t('portal.home.outstandingBalance') }}</h2>
                <p class="text-2xl font-semibold">{{ (outstandingBalanceMinor / 100).toFixed(2) }}</p>
            </Card>
        </div>
    </PortalLayout>
</template>
