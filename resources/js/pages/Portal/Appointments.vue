<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

type Slot = { starts_at: string; ends_at: string; resource_ids: string[] };
type AppointmentRow = { id: string; service: string | null; starts_at: string; ends_at: string; status: string; checked_in?: boolean; can_check_in?: boolean };
type Contact = { phone: string | null; email: string | null; address: { line1: string | null; line2: string | null; city: string | null; postal: string | null; country: string | null } };

const props = defineProps<{
    upcoming: AppointmentRow[];
    past: AppointmentRow[];
    services: Array<{ id: string; name: string; duration: number }>;
    branches: Array<{ id: string; name: string }>;
    cancelMinHours: number;
    contact: Contact;
    actions: { slotsUrl: string; storeUrl: string; cancelUrl: string; checkInUrl: string; updateContactUrl: string };
}>();

const cancelingId = ref<string | null>(null);

const contactForm = reactive<Contact>({
    phone: props.contact.phone,
    email: props.contact.email,
    address: { ...props.contact.address },
});

// --- date/time helpers (client-side formatting of existing props) ------------
function parse(value: string): Date | null {
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
}
function fmt(d: Date, opts: Intl.DateTimeFormatOptions): string {
    try {
        return new Intl.DateTimeFormat(locale.value, opts).format(d);
    } catch {
        return '';
    }
}
function badge(value: string): { weekday: string; day: string; month: string } {
    const d = parse(value);
    if (!d) return { weekday: '', day: value, month: '' };
    return {
        weekday: fmt(d, { weekday: 'short' }).toUpperCase(),
        day: fmt(d, { day: 'numeric' }),
        month: fmt(d, { month: 'short' }),
    };
}
function timeRange(s: string, e: string): string {
    const ds = parse(s);
    const de = parse(e);
    const t1 = ds ? fmt(ds, { hour: '2-digit', minute: '2-digit' }) : s;
    const t2 = de ? fmt(de, { hour: '2-digit', minute: '2-digit' }) : e;
    return `${t1} – ${t2}`;
}
function slotTime(value: string): string {
    const d = parse(value);
    return d ? fmt(d, { hour: '2-digit', minute: '2-digit' }) : value;
}
function relative(value: string): string {
    const d = parse(value);
    if (!d) return '';
    const startOfDay = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const days = Math.round((startOfDay(d) - startOfDay(new Date())) / 86_400_000);
    if (days === 0) return t('portal.appointments.today');
    if (days === 1) return t('portal.appointments.tomorrow');
    if (days > 1) return t('portal.appointments.inDays', { count: days });
    return '';
}
function withinWindow(value: string): boolean {
    const d = parse(value);
    if (!d) return false;
    return (d.getTime() - Date.now()) / 3_600_000 < props.cancelMinHours;
}
function statusClass(status: string): string {
    return status === 'booked' || status === 'confirmed' ? 'bg-euca-100 text-euca-800' : 'bg-surface-2 text-ink-muted';
}

// --- actions (unchanged contract) --------------------------------------------
function checkIn(appointment: AppointmentRow): void {
    router.post(props.actions.checkInUrl, { appointment_id: appointment.id }, { preserveScroll: true });
}
function cancel(appointment: AppointmentRow): void {
    router.post(props.actions.cancelUrl, { appointment_id: appointment.id, reason: t('portal.appointments.cancelReason') });
}
function saveContact(): void {
    router.post(
        props.actions.updateContactUrl,
        { phone: contactForm.phone, email: contactForm.email, address: contactForm.address },
        { preserveScroll: true },
    );
}

// --- booking (select-then-confirm) -------------------------------------------
const book = reactive({
    service_id: props.services[0]?.id ?? '',
    branch_id: props.branches[0]?.id ?? '',
    date: '',
});
const slots = ref<Slot[]>([]);
const searched = ref(false);
const selectedSlot = ref<Slot | null>(null);

const morningSlots = computed(() => slots.value.filter((s) => (parse(s.starts_at)?.getHours() ?? 0) < 12));
const afternoonSlots = computed(() => slots.value.filter((s) => (parse(s.starts_at)?.getHours() ?? 0) >= 12));
const selectedService = computed(() => props.services.find((s) => s.id === book.service_id));
const selectedBranch = computed(() => props.branches.find((b) => b.id === book.branch_id));

