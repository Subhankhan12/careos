<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Dispensing (PHARMACY.G4) — PRESENTATIONAL. The patient's active orders (with on-hand) + dispensing
// history; dispensing decrements stock (concurrency-safe, server-side). Operational facts only.
const { t, locale } = useI18n();

type Order = { id: string; name: string; dose: string; on_hand: number | null; dispense_url: string };
type DispenseRow = { id: string; name: string; quantity: number; dispensed_at: string };

const props = defineProps<{
    patient: { id: string; name: string };
    orders: Order[];
    history: DispenseRow[];
    actions: { can_dispense: boolean };
}>();

const forms = reactive<Record<string, { quantity: string }>>({});

function formFor(id: string): { quantity: string } {
    if (!forms[id]) {
        forms[id] = { quantity: '1' };
    }
    return forms[id];
}

function dispense(order: Order): void {
    router.post(order.dispense_url, { quantity: formFor(order.id).quantity }, { preserveScroll: true });
}

function fmtTime(iso: string): string {
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
}
</script>

<template>
    <AppLayout>
        <Head :title="t('pharmacy.dispensing.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('pharmacy.dispensing.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ patient.name }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('pharmacy.dispensing.subtitle') }}</p>
            </div>

            <!-- Active orders to dispense -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('pharmacy.dispensing.ordersHeading') }}</h2>
                <p v-if="orders.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('pharmacy.dispensing.noOrders') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-100">
                    <li v-for="order in orders" :key="order.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">{{ order.name }} <span class="text-ink-muted">· {{ order.dose }}</span></p>
                            <p class="text-xs text-ink-muted">{{ t('pharmacy.dispensing.onHand', { count: order.on_hand ?? 0 }) }}</p>
                        </div>
                        <div v-if="actions.can_dispense" class="flex items-center gap-2">
                            <input v-model="formFor(order.id).quantity" type="number" min="1" class="w-20 rounded-xl border border-euca-200 bg-white/70 px-2 py-1 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                            <button type="button" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700" @click="dispense(order)">{{ t('pharmacy.dispensing.dispense') }}</button>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Dispensing history -->
            <div class="glass-card p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('pharmacy.dispensing.historyHeading') }}</h2>
                <p v-if="history.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('pharmacy.dispensing.noHistory') }}</p>
                <ul v-else class="mt-3 divide-y divide-euca-100">
                    <li v-for="d in history" :key="d.id" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ d.name }} <span class="text-ink-muted">· ×{{ d.quantity }}</span></span>
                        <span class="text-xs text-ink-muted">{{ fmtTime(d.dispensed_at) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
