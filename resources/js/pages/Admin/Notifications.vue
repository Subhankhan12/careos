<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

type Event = { key: string; emailEnabled: boolean };

const props = defineProps<{
    events: Event[];
    smsAvailable: boolean;
    updateUrl: string;
    settingsUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// Map the real event keys (with dots) to their camelCase i18n label block.
const LABEL_KEY: Record<string, string> = {
    'appointment.reminder': 'appointmentReminder',
    'waitlist.offer': 'waitlistOffer',
    'telehealth.invite': 'telehealthInvite',
};
const label = (key: string): string => t(`notificationSettings.events.${LABEL_KEY[key] ?? key}.label`);
const help = (key: string): string => t(`notificationSettings.events.${LABEL_KEY[key] ?? key}.help`);

const form = useForm<{ email: Record<string, boolean> }>({
    email: Object.fromEntries(props.events.map((e) => [e.key, e.emailEnabled])),
});

function toggleEmail(key: string): void {
    form.email[key] = !form.email[key];
}

function save(): void {
    form.post(props.updateUrl, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('notificationSettings.title')" />
        <div class="settings-surface space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('notificationSettings.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('notificationSettings.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('notificationSettings.subtitle') }}</p>
                <Link :href="settingsUrl" class="mt-2 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('notificationSettings.backToSettings') }}</Link>
            </div>

            <p v-if="flash === 'saved'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t('notificationSettings.flash.saved') }}
            </p>

            <Card animate :style="{ '--euca-card-delay': '0.02s' }" :title="t('notificationSettings.title')" :subtitle="t('notificationSettings.subtitle')">
                <form @submit.prevent="save">
                    <!-- Column header -->
                    <div class="hidden items-center gap-4 border-b border-line pb-2 text-xs font-medium uppercase tracking-wide text-ink-subtle sm:flex">
                        <span class="flex-1">{{ t('notificationSettings.columns.event') }}</span>
                        <span class="w-16 text-center">{{ t('notificationSettings.columns.email') }}</span>
                        <span class="w-24 text-center">{{ t('notificationSettings.columns.sms') }}</span>
                    </div>

                    <ul class="divide-y divide-line">
                        <li v-for="event in events" :key="event.key" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-ink">{{ label(event.key) }}</p>
                                <p class="mt-0.5 text-xs text-ink-muted">{{ help(event.key) }}</p>
                            </div>

                            <!-- EMAIL toggle (functional — persists to the preference store) -->
                            <div class="flex w-16 justify-center">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="form.email[event.key]"
                                    :aria-label="t('notificationSettings.columns.email') + ' — ' + label(event.key)"
                                    class="relative inline-flex h-6 w-11 flex-none items-center rounded-full transition"
                                    :class="form.email[event.key] ? 'bg-gradient-to-b from-euca-600 to-euca-800' : 'bg-euca-900/15'"
                                    @click="toggleEmail(event.key)"
                                >
                                    <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition" :class="form.email[event.key] ? 'translate-x-5' : 'translate-x-0.5'"></span>
                                </button>
                            </div>

                            <!-- SMS seam (inert — no SMS provider is wired; disabled + labelled) -->
                            <div class="flex w-24 flex-col items-center gap-1">
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="false"
                                    disabled
                                    :title="t('notificationSettings.smsSeam')"
                                    class="relative inline-flex h-6 w-11 flex-none cursor-not-allowed items-center rounded-full bg-euca-900/10 opacity-60"
                                >
                                    <span class="inline-block h-5 w-5 translate-x-0.5 transform rounded-full bg-white/80 shadow"></span>
                                </button>
                                <span class="text-[10px] leading-tight text-ink-subtle">{{ t('notificationSettings.smsSeam') }}</span>
                            </div>
                        </li>

                        <!-- Clinician-attention flag — the AI safety hand-off (Inbox agent sets it on a
                             clinical refusal). Locked-on: NOT a preference, no toggle that turns it off. -->
                        <li class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-ink">{{ t('notificationSettings.attention.label') }}</p>
                                    <span class="rounded-full bg-warning-soft px-2 py-0.5 text-[11px] font-medium text-warning">clinical</span>
                                </div>
                                <p class="mt-0.5 text-xs text-ink-muted">{{ t('notificationSettings.attention.help') }}</p>
                            </div>
                            <div class="flex flex-none items-center">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-euca-200 bg-euca-50 px-3 py-1 text-xs font-semibold text-euca-800">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7" />
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                    </svg>
                                    {{ t('notificationSettings.attention.locked') }}
                                </span>
                            </div>
                        </li>
                    </ul>

                    <div class="mt-6">
                        <Button type="submit" pill :block="false" :disabled="form.processing">{{ t('notificationSettings.save') }}</Button>
                    </div>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
