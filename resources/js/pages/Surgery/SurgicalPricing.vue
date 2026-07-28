<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Surgical pricing (SURGERY.G5) — PRESENTATIONAL. Set tenant-authored prices in the EXISTING tariff store for
// surgical items, theatre time, and procedures. Prices are integer minor units server-side; the input is
// major units, converted on send (a display convenience — the billing ENGINE owns all money math). A price
// is a RATE, not a verdict.
const { t, locale } = useI18n();

type Item = { id: string; code: string; name: string; is_implant: boolean; price_minor: number | null; set_url: string };
type Procedure = { code: string; name: string; price_minor: number };

const props = defineProps<{
    items: Item[];
    procedures: Procedure[];
    theatre_time: { price_minor: number | null; unit: string | null };
    actions: { procedure_url: string; theatre_time_url: string };
}>();

const itemForms = reactive<Record<string, string>>({});
const procForm = reactive({ code: '', name: '', price: '' });
const theatreForm = reactive({ price: props.theatre_time.price_minor !== null ? (props.theatre_time.price_minor / 100).toFixed(2) : '', unit: props.theatre_time.unit ?? 'theatre-minute' });

function itemPrice(item: Item): string {
    if (itemForms[item.id] === undefined) {
        itemForms[item.id] = item.price_minor !== null ? (item.price_minor / 100).toFixed(2) : '';
    }
    return itemForms[item.id];
}
function setItem(item: Item): void {
    router.post(item.set_url, { price_minor: Math.round(parseFloat(itemForms[item.id]) * 100) }, { preserveScroll: true });
}
function setProcedure(): void {
    router.post(props.actions.procedure_url, { code: procForm.code, name: procForm.name, price_minor: Math.round(parseFloat(procForm.price) * 100) }, { preserveScroll: true, onSuccess: () => Object.assign(procForm, { code: '', name: '', price: '' }) });
}
function setTheatreTime(): void {
    router.post(props.actions.theatre_time_url, { price_minor: Math.round(parseFloat(theatreForm.price) * 100), unit: theatreForm.unit }, { preserveScroll: true });
}
function fmt(minor: number | null): string {
    return minor === null ? '—' : (minor / 100).toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('surgery.pricing.title')" />
        <div class="space-y-5">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('surgery.pricing.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('surgery.pricing.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('surgery.pricing.subtitle') }}</p>
            </div>

            <!-- Theatre time -->
            <form class="glass-card space-y-3 p-6" @submit.prevent="setTheatreTime">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.pricing.theatreTime') }}</h2>
                <div class="flex flex-wrap items-end gap-2">
                    <input v-model="theatreForm.price" type="number" step="0.01" min="0" :placeholder="t('surgery.pricing.price')" class="w-32 rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <input v-model="theatreForm.unit" :placeholder="t('surgery.pricing.unit')" class="w-40 rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.pricing.set') }}</button>
                </div>
            </form>

            <!-- Procedures -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.pricing.procedures') }}</h2>
                <ul v-if="procedures.length" class="mt-3 divide-y divide-euca-100">
                    <li v-for="p in procedures" :key="p.code" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ p.name }} <span class="text-ink-muted">· {{ p.code }}</span></span>
                        <span class="font-semibold text-ink">{{ fmt(p.price_minor) }}</span>
                    </li>
                </ul>
                <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="setProcedure">
                    <input v-model="procForm.code" :placeholder="t('surgery.pricing.code')" class="w-32 rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <input v-model="procForm.name" :placeholder="t('surgery.pricing.name')" class="w-52 rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <input v-model="procForm.price" type="number" step="0.01" min="0" :placeholder="t('surgery.pricing.price')" class="w-32 rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.pricing.addProcedure') }}</button>
                </form>
            </div>

            <!-- Surgical items -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.pricing.items') }}</h2>
                <p v-if="items.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('surgery.pricing.noItems') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-100">
                    <li v-for="item in items" :key="item.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-ink">{{ item.name }}<span v-if="item.is_implant" class="ml-1 rounded-full bg-euca-100 px-2 py-0.5 text-xs font-semibold text-euca-800">{{ t('surgery.pricing.implant') }}</span></p>
                            <p class="text-xs text-ink-muted">{{ item.code }} · {{ t('surgery.pricing.current', { price: fmt(item.price_minor) }) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <input v-model="itemForms[item.id]" :value="itemPrice(item)" type="number" step="0.01" min="0" :placeholder="t('surgery.pricing.price')" class="w-28 rounded-xl border border-euca-200 bg-white/70 px-3 py-1 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                            <button type="button" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700" @click="setItem(item)">{{ t('surgery.pricing.set') }}</button>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
