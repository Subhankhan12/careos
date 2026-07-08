<script setup lang="ts">
defineProps<{
    resources: Array<{ id: string; name: string; type: string }>;
    appointments: Array<{
        id: string;
        patient: string | null;
        service: string | null;
        starts_at: string;
        ends_at: string;
        status: string;
        resource_ids: string[];
    }>;
}>();

defineEmits<{ (e: 'action', payload: { appointmentId: string; action: string }): void }>();

function time(value: string): string {
    return value.slice(11, 16);
}
</script>

<template>
    <div class="overflow-x-auto rounded-md border border-line bg-surface">
        <div class="grid min-w-[760px]" :style="{ gridTemplateColumns: `160px repeat(${Math.max(resources.length, 1)}, minmax(180px, 1fr))` }">
            <div class="border-b border-line bg-surface-muted p-3 text-xs font-semibold uppercase text-ink-subtle">
                {{ $t('scheduling.dayBoard.time') }}
            </div>
            <div
                v-for="resource in resources"
                :key="resource.id"
                class="border-b border-l border-line bg-surface-muted p-3"
            >
                <p class="text-sm font-semibold text-ink">{{ resource.name }}</p>
                <p class="text-xs text-ink-muted">{{ resource.type }}</p>
            </div>

            <template v-for="hour in ['08', '09', '10', '11', '12', '13', '14', '15', '16', '17']" :key="hour">
                <div class="border-b border-line p-3 text-sm font-medium text-ink-muted">{{ hour }}:00</div>
                <div
                    v-for="resource in resources"
                    :key="`${hour}-${resource.id}`"
                    class="min-h-24 space-y-2 border-b border-l border-line p-2"
                >
                    <div
                        v-for="appointment in appointments.filter((item) => item.resource_ids.includes(resource.id) && item.starts_at.slice(11, 13) === hour)"
                        :key="appointment.id"
                        class="rounded-md border border-brand-200 bg-brand-50 p-2 text-xs"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="font-semibold text-brand-950">{{ appointment.patient ?? $t('scheduling.dayBoard.block') }}</p>
                                <p class="text-brand-800">{{ time(appointment.starts_at) }}-{{ time(appointment.ends_at) }}</p>
                                <p class="text-brand-800">{{ appointment.service }}</p>
                            </div>
                            <span class="rounded bg-surface px-2 py-0.5 text-[11px] font-semibold text-ink-muted">{{ appointment.status }}</span>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-1">
                            <button class="rounded border border-line bg-surface px-2 py-1 font-semibold text-ink hover:bg-surface-muted" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'arrive' })">
                                {{ $t('scheduling.actions.arrive') }}
                            </button>
                            <button class="rounded border border-line bg-surface px-2 py-1 font-semibold text-ink hover:bg-surface-muted" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'start' })">
                                {{ $t('scheduling.actions.start') }}
                            </button>
                            <button class="rounded border border-line bg-surface px-2 py-1 font-semibold text-ink hover:bg-surface-muted" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'complete' })">
                                {{ $t('scheduling.actions.complete') }}
                            </button>
                            <button class="rounded border border-danger/40 bg-surface px-2 py-1 font-semibold text-danger hover:bg-surface-muted" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'cancel' })">
                                {{ $t('scheduling.actions.cancel') }}
                            </button>
                            <button class="rounded border border-line bg-surface px-2 py-1 font-semibold text-ink hover:bg-surface-muted" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'no_show' })">
                                {{ $t('scheduling.actions.noShow') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
