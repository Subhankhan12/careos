<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// A case's supplies (SURGERY.G4) — PRESENTATIONAL. Record consumables used + implants placed (with
// lot/serial/UDI for traceability); using an item decrements stock. Record-not-judge: implant traceability
// is a record (which implant -> which patient), never a device-safety verdict.
const { t } = useI18n();

type Item = { id: string; code: string; name: string; is_implant: boolean };
type Usage = { id: string; item: string | null; quantity: number; used_at: string };
type Implant = { id: string; item: string | null; lot_number: string; serial_number: string | null; udi: string | null; placed_at: string };

const props = defineProps<{
    surgicalCase: { id: string; patient: string; procedure: string; case_url: string };
    usages: Usage[];
    implants: Implant[];
    patient_implants: Implant[];
    items: Item[];
    actions: { can_record: boolean; use_url: string; implant_url: string };
}>();

const useForm = reactive({ surgical_item_id: '', quantity: '1' });
const implantForm = reactive({ surgical_item_id: '', lot_number: '', serial_number: '', udi: '', note: '' });

const implantItems = computed(() => props.items.filter((i) => i.is_implant));

function recordUse(): void {
    router.post(props.actions.use_url, { ...useForm }, { preserveScroll: true, onSuccess: () => Object.assign(useForm, { surgical_item_id: '', quantity: '1' }) });
}
function placeImplant(): void {
    router.post(props.actions.implant_url, { ...implantForm }, { preserveScroll: true, onSuccess: () => Object.assign(implantForm, { surgical_item_id: '', lot_number: '', serial_number: '', udi: '', note: '' }) });
}
function fmt(iso: string): string {
    return iso ? iso.replace('T', ' ').slice(0, 16) : '';
}
</script>

<template>
    <AppLayout>
        <Head :title="t('surgery.supplies.title')" />
        <div class="space-y-5">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('surgery.supplies.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ surgicalCase.patient }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ surgicalCase.procedure }}</p>
                <Link :href="surgicalCase.case_url" class="mt-3 inline-block text-xs font-semibold text-euca-100 underline">{{ t('surgery.supplies.backToCase') }}</Link>
            </div>

            <!-- Record consumable use + implant placement -->
            <div v-if="actions.can_record" class="grid gap-5 lg:grid-cols-2">
                <form class="glass-card space-y-3 p-6" @submit.prevent="recordUse">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.supplies.recordUse') }}</h2>
                    <select v-model="useForm.surgical_item_id" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">—</option>
                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.name }}</option>
                    </select>
                    <input v-model="useForm.quantity" type="number" min="1" :placeholder="t('surgery.supplies.quantity')" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.supplies.use') }}</button>
                </form>
                <form class="glass-card space-y-3 p-6" @submit.prevent="placeImplant">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.supplies.placeImplant') }}</h2>
                    <p class="text-xs text-ink-muted">{{ t('surgery.supplies.implantHint') }}</p>
                    <select v-model="implantForm.surgical_item_id" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">—</option>
                        <option v-for="i in implantItems" :key="i.id" :value="i.id">{{ i.name }}</option>
                    </select>
                    <input v-model="implantForm.lot_number" :placeholder="t('surgery.supplies.lot')" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <input v-model="implantForm.serial_number" :placeholder="t('surgery.supplies.serial')" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <input v-model="implantForm.udi" :placeholder="t('surgery.supplies.udi')" class="w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.supplies.place') }}</button>
                </form>
            </div>

            <!-- Consumables used -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.supplies.used') }}</h2>
                <ul class="mt-3 divide-y divide-euca-100">
                    <li v-for="u in usages" :key="u.id" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ u.item }} <span class="text-ink-muted">· ×{{ u.quantity }}</span></span>
                        <span class="text-xs text-ink-muted">{{ fmt(u.used_at) }}</span>
                    </li>
                    <li v-if="usages.length === 0" class="py-2 text-sm text-ink-muted">{{ t('surgery.supplies.noUsage') }}</li>
                </ul>
            </div>

            <!-- Implants placed in this case -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.supplies.implantsPlaced') }}</h2>
                <ul class="mt-3 divide-y divide-euca-100">
                    <li v-for="p in implants" :key="p.id" class="py-2 text-sm">
                        <span class="font-semibold text-ink">{{ p.item }}</span>
                        <span class="text-ink-muted"> · {{ t('surgery.supplies.lot') }} {{ p.lot_number }}<template v-if="p.serial_number"> · {{ t('surgery.supplies.serial') }} {{ p.serial_number }}</template><template v-if="p.udi"> · UDI {{ p.udi }}</template> · {{ fmt(p.placed_at) }}</span>
                    </li>
                    <li v-if="implants.length === 0" class="py-2 text-sm text-ink-muted">{{ t('surgery.supplies.noImplants') }}</li>
                </ul>
            </div>

            <!-- Patient's implant history (across cases) -->
            <div v-if="patient_implants.length" class="glass-card p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('surgery.supplies.patientHistory') }}</h2>
                <ul class="mt-3 divide-y divide-euca-100">
                    <li v-for="p in patient_implants" :key="p.id" class="py-2 text-sm">
                        <span class="text-ink">{{ p.item }}</span>
                        <span class="text-ink-muted"> · {{ t('surgery.supplies.lot') }} {{ p.lot_number }}<template v-if="p.udi"> · UDI {{ p.udi }}</template> · {{ fmt(p.placed_at) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
