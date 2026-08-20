<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import ClinicalStatTile from '@/Components/Dental/ClinicalStatTile.vue';

const { t } = useI18n();
const page = usePage();

interface Procedure {
    id: string;
    code: string;
    name: string;
    fee_minor: number;
    vat_rate_bp: number;
    /** ENGINE/CATALOG-supplied display strings — the page formats nothing. */
    fee: string;
    fee_input: string;
    vat: string;
    vat_input: string;
    active: boolean;
    tooth_scoped: boolean;
    scope: 'tooth' | 'general';
    update_url: string;
}

const props = defineProps<{
    procedures: Procedure[];
    summary: { positions: number; active: number; tooth_scoped: number };
    currency: string;
    actions: { store_url: string; seed_url: string };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

/*
 * INPUT-BOUNDARY unit conversion, and the ONLY arithmetic left on this surface.
 *
 * The dentist types a fee in major units ("150.00") and a VAT rate in percent; the existing
 * endpoint takes integer minor units and basis points, and its contract is unchanged by this
 * gate. So these convert what the USER TYPED on its way to the server — they never derive,
 * total, average or re-price anything. Every fee DISPLAYED on this page is a string the
 * server produced; nothing is computed from a stored value in the browser.
 */
function toMinor(major: string): number {
    return Math.round(Number(major || '0') * 100);
}
function toBp(pct: string): number {
    return Math.round(Number(pct || '0') * 100);
}

// Search is a plain text match over the tenant's own codes and names — no ranking, no score.
const search = ref('');
const filtered = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (needle === '') return props.procedures;
    return props.procedures.filter((p) => `${p.code} ${p.name}`.toLowerCase().includes(needle));
});

/*
 * Grouped by a REAL attribute of the tenant's own row — whether the procedure is charged per
 * tooth or once. The wireframe groups by a category taxonomy that has NO backend field, and
 * that taxonomy was NOT invented here (see the notes at the foot of the page).
 */
const groups = computed(() => [
    { key: 'tooth', items: filtered.value.filter((p) => p.scope === 'tooth') },
    { key: 'general', items: filtered.value.filter((p) => p.scope === 'general') },
]);

const createForm = useForm({ code: '', name: '', fee: '', vat: '', tooth_scoped: false });
function submitCreate(): void {
    createForm
        .transform((d) => ({ code: d.code, name: d.name, fee_minor: toMinor(d.fee), vat_rate_bp: toBp(d.vat), tooth_scoped: d.tooth_scoped }))
        .post(props.actions.store_url, { preserveScroll: true, onSuccess: () => createForm.reset() });
}

