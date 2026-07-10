<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

defineProps<{
    invoices: Array<{
        id: string;
        number: string;
        issue_date: string | null;
        due_date: string | null;
        currency: string;
        total_minor: number;
        open_balance_minor: number;
        status: string;
        download_url: string;
    }>;
}>();
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.invoices.title')" />
        <Card>
            <h1 class="mb-3 font-semibold">{{ t('portal.invoices.title') }}</h1>
            <div class="overflow-x-auto">
                <table v-if="invoices.length" class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs uppercase text-gray-500">
                            <th class="py-2">{{ t('portal.invoices.number') }}</th>
                            <th class="py-2">{{ t('portal.invoices.issued') }}</th>
                            <th class="py-2">{{ t('portal.invoices.due') }}</th>
                            <th class="py-2">{{ t('portal.invoices.total') }}</th>
                            <th class="py-2">{{ t('portal.invoices.open') }}</th>
                            <th class="py-2">{{ t('portal.invoices.status') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices" :key="invoice.id" class="border-b">
                            <td class="py-2">{{ invoice.number }}</td>
                            <td class="py-2">{{ invoice.issue_date ?? '—' }}</td>
                            <td class="py-2">{{ invoice.due_date ?? '—' }}</td>
                            <td class="py-2">{{ (invoice.total_minor / 100).toFixed(2) }} {{ invoice.currency }}</td>
                            <td class="py-2">{{ (invoice.open_balance_minor / 100).toFixed(2) }} {{ invoice.currency }}</td>
                            <td class="py-2">{{ invoice.status }}</td>
                            <td class="py-2">
                                <a :href="invoice.download_url" class="text-blue-600 hover:underline">
                                    {{ t('portal.invoices.download') }}
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-sm text-gray-500">{{ t('portal.invoices.empty') }}</p>
            </div>
        </Card>
    </PortalLayout>
</template>
