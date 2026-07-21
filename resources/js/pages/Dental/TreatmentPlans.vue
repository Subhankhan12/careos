<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import DentalSectionNav from '@/Components/DentalSectionNav.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();
const page = usePage();

interface Item {
    id: string;
    code: string | null;
    name: string | null;
    tooth: string | null;
    surface: string | null;
    estimate_minor: number;
    done: boolean;
    perform_url: string;
}
interface Phase {
    id: string;
    name: string;
    total_minor: number;
    items: Item[];
}
interface Plan {
    id: string;
    title: string | null;
    status: string;
    accepted_at: string | null;
    total_minor: number;
    phases: Phase[];
    phase_url: string;
    item_url: string;
    transition_url: string;
}

const props = defineProps<{
    patient: { id: string; mrn: string; name: string };
    plans: Plan[];
    procedures: Array<{ id: string; code: string | null; name: string | null; tooth_scoped: boolean }>;
    branches: Array<{ id: string; name: string }>;
    currency: string;
    actions: { can_manage: boolean; can_perform: boolean; store_url: string };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

function money(minor: number): string {
    return `${props.currency} ${(minor / 100).toFixed(2)}`;
}

// Which lifecycle actions are legal from a given status (mirrors the server state machine; the
// server stays authoritative — this only hides buttons that would be rejected).
function actionsFor(status: string): string[] {
    return (
        {
            draft: ['propose'],
            proposed: ['accept', 'decline'],
            accepted: ['start'],
            in_progress: ['complete'],
        } as Record<string, string[]>
    )[status] ?? [];
}

const createForm = useForm({ title: '' });
function createPlan(): void {
    createForm.post(props.actions.store_url, { preserveScroll: true, onSuccess: () => createForm.reset() });
}

const phaseName = reactive<Record<string, string>>({});
function addPhase(plan: Plan): void {
    router.post(plan.phase_url, { name: phaseName[plan.id] ?? '' }, { preserveScroll: true, preserveState: true, onSuccess: () => (phaseName[plan.id] = '') });
}

const itemForm = reactive<Record<string, { treatment_plan_phase_id: string; dental_procedure_id: string; tooth: string; surface: string }>>({});
function itemFor(planId: string) {
    if (!itemForm[planId]) itemForm[planId] = { treatment_plan_phase_id: '', dental_procedure_id: '', tooth: '', surface: '' };
    return itemForm[planId];
}
function addItem(plan: Plan): void {
    router.post(plan.item_url, { ...itemFor(plan.id) }, { preserveScroll: true, preserveState: true, onSuccess: () => (itemForm[plan.id] = { treatment_plan_phase_id: '', dental_procedure_id: '', tooth: '', surface: '' }) });
}

function transition(plan: Plan, action: string): void {
    router.post(plan.transition_url, { action }, { preserveScroll: true, preserveState: true });
}

const performingItemId = ref<string | null>(null);
const performForm = reactive({ branch_id: props.branches.length === 1 ? props.branches[0].id : '', note: '' });
function startPerform(item: Item): void {
    performingItemId.value = item.id;
    performForm.branch_id = props.branches.length === 1 ? props.branches[0].id : '';
    performForm.note = '';
}
function confirmPerform(item: Item): void {
    router.post(item.perform_url, { branch_id: performForm.branch_id, note: performForm.note }, { preserveScroll: true, preserveState: true, onSuccess: () => (performingItemId.value = null) });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('treatmentPlan.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('treatmentPlan.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('treatmentPlan.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ patient.name }} · <span class="font-mono">{{ patient.mrn }}</span></p>
                <p class="mt-1 max-w-2xl text-sm text-ink-subtle">{{ t('treatmentPlan.subtitle') }}</p>
            </div>

            <DentalSectionNav :patient-id="patient.id" active="plans" />

            <p v-if="flash" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">{{ t(`treatmentPlan.flash.${flash}`) }}</p>

            <!-- New plan (dentist-authored). -->
            <Card v-if="actions.can_manage" :title="t('treatmentPlan.newPlan.title')" :subtitle="t('treatmentPlan.fenceNote')">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="createPlan">
                    <div class="flex-1"><Input id="plan-title" v-model="createForm.title" :label="t('treatmentPlan.newPlan.placeholder')" /></div>
                    <Button type="submit" :block="false" :disabled="createForm.processing">{{ t('treatmentPlan.newPlan.submit') }}</Button>
                </form>
            </Card>

            <p v-if="!plans.length" class="rounded-2xl border border-line bg-surface p-6 text-sm text-ink-muted">{{ t('treatmentPlan.empty') }}</p>

            <!-- Plans. -->
            <Card v-for="plan in plans" :key="plan.id">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-ink">{{ plan.title ?? t('treatmentPlan.title') }}</span>
                            <span class="inline-flex items-center rounded-full bg-euca-50 px-2.5 py-0.5 text-xs font-semibold text-euca-700">{{ t(`treatmentPlan.status.${plan.status}`) }}</span>
                        </div>
                        <p class="mt-0.5 text-sm text-ink-muted">{{ t('treatmentPlan.total') }}: <span class="font-semibold text-ink">{{ money(plan.total_minor) }}</span></p>
                    </div>
                    <div v-if="actions.can_manage" class="flex flex-wrap items-center gap-2">
                        <button v-for="a in actionsFor(plan.status)" :key="a" type="button" class="rounded-xl border border-line px-3 py-1.5 text-sm font-semibold text-ink hover:bg-euca-50" @click="transition(plan, a)">{{ t(`treatmentPlan.lifecycle.${a}`) }}</button>
                    </div>
                </div>

                <!-- Phases + items. -->
                <div class="mt-4 space-y-4">
                    <div v-for="phase in plan.phases" :key="phase.id" class="rounded-2xl border border-line p-4">
                        <div class="flex items-center justify-between">
                            <p class="font-semibold text-ink">{{ phase.name }}</p>
                            <p class="text-sm text-ink-muted">{{ t('treatmentPlan.phaseTotal') }}: {{ money(phase.total_minor) }}</p>
                        </div>
                        <table v-if="phase.items.length" class="mt-2 w-full text-left text-sm">
                            <tbody>
                                <tr v-for="item in phase.items" :key="item.id" class="border-t border-line/60">
                                    <td class="py-2 pr-3 font-mono text-ink-muted">{{ item.code }}</td>
                                    <td class="py-2 pr-3 text-ink">{{ item.name }}<span v-if="item.tooth" class="text-ink-subtle"> · {{ item.tooth }}</span></td>
                                    <td class="py-2 pr-3 text-ink">{{ money(item.estimate_minor) }}</td>
                                    <td class="py-2 text-right">
                                        <span v-if="item.done" class="inline-flex items-center rounded-full bg-success-soft px-2 py-0.5 text-xs font-semibold text-success">{{ t('treatmentPlan.item.done') }}</span>
                                        <template v-else-if="actions.can_perform && (plan.status === 'accepted' || plan.status === 'in_progress')">
                                            <template v-if="performingItemId === item.id">
                                                <div class="mt-2 flex flex-wrap items-center justify-end gap-2">
                                                    <select v-model="performForm.branch_id" class="rounded-md border border-line bg-surface px-2 py-1 text-xs text-ink">
                                                        <option value="" disabled>{{ t('treatmentPlan.perform.branch') }}</option>
                                                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                                    </select>
                                                    <button type="button" class="btn-glow rounded-lg px-3 py-1 text-xs font-semibold" :disabled="performForm.branch_id === ''" @click="confirmPerform(item)">{{ t('treatmentPlan.perform.submit') }}</button>
                                                    <button type="button" class="rounded-lg border border-line px-3 py-1 text-xs font-semibold text-ink" @click="performingItemId = null">✕</button>
                                                </div>
                                            </template>
                                            <button v-else type="button" class="text-sm font-semibold text-euca-700 hover:text-euca-800" @click="startPerform(item)">{{ t('treatmentPlan.item.perform') }}</button>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Build the plan (draft only). -->
                <div v-if="actions.can_manage && plan.status === 'draft'" class="mt-4 space-y-3 border-t border-line pt-4">
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="addPhase(plan)">
                        <div class="flex-1"><Input :id="`phase-${plan.id}`" v-model="phaseName[plan.id]" :label="t('treatmentPlan.phase.name')" /></div>
                        <Button type="submit" :block="false">{{ t('treatmentPlan.phase.submit') }}</Button>
                    </form>
                    <form v-if="plan.phases.length" class="grid gap-2 sm:grid-cols-5" @submit.prevent="addItem(plan)">
                        <select v-model="itemFor(plan.id).treatment_plan_phase_id" class="rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink">
                            <option value="" disabled>{{ t('treatmentPlan.item.choosePhase') }}</option>
                            <option v-for="ph in plan.phases" :key="ph.id" :value="ph.id">{{ ph.name }}</option>
                        </select>
                        <select v-model="itemFor(plan.id).dental_procedure_id" class="rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink sm:col-span-2">
                            <option value="" disabled>{{ t('treatmentPlan.item.chooseProcedure') }}</option>
                            <option v-for="p in procedures" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                        </select>
                        <input v-model="itemFor(plan.id).tooth" type="text" :placeholder="t('treatmentPlan.item.tooth')" class="rounded-md border border-line bg-surface px-2 py-2 text-sm text-ink" />
                        <Button type="submit" :block="false">{{ t('treatmentPlan.item.submit') }}</Button>
                    </form>
                    <p v-if="!procedures.length" class="text-xs text-ink-subtle">{{ t('treatmentPlan.noProcedures') }}</p>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
