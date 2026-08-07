<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();

defineProps<{
    security: { twoFactor: string; sessionTimeoutMin: number; nursePwaIdleMin: number };
    settingsUrl: string;
}>();
</script>

<template>
    <AppLayout>
        <Head :title="t('securitySettings.title')" />
        <div class="settings-surface space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('securitySettings.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('securitySettings.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('securitySettings.subtitle') }}</p>
                <Link :href="settingsUrl" class="mt-2 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('securitySettings.backToSettings') }}</Link>
            </div>

            <Card animate :style="{ '--euca-card-delay': '0.02s' }" :title="t('securitySettings.title')" :subtitle="t('securitySettings.subtitle')">
                <ul class="divide-y divide-line">
                    <!-- Two-factor: MANDATORY, platform-enforced (EnsureTwoFactorEnabled). Read-only, locked —
                         there is no control here that could disable it. -->
                    <li class="flex flex-col gap-2 py-4 first:pt-0 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-ink">{{ t('securitySettings.twoFactor.label') }}</p>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('securitySettings.twoFactor.help') }}</p>
                        </div>
                        <span class="inline-flex flex-none items-center gap-1.5 rounded-full border border-euca-200 bg-euca-50 px-3 py-1 text-xs font-semibold text-euca-800">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7" />
                                <path d="M8 11V8a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                            {{ t('securitySettings.twoFactor.locked') }}
                        </span>
                    </li>

                    <!-- Staff session timeout — platform config (session.lifetime). Read-only. -->
                    <li class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-ink">{{ t('securitySettings.sessionTimeout.label') }}</p>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('securitySettings.sessionTimeout.help') }}</p>
                        </div>
                        <span class="flex-none rounded-full bg-euca-100 px-3 py-1 text-xs font-medium text-euca-800">
                            {{ security.sessionTimeoutMin }} {{ t('securitySettings.sessionTimeout.unit') }}
                        </span>
                    </li>

                    <!-- Nurse-PWA idle wipe — client build constant in the separate PWA. Read-only. -->
                    <li class="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-ink">{{ t('securitySettings.pwaWipe.label') }}</p>
                            <p class="mt-0.5 text-xs text-ink-muted">{{ t('securitySettings.pwaWipe.help') }}</p>
                        </div>
                        <span class="flex-none rounded-full bg-euca-100 px-3 py-1 text-xs font-medium text-euca-800">
                            {{ security.nursePwaIdleMin }} {{ t('securitySettings.pwaWipe.unit') }}
                        </span>
                    </li>
                </ul>

                <p class="mt-4 flex items-center gap-2 text-xs text-ink-subtle">
                    <svg class="h-4 w-4 flex-none text-euca-700" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                    </svg>
                    {{ t('securitySettings.platformNote') }}
                </p>
            </Card>
        </div>
    </AppLayout>
</template>
