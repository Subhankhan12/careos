<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();

type Competency = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    enforcement: string;
    active: boolean;
};

type NurseGrant = { grant_id: string; competency_id: string; expires_at: string | null };

type Nurse = { id: string; name: string; competencies: NurseGrant[] };

const props = defineProps<{
    competencies: Competency[];
    nurses: Nurse[];
    enforcements: string[];
    actions: {
        storeUrl: string;
        updateUrl: string;
        grantUrl: string;
        revokeUrl: string;
        seedUrl: string;
    };
}>();

const createForm = useForm({ code: '', name: '', description: '', enforcement: 'hard' });
const grantSelection = reactive<Record<string, { competency_id: string; expires_at: string }>>({});

const competencyName = computed(() => {
    const map: Record<string, string> = {};
    props.competencies.forEach((c) => (map[c.id] = c.name));
    return map;
});

function create(): void {
    createForm.post(props.actions.storeUrl, {
        preserveScroll: true,
        onSuccess: () => createForm.reset('code', 'name', 'description'),
    });
}

function setEnforcement(competency: Competency, enforcement: string): void {
    useForm({ competency_id: competency.id, enforcement }).post(props.actions.updateUrl, { preserveScroll: true });
}

function toggleActive(competency: Competency): void {
    useForm({ competency_id: competency.id, active: !competency.active }).post(props.actions.updateUrl, {
        preserveScroll: true,
    });
}

function grant(nurse: Nurse): void {
    const selection = grantSelection[nurse.id];
    if (!selection?.competency_id) return;
    useForm({
        resource_id: nurse.id,
        competency_id: selection.competency_id,
        expires_at: selection.expires_at || null,
    }).post(props.actions.grantUrl, {
        preserveScroll: true,
        onSuccess: () => (grantSelection[nurse.id] = { competency_id: '', expires_at: '' }),
    });
}

function revoke(grantId: string): void {
    useForm({ grant_id: grantId }).post(props.actions.revokeUrl, { preserveScroll: true });
}

function seed(): void {
    useForm({}).post(props.actions.seedUrl, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('nursing.competencies.title')" />
        <div class="space-y-6">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div>
                    <h1 class="text-2xl font-semibold text-ink">{{ t('nursing.competencies.title') }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('nursing.competencies.subtitle') }}</p>
                </div>
                <Button type="button" variant="secondary" @click="seed">{{ t('nursing.competencies.seed') }}</Button>
            </div>

            <Card :title="t('nursing.competencies.add')">
                <form class="grid gap-3 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end" @submit.prevent="create">
                    <Input id="comp-code" v-model="createForm.code" :label="t('nursing.competencies.code')" />
                    <Input id="comp-name" v-model="createForm.name" :label="t('nursing.competencies.name')" />
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('nursing.competencies.enforcement') }}</span>
                        <select v-model="createForm.enforcement" class="rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option v-for="e in enforcements" :key="e" :value="e">{{ t('nursing.competencies.enforcementLabels.' + e) }}</option>
                        </select>
                    </label>
                    <div><Button type="submit" :disabled="!createForm.code || !createForm.name">{{ t('nursing.competencies.addButton') }}</Button></div>
                </form>
            </Card>

            <Card :title="t('nursing.competencies.defined')">
                <table class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('nursing.competencies.code') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('nursing.competencies.name') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('nursing.competencies.enforcement') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in competencies" :key="c.id" class="border-b border-line/60" :class="c.active ? '' : 'opacity-50'">
                            <td class="py-2 pr-4 text-ink">{{ c.code }}</td>
                            <td class="py-2 pr-4 text-ink">{{ c.name }}</td>
                            <td class="py-2 pr-4">
                                <select :value="c.enforcement" class="rounded-md border border-line bg-surface px-2 py-1 text-sm text-ink" @change="setEnforcement(c, ($event.target as HTMLSelectElement).value)">
                                    <option v-for="e in enforcements" :key="e" :value="e">{{ t('nursing.competencies.enforcementLabels.' + e) }}</option>
                                </select>
                            </td>
                            <td class="py-2 text-right">
                                <button type="button" class="text-sm font-medium text-ink-muted hover:opacity-80" @click="toggleActive(c)">
                                    {{ c.active ? t('nursing.competencies.deactivate') : t('nursing.competencies.reactivate') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>

            <Card :title="t('nursing.competencies.nurses')">
                <div class="space-y-4">
                    <div v-for="nurse in nurses" :key="nurse.id" class="rounded-md border border-line px-3 py-3">
                        <div class="font-medium text-ink">{{ nurse.name }}</div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span v-for="g in nurse.competencies" :key="g.grant_id" class="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-xs text-ink">
                                {{ competencyName[g.competency_id] ?? g.competency_id }}
                                <span v-if="g.expires_at" class="text-ink-muted">({{ t('nursing.competencies.expires') }} {{ g.expires_at }})</span>
                                <button type="button" class="text-danger hover:opacity-80" @click="revoke(g.grant_id)">×</button>
                            </span>
                            <span v-if="nurse.competencies.length === 0" class="text-sm text-ink-muted">{{ t('nursing.competencies.noneHeld') }}</span>
                        </div>
                        <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_180px_auto] sm:items-end">
                            <label class="block text-sm">
                                <span class="mb-1.5 block font-medium text-ink">{{ t('nursing.competencies.grant') }}</span>
                                <select v-model="(grantSelection[nurse.id] ??= { competency_id: '', expires_at: '' }).competency_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                    <option value="">{{ t('nursing.competencies.selectCompetency') }}</option>
                                    <option v-for="c in competencies.filter((x) => x.active)" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </label>
                            <Input :id="'exp-' + nurse.id" v-model="(grantSelection[nurse.id] ??= { competency_id: '', expires_at: '' }).expires_at" type="date" :label="t('nursing.competencies.expiryOptional')" />
                            <div>
                                <Button type="button" :disabled="!grantSelection[nurse.id]?.competency_id" @click="grant(nurse)">{{ t('nursing.competencies.grantButton') }}</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
