<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

defineProps<{
    batches: Array<{
        id: string;
        type: string;
        original_filename: string;
        status: string;
        row_count: number;
        summary: { counts?: Record<string, number> } | null;
        committed_at: string | null;
        created_at: string | null;
        show_url: string;
    }>;
    createUrl: string;
}>();
</script>

<template>
    <AppLayout>
        <Head :title="t('import.index.title')" />
        <div class="space-y-6">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-2xl font-semibold text-ink">{{ t('import.index.title') }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('import.index.subtitle') }}</p>
                </div>
                <Link :href="createUrl" class="inline-flex items-center justify-center rounded-md bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-700">
                    {{ t('import.index.new') }}
                </Link>
            </div>

            <Card>
                <p v-if="batches.length === 0" class="text-sm text-ink-muted">{{ t('import.index.empty') }}</p>
                <table v-else class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('import.index.filename') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('import.index.status') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('import.index.rows') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('import.index.created') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="batch in batches" :key="batch.id" class="border-b border-line/60">
                            <td class="py-2 pr-4 text-ink">{{ batch.original_filename }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ batch.status }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ batch.row_count }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ batch.created_at }}</td>
                            <td class="py-2 text-right">
                                <Link :href="batch.show_url" class="font-medium text-brand-600 hover:text-brand-700">{{ t('import.index.view') }}</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>
    </AppLayout>
</template>