async function findSlots(): Promise<void> {
    selectedSlot.value = null;
    const response = await fetch(props.actions.slotsUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
        },
        body: JSON.stringify(book),
    });
    slots.value = response.ok ? (await response.json()).slots : [];
    searched.value = true;
}

function confirmBooking(): void {
    if (!selectedSlot.value) return;
    router.post(props.actions.storeUrl, {
        service_id: book.service_id,
        branch_id: book.branch_id,
        starts_at: selectedSlot.value.starts_at,
        resource_ids: selectedSlot.value.resource_ids,
    });
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.nav.appointments')" />

        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('portal.appointments.eyebrow') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{{ t('portal.nav.appointments') }}</h1>
        <p class="mt-1 text-ink-muted">{{ t('portal.appointments.subtitle') }}</p>

        <div class="mt-6 grid gap-5 lg:grid-cols-2">
            <!-- Upcoming + Past -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold text-ink">{{ t('portal.appointments.upcoming') }}</h2>

                <ul v-if="upcoming.length" class="mt-4 space-y-3">
                    <li
                        v-for="(appointment, index) in upcoming"
                        :key="appointment.id"
                        class="rounded-xl border p-4"
                        :class="index === 0 ? 'border-euca-200 bg-euca-50/60' : 'border-line'"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-xl text-center leading-none"
                                :class="index === 0 ? 'euca-tile-dark text-euca-50' : 'bg-euca-100 text-euca-900'"
                            >
                                <span class="text-[10px] font-semibold tracking-wide">{{ badge(appointment.starts_at).weekday }}</span>
                                <span class="text-lg font-semibold">{{ badge(appointment.starts_at).day }}</span>
                                <span class="text-[10px]">{{ badge(appointment.starts_at).month }}</span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-semibold text-ink">{{ timeRange(appointment.starts_at, appointment.ends_at) }}</p>
                                    <span class="text-sm text-ink-subtle">{{ relative(appointment.starts_at) }}</span>
                                </div>
                                <p class="text-sm text-ink-muted">{{ appointment.service ?? '—' }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusClass(appointment.status)">
                                        {{ appointment.status }}
                                    </span>
                                    <span v-if="appointment.checked_in" class="text-xs font-medium text-euca-700">
                                        · {{ t('portal.checkIn.checkedIn') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Cancel / check-in / within-window notice -->
                        <div v-if="cancelingId !== appointment.id" class="mt-3 flex flex-wrap items-center gap-2">
                            <button
                                v-if="appointment.can_check_in"
                                type="button"
                                class="btn-glow inline-flex items-center rounded-xl px-4 py-2 text-sm font-semibold"
                                @click="checkIn(appointment)"
                            >
                                {{ t('portal.checkIn.action') }}
                            </button>
                            <button
                                v-if="!withinWindow(appointment.starts_at)"
                                type="button"
                                class="text-sm font-semibold text-danger transition hover:opacity-80"
                                @click="cancelingId = appointment.id"
                            >
                                {{ t('portal.appointments.cancel') }}
                            </button>
                            <span v-else class="text-xs text-ink-subtle">
                                {{ t('portal.appointments.withinWindow', { hours: cancelMinHours }) }}
                            </span>
                        </div>

                        <div v-else class="mt-3 rounded-xl border border-danger/30 bg-danger-soft p-3">
                            <p class="text-sm text-ink">{{ t('portal.appointments.cancelConfirm') }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="rounded-xl border border-line bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                                    @click="cancelingId = null"
                                >
                                    {{ t('portal.appointments.keepIt') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-xl bg-danger px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                                    @click="cancel(appointment)"
                                >
                                    {{ t('portal.appointments.confirmCancel') }}
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
                <p v-else class="mt-4 text-sm text-ink-muted">{{ t('portal.appointments.empty') }}</p>

                <p class="mt-4 text-xs text-ink-subtle">{{ t('portal.appointments.cancelHint', { hours: cancelMinHours }) }}</p>

                <h2 class="mb-3 mt-8 text-lg font-semibold text-ink">{{ t('portal.appointments.past') }}</h2>
                <ul v-if="past.length" class="divide-y divide-line/70">
                    <li v-for="appointment in past" :key="appointment.id" class="flex items-center justify-between gap-3 py-3 text-sm">
                        <span class="text-ink">{{ appointment.service ?? '—' }} · {{ timeRange(appointment.starts_at, appointment.ends_at) }}</span>
                        <span class="rounded-full bg-surface-2 px-2.5 py-0.5 text-xs font-medium text-ink-muted">{{ appointment.status }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-ink-muted">{{ t('portal.appointments.empty') }}</p>
            </div>

            <!-- Book -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold text-ink">{{ t('portal.appointments.book') }}</h2>
                <p class="mt-1 text-sm text-ink-muted">{{ t('portal.appointments.bookingIntro') }}</p>

                <div class="mt-4 space-y-4">
                    <label class="block text-sm font-medium text-ink">
                        {{ t('portal.appointments.service') }}
                        <select v-model="book.service_id" class="mt-1.5 block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30">
                            <option v-for="service in services" :key="service.id" :value="service.id">{{ service.name }}</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-ink">
                        {{ t('portal.appointments.location') }}
                        <select v-model="book.branch_id" class="mt-1.5 block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30">
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-ink">
                        {{ t('portal.appointments.date') }}
                        <input v-model="book.date" type="date" class="mt-1.5 block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                    </label>
                    <button type="button" class="btn-glow inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold" @click="findSlots">
                        {{ t('portal.appointments.findSlots') }}
                    </button>
                </div>

                <div v-if="slots.length" class="mt-5 space-y-4">
                    <div v-if="morningSlots.length">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('portal.appointments.morning') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="slot in morningSlots"
                                :key="slot.starts_at"
                                type="button"
                                class="rounded-xl px-3.5 py-2 text-sm font-semibold transition"
                                :class="selectedSlot?.starts_at === slot.starts_at ? 'btn-glow' : 'border border-line bg-surface-2 text-ink hover:border-euca-400'"
                                @click="selectedSlot = slot"
                            >
                                {{ slotTime(slot.starts_at) }}
                            </button>
                        </div>
                    </div>
                    <div v-if="afternoonSlots.length">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t('portal.appointments.afternoon') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="slot in afternoonSlots"
                                :key="slot.starts_at"
                                type="button"
                                class="rounded-xl px-3.5 py-2 text-sm font-semibold transition"
                                :class="selectedSlot?.starts_at === slot.starts_at ? 'btn-glow' : 'border border-line bg-surface-2 text-ink hover:border-euca-400'"
                                @click="selectedSlot = slot"
                            >
                                {{ slotTime(slot.starts_at) }}
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-ink-subtle">{{ t('portal.appointments.slotsCapNote') }}</p>

                    <div v-if="selectedSlot" class="rounded-xl border border-line bg-surface-2 p-3 text-sm text-ink">
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="h-4 w-4 text-euca-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M5 12.5l4 4 10-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            {{ selectedService?.name }} · {{ slotTime(selectedSlot.starts_at) }} · {{ selectedBranch?.name }}
                        </span>
                    </div>
                    <button
                        type="button"
                        class="btn-glow inline-flex w-full items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!selectedSlot"
                        @click="confirmBooking"
                    >
                        {{ t('portal.appointments.confirmBooking') }}
                    </button>
                </div>
                <p v-else-if="searched" class="mt-5 text-sm text-ink-muted">{{ t('portal.appointments.slotsEmpty') }}</p>
            </div>
        </div>

        <!-- Contact details -->
        <div class="glass-card mt-5 p-6">
            <h2 class="text-lg font-semibold text-ink">{{ t('portal.checkIn.contactTitle') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ t('portal.checkIn.contactHint') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block text-sm font-medium text-ink">{{ t('portal.checkIn.phone') }}
                    <input v-model="contactForm.phone" type="tel" class="mt-1.5 block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                </label>
                <label class="block text-sm font-medium text-ink">{{ t('portal.checkIn.email') }}
                    <input v-model="contactForm.email" type="email" class="mt-1.5 block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                </label>
                <label class="block text-sm font-medium text-ink">{{ t('portal.checkIn.addressLine') }}
                    <input v-model="contactForm.address.line1" type="text" class="mt-1.5 block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                </label>
                <label class="block text-sm font-medium text-ink">{{ t('portal.checkIn.city') }}
                    <input v-model="contactForm.address.city" type="text" class="mt-1.5 block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                </label>
                <label class="block text-sm font-medium text-ink">{{ t('portal.checkIn.postal') }}
                    <input v-model="contactForm.address.postal" type="text" class="mt-1.5 block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30" />
                </label>
            </div>
            <button type="button" class="btn-glow mt-4 inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-semibold" @click="saveContact">
                {{ t('portal.checkIn.saveContact') }}
            </button>
        </div>
    </PortalLayout>
</template>
