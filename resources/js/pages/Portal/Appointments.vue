<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

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

const contactForm = reactive<Contact>({
    phone: props.contact.phone,
    email: props.contact.email,
    address: { ...props.contact.address },
});

function checkIn(appointment: AppointmentRow): void {
    router.post(props.actions.checkInUrl, { appointment_id: appointment.id }, { preserveScroll: true });
}

function saveContact(): void {
    router.post(props.actions.updateContactUrl, {
        phone: contactForm.phone,
        email: contactForm.email,
        address: contactForm.address,
    }, { preserveScroll: true });
}

const book = reactive({
    service_id: props.services[0]?.id ?? '',
    branch_id: props.branches[0]?.id ?? '',
    date: '',
});
const slots = ref<Slot[]>([]);

async function findSlots(): Promise<void> {
    const response = await fetch(props.actions.slotsUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
        },
        body: JSON.stringify(book),
    });

    slots.value = response.ok ? (await response.json()).slots : [];
}

function bookSlot(slot: Slot): void {
    router.post(props.actions.storeUrl, {
        service_id: book.service_id,
        branch_id: book.branch_id,
        starts_at: slot.starts_at,
        resource_ids: slot.resource_ids,
    });
}

function cancel(appointment: AppointmentRow): void {
    router.post(props.actions.cancelUrl, {
        appointment_id: appointment.id,
        reason: t('portal.appointments.cancelReason'),
    });
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.nav.appointments')" />

        <div class="grid gap-4 md:grid-cols-2">
            <Card>
                <h2 class="mb-2 font-semibold">{{ t('portal.appointments.upcoming') }}</h2>
                <p class="mb-2 text-xs text-gray-500">
                    {{ t('portal.appointments.cancelHint', { hours: cancelMinHours }) }}
                </p>
                <ul v-if="upcoming.length" class="divide-y">
                    <li v-for="appointment in upcoming" :key="appointment.id" class="flex items-center justify-between py-2 text-sm">
                        <span>
                            {{ appointment.service ?? '—' }} · {{ appointment.starts_at }}
                            <span v-if="appointment.checked_in" class="ml-1 text-brand-600">· {{ t('portal.checkIn.checkedIn') }}</span>
                        </span>
                        <span class="flex gap-2">
                            <Button v-if="appointment.can_check_in" type="button" @click="checkIn(appointment)">
                                {{ t('portal.checkIn.action') }}
                            </Button>
                            <Button type="button" variant="secondary" @click="cancel(appointment)">
                                {{ t('portal.appointments.cancel') }}
                            </Button>
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-500">{{ t('portal.appointments.empty') }}</p>

                <h2 class="mb-2 mt-6 font-semibold">{{ t('portal.appointments.past') }}</h2>
                <ul v-if="past.length" class="divide-y">
                    <li v-for="appointment in past" :key="appointment.id" class="py-2 text-sm">
                        {{ appointment.service ?? '—' }} · {{ appointment.starts_at }} · {{ appointment.status }}
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-500">{{ t('portal.appointments.empty') }}</p>
            </Card>

            <Card>
                <h2 class="mb-2 font-semibold">{{ t('portal.appointments.book') }}</h2>
                <div class="space-y-2">
                    <label class="block text-sm">
                        {{ t('portal.appointments.service') }}
                        <select v-model="book.service_id" class="mt-1 w-full rounded border px-2 py-1 text-sm">
                            <option v-for="service in services" :key="service.id" :value="service.id">
                                {{ service.name }}
                            </option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        {{ t('portal.appointments.branch') }}
                        <select v-model="book.branch_id" class="mt-1 w-full rounded border px-2 py-1 text-sm">
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">
                                {{ branch.name }}
                            </option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        {{ t('portal.appointments.date') }}
                        <input v-model="book.date" type="date" class="mt-1 w-full rounded border px-2 py-1 text-sm" />
                    </label>
                    <Button type="button" @click="findSlots">{{ t('portal.appointments.findSlots') }}</Button>
                </div>

                <ul v-if="slots.length" class="mt-3 space-y-1">
                    <li v-for="slot in slots" :key="slot.starts_at" class="flex items-center justify-between text-sm">
                        <span>{{ slot.starts_at }}</span>
                        <Button type="button" @click="bookSlot(slot)">{{ t('portal.appointments.bookSlot') }}</Button>
                    </li>
                </ul>
                <p v-else class="mt-3 text-sm text-gray-500">{{ t('portal.appointments.slotsEmpty') }}</p>
            </Card>
        </div>

        <Card class="mt-4">
            <h2 class="mb-2 font-semibold">{{ t('portal.checkIn.contactTitle') }}</h2>
            <p class="mb-3 text-xs text-gray-500">{{ t('portal.checkIn.contactHint') }}</p>
            <div class="grid gap-2 sm:grid-cols-2">
                <label class="block text-sm">{{ t('portal.checkIn.phone') }}
                    <input v-model="contactForm.phone" type="tel" class="mt-1 w-full rounded border px-2 py-1 text-sm" />
                </label>
                <label class="block text-sm">{{ t('portal.checkIn.email') }}
                    <input v-model="contactForm.email" type="email" class="mt-1 w-full rounded border px-2 py-1 text-sm" />
                </label>
                <label class="block text-sm">{{ t('portal.checkIn.addressLine') }}
                    <input v-model="contactForm.address.line1" type="text" class="mt-1 w-full rounded border px-2 py-1 text-sm" />
                </label>
                <label class="block text-sm">{{ t('portal.checkIn.city') }}
                    <input v-model="contactForm.address.city" type="text" class="mt-1 w-full rounded border px-2 py-1 text-sm" />
                </label>
                <label class="block text-sm">{{ t('portal.checkIn.postal') }}
                    <input v-model="contactForm.address.postal" type="text" class="mt-1 w-full rounded border px-2 py-1 text-sm" />
                </label>
            </div>
            <div class="mt-3">
                <Button type="button" @click="saveContact">{{ t('portal.checkIn.saveContact') }}</Button>
            </div>
        </Card>
    </PortalLayout>
</template>
