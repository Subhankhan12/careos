<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

defineProps<{
    documents: Array<{
        id: string;
        category: string;
        title: string;
        original_filename: string;
        mime_type: string;
        uploaded_at: string;
        shared_at: string | null;
        download_url: string;
    }>;
}>();
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.documents.title')" />
        <Card>
            <h1 class="mb-3 font-semibold">{{ t('portal.documents.title') }}</h1>
            <ul v-if="documents.length" class="divide-y">
                <li v-for="document in documents" :key="document.id" class="flex items-center justify-between py-2 text-sm">
                    <span>{{ document.title }} <span class="text-gray-500">({{ document.category }})</span></span>
                    <a :href="document.download_url" class="text-blue-600 hover:underline">
                        {{ t('portal.documents.download') }}
                    </a>
                </li>
            </ul>
            <p v-else class="text-sm text-gray-500">{{ t('portal.documents.empty') }}</p>
        </Card>
    </PortalLayout>
</template>
