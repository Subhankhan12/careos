<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Pharmacy inventory (PHARMACY.G4) — PRESENTATIONAL. Stock (on-hand, below-threshold shown FACTUALLY),
// receive/adjust, and the append-only movement log. Operational facts only: "below threshold" is a plain
// on-hand-vs-threshold comparison, never a graded alert.
const { t, locale } = useI18n();

type Stock = { id: string; name: string; on_hand: number; unit: string; reorder_threshold: number | null; below_threshold: boolean; adjust_url: string };
type Movement = { id: string; name: string; type: string; quantity_change: number; resulting_on_hand: number; reason: string | null; occurred_at: string };
type FormularyOption = { id: string; name: string; strength: string | null };

const props = defineProps<{
    stock: Stock[];
    movements: Movement[];
    formulary: FormularyOption[];
    actions: { receive_url: string };
}>();

const receiveForm = reactive({ formulary_item_id: '', quantity: '', unit: '', reorder_threshold: '' });
const adjustForms = reactive<Record<string, { on_hand: string; reason: string }>>({});

function adjustFor(id: string): { on_hand: string; reason: string } {
    if (!adjustForms[id]) {
        adjustForms[id] = { on_hand: '', reason: '' };
    }
    return adjustForms[id];
}

function receive(): void {
    router.post(props.actions.receive_url, { ...receiveForm }, {
        preserveScroll: true,
        onSuccess: () => Object.assign(receiveForm, { formulary_item_id: '', quantity: '', unit: '', reorder_threshold: '' }),
    });
}

function adjust(row: Stock): void {
    const f = adjustFor(row.id);
    router.post(row.adjust_url, { on_hand: f.on_hand, reason: f.reason }, {
        preserveScroll: true,
        onSuccess: () => Object.assign(f, { on_hand: '', reason: '' }),
    });
}

function fmtTime(iso: string): string {
    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
}
</script>

<template>
    <AppLayout>
        <Head :title="t('pharmacy.inventory.title')" />
        <div class="space-y-5">
            <!-- Header tile -->
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('pharmacy.inventory.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('pharmacy.inventory.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('pharmacy.inventory.subtitle') }}</p>
            </div>

            <!-- Receive stock -->
            <form class="glass-card grid gap-3 p-6 sm:grid-cols-2 xl:grid-cols-5" @submit.prevent="receive">
                <div class="sm:col-span-2">
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="r-item">{{ t('pharmacy.inventory.medication') }}</label>
                    <select id="r-item" v-model="receiveForm.formulary_item_id" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">—</option>
                        <option v-for="item in formulary" :key="item.id" :value="item.id">{{ item.name }}<template v-if="item.strength"> · {{ item.strength }}</template></option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="r-qty">{{ t('pharmacy.inventory.quantity') }}</label>
                    <input id="r-qty" v-model="receiveForm.quantity" type="number" min="1" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="r-unit">{{ t('pharmacy.inventory.unit') }}</label>
                    <input id="r-unit" v-model="receiveForm.unit" :placeholder="t('pharmacy.inventory.unitPlaceholder')" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('pharmacy.inventory.receive') }}</button>
                </div>
            </form>

            <!-- Stock -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('pharmacy.inventory.stockHeading') }}</h2>
                <p v-if="stock.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('pharmacy.inventory.noStock') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-100">
                    <li v-for="row in stock" :key="row.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">
                                {{ row.name }}
                                <span class="text-ink-muted">· {{ row.on_hand }} {{ row.unit }}</span>
                                <span v-if="row.below_threshold" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">{{ t('pharmacy.inventory.belowThreshold') }}</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <input v-model="adjustFor(row.id).on_hand" type="number" min="0" :placeholder="t('pharmacy.inventory.newCount')" class="w-24 rounded-xl border border-euca-200 bg-white/70 px-2 py-1 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                            <input v-model="adjustFor(row.id).reason" :placeholder="t('pharmacy.inventory.reason')" class="w-40 rounded-xl border border-euca-200 bg-white/70 px-2 py-1 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                            <button type="button" class="rounded-full bg-white/50 px-3 py-1 text-xs font-semibold text-ink transition hover:bg-white/70" @click="adjust(row)">{{ t('pharmacy.inventory.adjust') }}</button>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Movement log -->
            <div class="glass-card p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('pharmacy.inventory.movements') }}</h2>
                <p v-if="movements.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('pharmacy.inventory.noMovements') }}</p>
                <ul v-else class="mt-3 divide-y divide-euca-100">
                    <li v-for="m in movements" :key="m.id" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ m.name }} <span class="text-ink-muted">· {{ t(`pharmacy.inventory.types.${m.type}`) }}</span></span>
                        <span class="flex items-center gap-3 text-ink-muted">
                            <span class="font-semibold" :class="m.quantity_change < 0 ? 'text-ink' : 'text-euca-700'">{{ m.quantity_change > 0 ? '+' : '' }}{{ m.quantity_change }}</span>
                            <span>→ {{ m.resulting_on_hand }}</span>
                            <span class="text-xs">{{ fmtTime(m.occurred_at) }}</span>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