const editingId = ref<string | null>(null);
const editForm = useForm({ name: '', fee: '', vat: '', tooth_scoped: false, active: true });
function startEdit(p: Procedure): void {
    editingId.value = p.id;
    editForm.name = p.name;
    // The server supplied both edit values already — no conversion here.
    editForm.fee = p.fee_input;
    editForm.vat = p.vat_input;
    editForm.tooth_scoped = p.tooth_scoped;
    editForm.active = p.active;
    editForm.clearErrors();
}
function submitEdit(p: Procedure): void {
    editForm
        .transform((d) => ({ name: d.name, fee_minor: toMinor(d.fee), vat_rate_bp: toBp(d.vat), tooth_scoped: d.tooth_scoped, active: d.active }))
        .post(p.update_url, { preserveScroll: true, onSuccess: () => (editingId.value = null) });
}
function seed(): void {
    router.post(props.actions.seed_url, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('feeSchedule.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('feeSchedule.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('feeSchedule.title') }}</h1>
                <p class="mt-1 max-w-2xl text-sm text-ink-muted">{{ t('feeSchedule.subtitle') }}</p>
            </div>

            <p v-if="flash" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">{{ t(`feeSchedule.flash.${flash}`) }}</p>

            <!-- Factual counts of the tenant's OWN catalog rows, counted server-side. -->
            <div v-if="procedures.length" class="grid gap-3 sm:grid-cols-3">
                <ClinicalStatTile :label="t('feeSchedule.summary.positions')" :value="String(summary.positions)" />
                <ClinicalStatTile :label="t('feeSchedule.summary.active')" :value="String(summary.active)" />
                <ClinicalStatTile :label="t('feeSchedule.summary.toothScoped')" :value="String(summary.tooth_scoped)" />
            </div>

            <!-- The catalog. -->
            <Card :title="t('feeSchedule.list.title')" :subtitle="t('feeSchedule.list.subtitle')">
                <div v-if="!procedures.length" class="space-y-3">
                    <p class="text-sm text-ink-muted">{{ t('feeSchedule.list.empty') }}</p>
                    <Button :block="false" @click="seed">{{ t('feeSchedule.seed') }}</Button>
                </div>

                <template v-else>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label class="flex-1 sm:max-w-xs">
                            <span class="sr-only">{{ t('feeSchedule.search') }}</span>
                            <input
                                v-model="search"
                                type="search"
                                :placeholder="t('feeSchedule.search')"
                                class="w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink"
                            />
                        </label>
                        <p class="text-xs text-ink-subtle">{{ t('feeSchedule.showing', { shown: filtered.length, total: procedures.length }) }}</p>
                    </div>

                    <p v-if="!filtered.length" class="mt-4 text-sm text-ink-muted">{{ t('feeSchedule.noMatch') }}</p>

                    <div v-for="group in groups" :key="group.key" class="mt-5">
                        <template v-if="group.items.length">
                            <p class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-subtle">{{ t(`feeSchedule.groups.${group.key}`) }}</p>

                            <table class="mt-2 w-full text-left text-sm">
                                <thead class="text-ink-muted">
                                    <tr class="border-b border-line">
                                        <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.code') }}</th>
                                        <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.name') }}</th>
                                        <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.fee') }}</th>
                                        <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.vat') }}</th>
                                        <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.status') }}</th>
                                        <th class="py-2 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="p in group.items" :key="p.id">
                                        <tr class="border-b border-line/60">
                                            <td class="py-2 pr-4 font-mono text-ink">{{ p.code }}</td>
                                            <td class="py-2 pr-4 text-ink">{{ p.name }}</td>
                                            <!-- Server-formatted; the page never divides a stored fee. -->
                                            <td class="py-2 pr-4 text-ink">{{ p.fee }}</td>
                                            <td class="py-2 pr-4 text-ink-muted">{{ p.vat }}</td>
                                            <td class="py-2 pr-4">
                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                                    :class="p.active ? 'bg-euca-50 text-euca-700' : 'bg-surface-2 text-ink-subtle'"
                                                >
                                                    {{ p.active ? t('feeSchedule.active') : t('feeSchedule.retired') }}
                                                </span>
                                            </td>
                                            <td class="py-2 text-right">
                                                <button type="button" class="text-sm font-semibold text-euca-700 hover:text-euca-800" @click="startEdit(p)">{{ t('feeSchedule.edit') }}</button>
                                            </td>
                                        </tr>
                                        <tr v-if="editingId === p.id">
                                            <td colspan="6" class="pb-4">
                                                <form class="grid gap-3 rounded-2xl border border-line p-4 sm:grid-cols-2" @submit.prevent="submitEdit(p)">
                                                    <Input :id="`e-name-${p.id}`" v-model="editForm.name" :label="t('feeSchedule.name')" :error="editForm.errors.name" />
                                                    <Input :id="`e-fee-${p.id}`" v-model="editForm.fee" :label="t('feeSchedule.feeInput', { currency })" />
                                                    <Input :id="`e-vat-${p.id}`" v-model="editForm.vat" :label="t('feeSchedule.vatInput')" />
                                                    <label class="flex items-center gap-2 text-sm text-ink"><input v-model="editForm.tooth_scoped" type="checkbox" class="rounded border-line text-euca-700" />{{ t('feeSchedule.toothScoped') }}</label>
                                                    <label class="flex items-center gap-2 text-sm text-ink"><input v-model="editForm.active" type="checkbox" class="rounded border-line text-euca-700" />{{ t('feeSchedule.active') }}</label>
                                                    <div class="flex items-center gap-2 sm:col-span-2">
                                                        <Button type="submit" :block="false" :disabled="editForm.processing">{{ t('feeSchedule.save') }}</Button>
                                                        <button type="button" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink hover:bg-euca-50" @click="editingId = null">{{ t('feeSchedule.cancel') }}</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </template>
                    </div>
                </template>
            </Card>

            <!-- Add a procedure (tenant-authored — the dentist's own code + fee). -->
            <Card :title="t('feeSchedule.new.title')" :subtitle="t('feeSchedule.new.subtitle')">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitCreate">
                    <Input id="c-code" v-model="createForm.code" :label="t('feeSchedule.code')" :error="createForm.errors.code" :placeholder="t('feeSchedule.codePlaceholder')" />
                    <Input id="c-name" v-model="createForm.name" :label="t('feeSchedule.name')" :error="createForm.errors.name" />
                    <Input id="c-fee" v-model="createForm.fee" :label="t('feeSchedule.feeInput', { currency })" :error="createForm.errors.fee_minor" />
                    <Input id="c-vat" v-model="createForm.vat" :label="t('feeSchedule.vatInput')" />
                    <label class="flex items-center gap-2 text-sm text-ink sm:col-span-2"><input v-model="createForm.tooth_scoped" type="checkbox" class="rounded border-line text-euca-700" />{{ t('feeSchedule.toothScopedHint') }}</label>
                    <div class="sm:col-span-2">
                        <Button type="submit" :block="false" :disabled="createForm.processing">{{ t('feeSchedule.new.submit') }}</Button>
                    </div>
                </form>
            </Card>

            <!-- What this schedule deliberately does NOT carry, stated on the page rather than
                 quietly missing: a licensed code set, and effective-dated versioning. -->
            <div class="rounded-2xl border border-line bg-surface-2/60 p-4">
                <p class="text-[0.65rem] font-semibold uppercase tracking-[0.12em] text-ink-subtle">{{ t('feeSchedule.notes.title') }}</p>
                <ul class="mt-2 space-y-1.5 text-xs text-ink-muted">
                    <li>{{ t('feeSchedule.notes.tenantAuthored') }}</li>
                    <li>{{ t('feeSchedule.notes.noLicensedCodes') }}</li>
                    <li>{{ t('feeSchedule.notes.noVersioning') }}</li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
