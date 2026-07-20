<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();
const page = usePage();

interface Procedure {
    id: string;
    code: string;
    name: string;
    fee_minor: number;
    vat_rate_bp: number;
    active: boolean;
    tooth_scoped: boolean;
    update_url: string;
}

const props = defineProps<{
    procedures: Procedure[];
    currency: string;
    actions: { store_url: string; seed_url: string };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// Display-only unit conversions (minor <-> major, bp <-> %) — presentation, like the
// vitals unit helper. No money math reaches the server; PHP only ever sees integer minor.
function money(minor: number): string {
    return `${props.currency} ${(minor / 100).toFixed(2)}`;
}
function toMinor(major: string): number {
    return Math.round(Number(major || '0') * 100);
}
function toBp(pct: string): number {
    return Math.round(Number(pct || '0') * 100);
}

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
    editForm.fee = (p.fee_minor / 100).toFixed(2);
    editForm.vat = (p.vat_rate_bp / 100).toString();
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

            <!-- The catalog. -->
            <Card :title="t('feeSchedule.list.title')" :subtitle="t('feeSchedule.list.subtitle')">
                <div v-if="!procedures.length" class="space-y-3">
                    <p class="text-sm text-ink-muted">{{ t('feeSchedule.list.empty') }}</p>
                    <Button :block="false" @click="seed">{{ t('feeSchedule.seed') }}</Button>
                </div>
                <table v-else class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.code') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.name') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.fee') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.vat') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.toothScoped') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('feeSchedule.active') }}</th>
                            <th class="py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in procedures" :key="p.id">
                            <tr class="border-b border-line/60" :class="{ 'opacity-50': !p.active }">
                                <td class="py-2 pr-4 font-mono text-ink">{{ p.code }}</td>
                                <td class="py-2 pr-4 text-ink">{{ p.name }}</td>
                                <td class="py-2 pr-4 text-ink">{{ money(p.fee_minor) }}</td>
                                <td class="py-2 pr-4 text-ink-muted">{{ (p.vat_rate_bp / 100).toFixed(2) }}%</td>
                                <td class="py-2 pr-4 text-ink-muted">{{ p.tooth_scoped ? t('feeSchedule.yes') : t('feeSchedule.no') }}</td>
                                <td class="py-2 pr-4 text-ink-muted">{{ p.active ? t('feeSchedule.active') : t('feeSchedule.inactive') }}</td>
                                <td class="py-2 text-right">
                                    <button type="button" class="text-sm font-semibold text-euca-700 hover:text-euca-800" @click="startEdit(p)">{{ t('feeSchedule.edit') }}</button>
                                </td>
                            </tr>
                            <tr v-if="editingId === p.id">
                                <td colspan="7" class="pb-4">
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
        </div>
    </AppLayout>
</template>
