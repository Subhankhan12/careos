<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

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
        <div class="space-y-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('clinical.ordersReview.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('clinical.ordersReview.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('clinical.ordersReview.subtitle') }}</p>
            </div>

            <div class="glass-card p-2">
                <p v-if="orders.length === 0" class="px-4 py-12 text-center text-sm text-ink-muted">{{ t('clinical.ordersReview.empty') }}</p>
                <div v-for="order in orders" :key="order.id" class="rounded-xl px-4 py-4 transition hover:bg-euca-50/50">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">
                                {{ order.item }}
                                <span class="text-xs font-normal text-ink-muted">({{ order.category }} · {{ order.priority }})</span>
                            </p>
                            <Link :href="order.chart_url" class="text-sm font-medium text-euca-700 transition hover:text-euca-800">{{ order.patient }}</Link>
                        </div>
                        <button type="button" class="btn-glow inline-flex shrink-0 items-center rounded-xl px-4 py-2 text-sm font-semibold" @click="review(order.id)">
                            {{ t('clinical.orders.markReviewed') }}
                        </button>
                    </div>
                    <!-- Raw values, as entered. No interpretation, flags, or ranges. -->
                    <ul class="mt-2 space-y-1">
                        <li v-for="r in order.results" :key="r.id" class="text-sm text-ink">
                            <span class="font-mono tabular-nums">{{ r.value ?? (r.has_document ? t('clinical.orders.seeDocument') : '') }}</span>
                            <span class="ml-2 text-xs text-ink-muted">{{ r.entered_at }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
