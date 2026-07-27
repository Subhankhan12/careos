<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Pharmacy pricing (PHARMACY.G5) — PRESENTATIONAL. Set a med's price as a tenant-authored tariff item (the
// existing tariff store). Prices are integer minor units server-side; the input is major units, converted on
// send (a display convenience — the billing ENGINE owns all money math). A med price is a RATE, not a verdict.
const { t, locale } = useI18n();

type Item = { id: string; code: string; name: string; strength: string | null; price_minor: number | null; unit: string | null; set_url: string };

const props = defineProps<{ items: Item[] }>();

const forms = reactive<Record<string, { price: string; unit: string }>>({});

function formFor(item: Item): { price: string; unit: string } {
    if (!forms[item.id]) {
        forms[item.id] = { price: item.price_minor !== null ? (item.price_minor / 100).toFixed(2) : '', unit: item.unit ?? '' };
    }
    return forms[item.id];
}

function setPrice(item: Item): void {
    const f = formFor(item);
    const priceMinor = Math.round(parseFloat(f.price) * 100);
    router.post(item.set_url, { price_minor: priceMinor, unit: f.unit || null }, { preserveScroll: true });
}

function fmtPrice(minor: number | null): string {
    if (minor === null) return '—';
    return (minor / 100).toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('pharmacy.pricing.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('pharmacy.pricing.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('pharmacy.pricing.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('pharmacy.pricing.subtitle') }}</p>
            </div>

            <!-- Priced formulary -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('pharmacy.pricing.heading') }}</h2>
                <p v-if="items.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('pharmacy.pricing.empty') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-100">
                    <li v-for="item in items" :key="item.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">
                                {{ item.name }}
                                <span v-if="item.strength" class="text-ink-muted">· {{ item.strength }}</span>
                            </p>
                            <p class="text-xs text-ink-muted">{{ item.code }} · {{ t('pharmacy.pricing.current', { price: fmtPrice(item.price_minor) }) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <input v-model="formFor(item).price" type="number" step="0.01" min="0" :placeholder="t('pharmacy.pricing.price')" class="w-28 rounded-xl border border-euca-200 bg-white/70 px-3 py-1 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                            <input v-model="formFor(item).unit" :placeholder="t('pharmacy.pricing.unit')" class="w-24 rounded-xl border border-euca-200 bg-white/70 px-3 py-1 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                            <button type="button" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700" @click="setPrice(item)">{{ t('pharmacy.pricing.set') }}</button>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
