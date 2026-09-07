<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import AllergyRecordPanel from '@/Components/AllergyRecordPanel.vue';

// Dispensing (PHARMACY.G4) — PRESENTATIONAL. The patient's active orders (with on-hand) + dispensing
// history; dispensing decrements stock (concurrency-safe, server-side). Operational facts only.
const { t, locale } = useI18n();

type Order = { id: string; name: string; dose: string; on_hand: number | null; dispense_url: string };
type DispenseRow = { id: string; name: string; quantity: number; dispensed_at: string; charged: boolean };

type Allergy = {
    id: string;
    substance: string;
    reaction: string | null;
    source: string | null;
    severity: string;
    status: string;
    recorded_at: string;
    verified_at: string | null;
};
const props = defineProps<{
    patient: { id: string; name: string };
    orders: Order[];
    // QA-FIX.5a (P5-C1) — the RECORDED allergy list and the medication-safety seam state, rendered by
    // the SAME shared component the clinical chart uses so the wording can never drift apart.
    allergies: Allergy[];
    medicationSafety: { providerConfigured: boolean; advisories: Array<{ code: string; message: string; source: string }> };
    history: DispenseRow[];
    // QA-FIX.5b (P5-C2 / P5-M4): how many of this patient's dispenses carry no billing charge.
    uncharged_count: number;
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

            <!--
                QA-FIX.5a (P5-C1): the recorded allergies and the medication-safety seam, on the screen
                where the medication action happens. The SAME component the clinical chart renders, so
                the wording cannot drift. `always-show-seam` makes the seam statement appear even when
                no allergy is recorded — an empty list must never be readable as "checked and clear".
                Nothing here compares the list against the drug: that judgment is the certified
                partner's, and CareOS does not make it.
            -->
            <AllergyRecordPanel :allergies="allergies" :medication-safety="medicationSafety" always-show-seam />

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
                <!--
                    QA-FIX.5b (P5-C2 / P5-M4): an unbilled dispense is now visible where it happened.
                    Phase 5 found NOTHING in the product could find these rows, so the code comment's
                    promise that they were "reconcilable later" was unbacked. This states the count and
                    claims no reconciliation the product does not perform (D-170).
                -->
                <p v-if="uncharged_count > 0" class="mt-2 text-xs text-ink-muted">{{ t('pharmacy.dispensing.unchargedSummary', { count: uncharged_count }) }}</p>
                <ul v-if="history.length > 0" class="mt-3 divide-y divide-euca-100">
                    <li v-for="d in history" :key="d.id" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">
                            {{ d.name }} <span class="text-ink-muted">· ×{{ d.quantity }}</span>
                            <!-- A FACT from the ledger: this dispense has no charge row. It does not say
                                 WHY — an unpriced medication and a not-permitted actor both land here. -->
                            <span v-if="!d.charged" class="ml-2 rounded-full bg-surface-2 px-2 py-0.5 text-xs text-ink-muted">{{ t('pharmacy.dispensing.notBilled') }}</span>
                        </span>
                        <span class="text-xs text-ink-muted">{{ fmtTime(d.dispensed_at) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
