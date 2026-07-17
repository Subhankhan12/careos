<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();
const page = usePage();

type Visit = {
    id: string;
    patient: string;
    window_start_at: string;
    window_end_at: string;
    duration_minutes: number;
    required_qualification: string | null;
    required_competencies?: string[];
    status: string;
    assigned_resource_id: string | null;
};

type NurseLane = {
    resource: {
        id: string;
        name: string;
        qualification: string | null;
        max_hours_per_week: string | null;
    };
    visits: Visit[];
};

const props = defineProps<{
    filters: { date: string; branch_id: string };
    branches: Array<{ id: string; name: string }>;
    unassignedVisits: Visit[];
    nurseLanes: NurseLane[];
    actions: { assignUrl: string; unassignUrl: string };
}>();

const filters = reactive({ ...props.filters });
const selections = reactive<Record<string, string>>({});

const assignmentError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.assignment ?? null;
});

// Non-blocking soft-competency advisories from the last assignment. The dispatcher
// was allowed to proceed; this only surfaces what they proceeded past.
const assignmentWarnings = computed(() => {
    const flash = page.props.flash as { assignmentWarnings?: string[] } | undefined;

    return flash?.assignmentWarnings ?? null;
});

function reload(): void {
    router.get('/nursing/dispatch', filters, { preserveState: true, replace: true });
}

function assign(visitId: string): void {
    router.post(props.actions.assignUrl, {
        planned_visit_id: visitId,
        resource_id: selections[visitId],
    }, { preserveScroll: true });
}

function unassign(visitId: string): void {
    router.post(props.actions.unassignUrl, {
        planned_visit_id: visitId,
    }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('nursing.dispatch.title')" />

        <div class="space-y-6">
            <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                <div>
                    <h1 class="text-2xl font-semibold text-ink">{{ t('nursing.dispatch.title') }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('nursing.dispatch.subtitle') }}</p>
                </div>
                <form class="grid gap-3 sm:grid-cols-[180px_220px_120px]" @submit.prevent="reload">
                    <Input id="dispatch-date" v-model="filters.date" type="date" :label="t('nursing.dispatch.fields.date')" />
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('nursing.dispatch.fields.branch') }}</span>
                        <select v-model="filters.branch_id" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </label>
                    <div class="flex items-end">
                        <Button type="submit">{{ t('nursing.dispatch.refresh') }}</Button>
                    </div>
                </form>
            </div>

            <div v-if="assignmentError" class="rounded-md border border-danger/30 bg-danger/10 px-4 py-3 text-sm text-danger">
                {{ assignmentError }}
            </div>

            <div v-if="assignmentWarnings" class="rounded-md border border-amber-400/40 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                <div class="font-medium">{{ t('nursing.dispatch.softWarningTitle') }}</div>
                <ul class="mt-1 list-inside list-disc">
                    <li v-for="warning in assignmentWarnings" :key="warning">{{ warning }}</li>
                </ul>
            </div>

            <div class="grid gap-6 xl:grid-cols-[minmax(280px,360px)_1fr]">
                <Card :title="t('nursing.dispatch.unassigned')">
                    <div class="space-y-3">
                        <div v-if="unassignedVisits.length === 0" class="text-sm text-ink-muted">{{ t('nursing.dispatch.empty') }}</div>
                        <div v-for="visit in unassignedVisits" :key="visit.id" class="rounded-md border border-line bg-surface px-3 py-3">
                            <div class="font-medium text-ink">{{ visit.patient }}</div>
                            <div class="mt-1 text-sm text-ink-muted">
                                {{ visit.window_start_at }} - {{ visit.window_end_at }}
                            </div>
                            <div class="mt-1 text-xs text-ink-muted">
                                {{ t('nursing.dispatch.requiredQualification') }}: {{ visit.required_qualification ?? t('nursing.dispatch.none') }}
                            </div>
                            <div v-if="visit.required_competencies && visit.required_competencies.length" class="mt-1 text-xs text-ink-muted">
                                {{ t('nursing.dispatch.requiredCompetencies') }}: {{ visit.required_competencies.join(', ') }}
                            </div>
                            <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_auto]">
                                <select v-model="selections[visit.id]" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30">
                                    <option value="">{{ t('nursing.dispatch.selectNurse') }}</option>
                                    <option v-for="lane in nurseLanes" :key="lane.resource.id" :value="lane.resource.id">
                                        {{ lane.resource.name }}
                                    </option>
                                </select>
                                <Button type="button" :disabled="!selections[visit.id]" @click="assign(visit.id)">
                                    {{ t('nursing.dispatch.assign') }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </Card>

                <div class="grid gap-4 lg:grid-cols-2">
                    <Card v-for="lane in nurseLanes" :key="lane.resource.id" :title="lane.resource.name" :subtitle="lane.resource.qualification ?? t('nursing.dispatch.noConstraint')">
                        <div class="space-y-3">
                            <div v-if="lane.visits.length === 0" class="text-sm text-ink-muted">{{ t('nursing.dispatch.emptyLane') }}</div>
                            <div v-for="visit in lane.visits" :key="visit.id" class="rounded-md border border-line bg-surface px-3 py-3">
                                <div class="font-medium text-ink">{{ visit.patient }}</div>
                                <div class="mt-1 text-sm text-ink-muted">
                                    {{ visit.window_start_at }} - {{ visit.window_end_at }}
                                </div>
                                <div class="mt-3 max-w-36">
                                    <Button type="button" variant="secondary" @click="unassign(visit.id)">
                                        {{ t('nursing.dispatch.unassign') }}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
