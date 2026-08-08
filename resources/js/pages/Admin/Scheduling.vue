<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

const props = defineProps<{
    scheduling: { cancelMinHours: number; travelSpeedKmh: number };
    bounds: {
        cancelMinHours: { min: number; max: number };
        travelSpeedKmh: { min: number; max: number };
    };
    updateUrl: string;
    settingsUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

const form = useForm({
    cancel_min_hours: props.scheduling.cancelMinHours,
    travel_speed_kmh: props.scheduling.travelSpeedKmh,
});

function save(): void {
    form.post(props.updateUrl, { preserveScroll: true });
}
</script>

<template>
    <SettingsLayout>
        <Head :title="t('schedulingSettings.title')" />
        <div class="settings-surface space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('schedulingSettings.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('schedulingSettings.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('schedulingSettings.subtitle') }}</p>
                <Link :href="settingsUrl" class="mt-2 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('schedulingSettings.backToSettings') }}</Link>
            </div>

            <p v-if="flash === 'saved'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t('schedulingSettings.flash.saved') }}
            </p>

            <Card animate :style="{ '--euca-card-delay': '0.02s' }" :title="t('schedulingSettings.title')" :subtitle="t('schedulingSettings.subtitle')">
                <form class="divide-y divide-line" @submit.prevent="save">
                    <!-- Portal cancellation window → scheduling.portal.cancel_min_hours -->
                    <div class="flex flex-col gap-2 py-4 first:pt-0 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <label for="cancel-window" class="block text-sm font-medium text-ink">{{ t('schedulingSettings.cancelWindow.label') }}</label>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('schedulingSettings.cancelWindow.help') }}</p>
                            <p v-if="form.errors.cancel_min_hours" class="mt-1 text-xs text-danger">{{ form.errors.cancel_min_hours }}</p>
                        </div>
                        <div class="flex flex-none items-center gap-2">
                            <input
                                id="cancel-window"
                                v-model.number="form.cancel_min_hours"
                                type="number"
                                :min="bounds.cancelMinHours.min"
                                :max="bounds.cancelMinHours.max"
                                class="w-24 rounded-xl border bg-surface-2 px-3.5 py-2.5 text-right text-sm text-ink shadow-sm transition focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                                :class="form.errors.cancel_min_hours ? 'border-danger' : 'border-line focus:border-euca-600'"
                            />
                            <span class="text-sm text-ink-muted">{{ t('schedulingSettings.cancelWindow.unit') }}</span>
                        </div>
                    </div>

                    <!-- Nurse travel speed → nursing.dispatch.average_speed_kmh -->
                    <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <label for="travel-speed" class="block text-sm font-medium text-ink">{{ t('schedulingSettings.travelSpeed.label') }}</label>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('schedulingSettings.travelSpeed.help') }}</p>
                            <p v-if="form.errors.travel_speed_kmh" class="mt-1 text-xs text-danger">{{ form.errors.travel_speed_kmh }}</p>
                        </div>
                        <div class="flex flex-none items-center gap-2">
                            <input
                                id="travel-speed"
                                v-model.number="form.travel_speed_kmh"
                                type="number"
                                :min="bounds.travelSpeedKmh.min"
                                :max="bounds.travelSpeedKmh.max"
                                class="w-24 rounded-xl border bg-surface-2 px-3.5 py-2.5 text-right text-sm text-ink shadow-sm transition focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                                :class="form.errors.travel_speed_kmh ? 'border-danger' : 'border-line focus:border-euca-600'"
                            />
                            <span class="text-sm text-ink-muted">{{ t('schedulingSettings.travelSpeed.unit') }}</span>
                        </div>
                    </div>

                    <!-- Default appointment buffer — HONEST per-service pointer (no global setting the
                         scheduler reads; buffers live on each Service). Read-only, never persisted here. -->
                    <div class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="block text-sm font-medium text-ink">{{ t('schedulingSettings.buffer.label') }}</p>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('schedulingSettings.buffer.help') }}</p>
                        </div>
                        <span class="flex-none rounded-full bg-euca-100 px-3 py-1 text-xs font-medium text-euca-800">{{ t('schedulingSettings.buffer.value') }}</span>
                    </div>

                    <div class="pt-6">
                        <Button type="submit" pill :block="false" :disabled="form.processing">{{ t('schedulingSettings.save') }}</Button>
                    </div>
                </form>
            </Card>
        </div>
    </SettingsLayout>
</template>
