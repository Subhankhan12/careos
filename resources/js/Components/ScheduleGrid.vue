<script setup lang="ts">
const props = defineProps<{
    resources: Array<{ id: string; name: string; type: string }>;
    appointments: Array<{
        id: string;
        patient_id: string | null;
        patient: string | null;
        service: string | null;
        starts_at: string;
        ends_at: string;
        status: string;
        resource_ids: string[];
    }>;
}>();

defineEmits<{
    (e: 'action', payload: { appointmentId: string; action: string }): void;
    (e: 'open-encounter', payload: { appointmentId: string }): void;
}>();

const hours = ['08', '09', '10', '11', '12', '13', '14', '15', '16', '17'];

function time(value: string): string {
    return value.slice(11, 16);
}

function initials(name: string | null): string {
    if (!name) return '·';
    const parts = name.trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? (parts[parts.length - 1][0] ?? '') : '')).toUpperCase();
}

function laneCount(resourceId: string): number {
    return props.appointments.filter((a) => a.resource_ids.includes(resourceId)).length;
}

// Left-edge tint by WORKFLOW status (never clinical): booked → arrived → in-progress
// → completed → cancelled. Colors per the Eucalyptus Glow status-edge scale.
function edgeClass(status: string): string {
    return (
        {
            booked: 'border-l-euca-300',
            arrived: 'border-l-euca-500',
            in_progress: 'border-l-euca-700',
            completed: 'border-l-ink-subtle',
            cancelled: 'border-l-danger',
            no_show: 'border-l-danger',
        }[status] ?? 'border-l-euca-300'
    );
}
</script>

<template>
    <div class="glass-card overflow-x-auto p-0">
        <div class="grid min-w-[780px]" :style="{ gridTemplateColumns: `88px repeat(${Math.max(resources.length, 1)}, minmax(190px, 1fr))` }">
            <div class="border-b border-line/70 p-3 text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                {{ $t('scheduling.dayBoard.time') }}
            </div>
            <div v-for="resource in resources" :key="resource.id" class="border-b border-l border-line/70 bg-euca-50/60 p-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-euca-200 text-xs font-semibold text-euca-900">
                        {{ initials(resource.name) }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-ink">{{ resource.name }}</p>
                        <p class="text-xs text-ink-subtle">
                            {{ resource.type }} · {{ $t('scheduling.dayBoard.today', { count: laneCount(resource.id) }) }}
                        </p>
                    </div>
                </div>
            </div>

            <template v-for="hour in hours" :key="hour">
                <div class="border-b border-line/60 p-3 text-sm font-medium text-ink-subtle">{{ hour }}:00</div>
                <div
                    v-for="resource in resources"
                    :key="`${hour}-${resource.id}`"
                    class="min-h-20 space-y-2 border-b border-l border-line/60 p-2"
                >
                    <div
                        v-for="appointment in appointments.filter((item) => item.resource_ids.includes(resource.id) && item.starts_at.slice(11, 13) === hour)"
                        :key="appointment.id"
                        class="rounded-lg border border-l-4 border-line bg-surface-2 p-2.5 text-xs shadow-sm"
                        :class="edgeClass(appointment.status)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-ink">{{ appointment.patient ?? $t('scheduling.dayBoard.block') }}</p>
                                <p class="text-ink-muted">{{ time(appointment.starts_at) }}–{{ time(appointment.ends_at) }}</p>
                                <p class="truncate text-ink-muted">{{ appointment.service }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-euca-50 px-2 py-0.5 text-[11px] font-semibold text-euca-800">
                                {{ appointment.status }}
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <button
                                v-if="appointment.patient_id"
                                class="rounded-md bg-euca-700 px-2 py-1 font-semibold text-white transition hover:bg-euca-800"
                                type="button"
                                @click="$emit('open-encounter', { appointmentId: appointment.id })"
                            >
                                {{ $t('scheduling.actions.document') }}
                            </button>
                            <button class="rounded-md border border-line bg-surface px-2 py-1 font-medium text-ink transition hover:bg-euca-50" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'arrive' })">
                                {{ $t('scheduling.actions.arrive') }}
                            </button>
                            <button class="rounded-md border border-line bg-surface px-2 py-1 font-medium text-ink transition hover:bg-euca-50" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'start' })">
                                {{ $t('scheduling.actions.start') }}
                            </button>
                            <button class="rounded-md border border-line bg-surface px-2 py-1 font-medium text-ink transition hover:bg-euca-50" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'complete' })">
                                {{ $t('scheduling.actions.complete') }}
                            </button>
                            <button class="rounded-md border border-danger/40 bg-surface px-2 py-1 font-medium text-danger transition hover:bg-danger-soft" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'cancel' })">
                                {{ $t('scheduling.actions.cancel') }}
                            </button>
                            <button class="rounded-md border border-line bg-surface px-2 py-1 font-medium text-ink transition hover:bg-euca-50" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'no_show' })">
                                {{ $t('scheduling.actions.noShow') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
