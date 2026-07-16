<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

const props = defineProps<{
    orders: Array<{
        id: string;
        patient_id: string;
        patient: string | null;
        item: string | null;
        category: string | null;
        priority: string;
        ordered_at: string;
        results: Array<{ id: string; value: string | null; has_document: boolean; entered_at: string }>;
        chart_url: string;
    }>;
    reviewUrl: string;
}>();

function review(orderId: string): void {
    useForm({ order_id: orderId }).post(props.reviewUrl, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('clinical.ordersReview.title')" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ t('clinical.ordersReview.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('clinical.ordersReview.subtitle') }}</p>
            </div>

            <Card>
                <p v-if="orders.length === 0" class="text-sm text-ink-muted">{{ t('clinical.ordersReview.empty') }}</p>
                <div v-for="order in orders" :key="order.id" class="border-b border-line/60 py-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-ink">{{ order.item }} <span class="text-xs text-ink-muted">({{ order.category }} · {{ order.priority }})</span></p>
                            <Link :href="order.chart_url" class="text-sm text-brand-600 hover:text-brand-700">{{ order.patient }}</Link>
                        </div>
                        <button type="button" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700" @click="review(order.id)">
                            {{ t('clinical.orders.markReviewed') }}
                        </button>
                    </div>
                    <!-- Raw values, no interpretation. -->
                    <ul class="mt-2 space-y-1">
                        <li v-for="r in order.results" :key="r.id" class="text-sm text-ink">
                            <span class="font-mono">{{ r.value ?? (r.has_document ? t('clinical.orders.seeDocument') : '') }}</span>
                            <span class="ml-2 text-xs text-ink-muted">{{ r.entered_at }}</span>
                        </li>
                    </ul>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
