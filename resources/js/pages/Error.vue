<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const { t } = useI18n();

const props = defineProps<{
    status: number;
    context?: string;
}>();

// The portal consent-withdrawal lockout is a 403 on a portal route — it gets its own
// reassuring "contact the practice" message rather than the generic staff "no access".
const isPortalLockout = computed(() => props.status === 403 && props.context === 'portal');

const messageKey = computed(() => {
    if (isPortalLockout.value) return 'portalLockout';
    return [403, 404, 419, 503].includes(props.status) ? String(props.status) : 'generic';
});

const title = computed(() => t(`errors.${messageKey.value}.title`));
const body = computed(() => t(`errors.${messageKey.value}.body`));

// Staff/other viewers get a way back to the dashboard; a locked-out portal patient is not
// offered a link back into the portal (it would only 403 again) — they contact the practice.
const showBackToApp = computed(() => props.context !== 'portal');
</script>

<template>
    <GuestLayout>
        <Head :title="title" />
        <div class="glass-card p-8 text-center">
            <span class="inline-flex items-center rounded-full bg-euca-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-euca-800">
                {{ t('errors.code', { status }) }}
            </span>
            <h1 class="mt-4 text-xl font-semibold tracking-tight text-ink">{{ title }}</h1>
            <p class="mt-2 text-sm text-ink-muted">{{ body }}</p>
            <Link v-if="showBackToApp" href="/app" class="btn-glow mt-6 inline-flex">{{ t('errors.backToApp') }}</Link>
        </div>
    </GuestLayout>
</template>
