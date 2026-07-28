<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Surgical inventory (SURGERY.G4) — PRESENTATIONAL. The item catalog + stock (below-threshold factual) +
// receive/adjust + the lot/UDI recall lookup. Operational: "below stock" is a factual count; the recall
// lookup returns records (which patients received a lot/UDI), never a device-safety verdict.
const { t } = useI18n();

type Stock = { id: string; item: string | null; code: string | null; is_implant: boolean; on_hand: number; unit: string; below_threshold: boolean; adjust_url: string };
type Item = { id: string; code: string; name: string; is_implant: boolean };
type Movement = { id: string; item: string | null; type: string; quantity_change: number; resulting_on_hand: number; occurred_at: string };
type RecallRow = { patient: string; item: string | null; lot_number: string; serial_number: string | null; udi: string | null; placed_at: string };

const props = defineProps<{
    stock: Stock[];
    items: Item[];
    movements: Movement[];
    recall: { query: string; results: RecallRow[] };
    actions: { can_manage: boolean; item_url: string; receive_url: string; recall_url: string };
}>();

const itemForm = reactive({ code: '', name: '', is_implant: false, unit: '' });
const receiveForm = reactive({ surgical_item_id: '', quantity: '', reorder_threshold: '' });
const lot = ref(props.recall.query);

function createItem(): void {
    router.post(props.actions.item_url, { ...itemForm }, { preserveScroll: true, onSuccess: () => Object.assign(itemForm, { code: '', name: '', is_implant: false, unit: '' }) });
}
function receive(): void {
    router.post(props.actions.receive_url, { ...receiveForm }, { preserveScroll: true, onSuccess: () => Object.assign(receiveForm, { surgical_item_id: '', quantity: '', reorder_threshold: '' }) });
}
function adjust(s: Stock): void {
    const value = window.prompt(t('surgery.inventory.adjustPrompt', { item: s.item ?? '' }));
    if (value === null) return;
    const reason = window.prompt(t('surgery.inventory.adjustReason')) ?? '';
    router.post(s.adjust_url, { on_hand: Number(value), reason }, { preserveScroll: true });
}
function search(): void {
    router.get(props.actions.recall_url, { lot: lot.value }, { preserveScroll: true, preserveState: true });
}
function fmt(iso: string): string {
    return iso ? iso.replace('T', ' ').slice(0, 16) : '';
}
</script>

<template>
    <AppLayout>
        <Head :title="t('surgery.inventory.title')" />
        <div class="space-y-5">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('surgery.inventory.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('surgery.inventory.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('surgery.inventory.subtitle') }}</p>
            </div>

            <!-- Recall lookup (lot / UDI -> patients) -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.inventory.recall') }}</h2>
                <p class="mt-1 text-xs text-ink-muted">{{ t('surgery.inventory.recallHint') }}</p>
                <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="search">
                    <input v-model="lot" :placeholder="t('surgery.inventory.lotOrUdi')" class="w-64 rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.inventory.search') }}</button>
                </form>
                <ul v-if="recall.results.length" class="mt-3 divide-y divide-euca-100">
                    <li v-for="(r, i) in recall.results" :key="i" class="py-2 text-sm">
                        <span class="font-semibold text-ink">{{ r.patient }}</span>
                        <span class="text-ink-muted"> · {{ r.item }} · {{ t('surgery.inventory.lot') }} {{ r.lot_number }}<template v-if="r.udi"> · UDI {{ r.udi }}</template> · {{ fmt(r.placed_at) }}</span>
                    </li>
                </ul>
                <p v-else-if="recall.query" class="mt-3 text-sm text-ink-muted">{{ t('surgery.inventory.noMatches') }}</p>
            </div>

            <!-- Author an item + receive stock -->
            <div v-if="actions.can_manage" class="grid gap-5 lg:grid-cols-2">
                <form class="glass-card space-y-3 p-6" @submit.prevent="createItem">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.inventory.newItem') }}</h2>
                    <input v-model="itemForm.code" :placeholder="t('surgery.inventory.code')" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <input v-model="itemForm.name" :placeholder="t('surgery.inventory.name')" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <label class="flex items-center gap-2 text-sm text-ink"><input v-model="itemForm.is_implant" type="checkbox" class="rounded border-euca-300" /> {{ t('surgery.inventory.isImplant') }}</label>
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.inventory.addItem') }}</button>
                </form>
                <form class="glass-card space-y-3 p-6" @submit.prevent="receive">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.inventory.receiveStock') }}</h2>
                    <select v-model="receiveForm.surgical_item_id" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">—</option>
                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.name }}<template v-if="i.is_implant"> · {{ t('surgery.inventory.implant') }}</template></option>
                    </select>
                    <input v-model="receiveForm.quantity" type="number" min="1" :placeholder="t('surgery.inventory.quantity')" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <input v-model="receiveForm.reorder_threshold" type="number" min="0" :placeholder="t('surgery.inventory.reorderThreshold')" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.inventory.receive') }}</button>
                </form>
            </div>

            <!-- Stock list -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.inventory.stock') }}</h2>
                <p v-if="stock.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('surgery.inventory.noStock') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-100">
                    <li v-for="s in stock" :key="s.id" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ s.item }} <span class="text-ink-muted">· {{ s.code }}</span><span v-if="s.is_implant" class="ml-1 rounded-full bg-euca-100 px-2 py-0.5 text-xs font-semibold text-euca-800">{{ t('surgery.inventory.implant') }}</span></span>
                        <span class="flex items-center gap-2">
                            <span :class="s.below_threshold ? 'text-amber-700 font-semibold' : 'text-ink'">{{ s.on_hand }} {{ s.unit }}</span>
                            <span v-if="s.below_threshold" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">{{ t('surgery.inventory.belowThreshold') }}</span>
                            <button v-if="actions.can_manage" type="button" class="rounded-full bg-white/50 px-3 py-1 text-xs font-semibold text-ink transition hover:bg-white/70" @click="adjust(s)">{{ t('surgery.inventory.adjust') }}</button>
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Movement log -->
            <div v-if="movements.length" class="glass-card p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('surgery.inventory.movements') }}</h2>
                <ul class="mt-3 divide-y divide-euca-100">
                    <li v-for="m in movements" :key="m.id" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ m.item }} <span class="text-ink-muted">· {{ t(`surgery.inventory.movementType.${m.type}`) }}</span></span>
                        <span class="text-xs text-ink-muted">{{ m.quantity_change > 0 ? '+' : '' }}{{ m.quantity_change }} → {{ m.resulting_on_hand }} · {{ fmt(m.occurred_at) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
