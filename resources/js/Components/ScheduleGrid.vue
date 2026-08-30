<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

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
        // APPT.P1 — the drill-in to the Appointment Detail page (optional so any other caller of this
        // grid keeps working unchanged).
        detail_url?: string;
        /*
         * SCHED.P1 — the actions the SERVER will accept for this appointment's true status,
         * derived from AppointmentService::boardActionsFor(). The grid renders exactly these and
         * decides nothing itself, so an offered action can never end in a refusal.
         *
         * Optional, and absent means NONE: a caller that does not supply it gets a read-only
         * grid rather than every button (fail-closed — the opposite of the old default).
         */
        actions?: string[];
        // A recorded fact: when reception checked this patient in. Null until they arrive.
        checked_in_at?: string | null;
        // Server-computed elapsed since check-in; see waitingMinutes() for why it is not derived here.
        waiting_minutes?: number | null;
    }>;
    /**
     * Per-lane booked-vs-available minutes. A plain ratio of two recorded quantities — never
     * tinted, never ranked, never used to order the lanes (D-169).
     */
    utilisation?: Record<string, { bookedMinutes: number; availableMinutes: number; percent: number | null }>;
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

/** Only what the server said it would accept. No list means no actions. */
function offers(appointment: { actions?: string[] }, action: string): boolean {
    return (appointment.actions ?? []).includes(action);
}

/**
 * Minutes since reception recorded the check-in — COMPUTED ON THE SERVER, from the stored UTC
 * instant, and simply displayed here.
 *
 * It is not computed in the browser because a naive timestamp is ambiguous twice over: the viewer
 * may be in any zone, and the tenant-timezone middleware re-labels the stored value. Browser
 * verification caught both (a check-in read as 0 minutes, then as 2 hours early).
 *
 * There is deliberately NO threshold, no band and no escalation: how long is too long is a
 * judgment nobody recorded (D-169).
 */
function waitingMinutes(appointment: { waiting_minutes?: number | null }): number | null {
    return appointment.waiting_minutes ?? null;
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
                        <!-- Booked vs available minutes, both recorded. Rendered in the ordinary
                             subtle colour like every other lane: no tint keyed to the value, no
                             ranking, no "busiest lane" (D-169). An honest dash when the branch has
                             no opening window to divide by. -->
                        <p v-if="utilisation && utilisation[resource.id]" class="text-xs text-ink-subtle">
                            {{
                                utilisation[resource.id].percent === null
                                    ? $t('scheduling.dayBoard.utilisationUnknown')
                                    : $t('scheduling.dayBoard.utilisation', {
                                          percent: utilisation[resource.id].percent,
                                          booked: utilisation[resource.id].bookedMinutes,
                                          available: utilisation[resource.id].availableMinutes,
                                      })
                            }}
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
                                <!-- APPT.P1 — drill into the appointment detail. A plain link; the
                                     tile's existing actions are untouched. -->
                                <component
                                    :is="appointment.detail_url ? Link : 'p'"
                                    :href="appointment.detail_url"
                                    class="block truncate font-semibold text-ink"
                                    :class="appointment.detail_url ? 'transition hover:text-euca-800 hover:underline' : ''"
                                >{{ appointment.patient ?? $t('scheduling.dayBoard.block') }}</component>
                                <p class="text-ink-muted">{{ time(appointment.starts_at) }}–{{ time(appointment.ends_at) }}</p>
                                <p class="truncate text-ink-muted">{{ appointment.service }}</p>
                                <!-- Elapsed since the RECORDED check-in. Plain text in the ordinary
                                     muted colour — no threshold, no amber, no "waiting too long". -->
                                <p v-if="waitingMinutes(appointment) !== null" class="text-ink-muted">
                                    {{ $t('scheduling.dayBoard.waitingSince', { minutes: waitingMinutes(appointment) }) }}
                                </p>
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
                            <button v-if="offers(appointment, 'arrive')" class="rounded-md border border-line bg-surface px-2 py-1 font-medium text-ink transition hover:bg-euca-50" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'arrive' })">
                                {{ $t('scheduling.actions.arrive') }}
                            </button>
                            <button v-if="offers(appointment, 'start')" class="rounded-md border border-line bg-surface px-2 py-1 font-medium text-ink transition hover:bg-euca-50" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'start' })">
                                {{ $t('scheduling.actions.start') }}
                            </button>
                            <button v-if="offers(appointment, 'complete')" class="rounded-md border border-line bg-surface px-2 py-1 font-medium text-ink transition hover:bg-euca-50" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'complete' })">
                                {{ $t('scheduling.actions.complete') }}
                            </button>
                            <button v-if="offers(appointment, 'cancel')" class="rounded-md border border-danger/40 bg-surface px-2 py-1 font-medium text-danger transition hover:bg-danger-soft" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'cancel' })">
                                {{ $t('scheduling.actions.cancel') }}
                            </button>
                            <button v-if="offers(appointment, 'no_show')" class="rounded-md border border-line bg-surface px-2 py-1 font-medium text-ink transition hover:bg-euca-50" type="button" @click="$emit('action', { appointmentId: appointment.id, action: 'no_show' })">
                                {{ $t('scheduling.actions.noShow') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
