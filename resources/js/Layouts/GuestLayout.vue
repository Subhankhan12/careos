<script setup lang="ts">
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <div class="flex min-h-full flex-col items-center justify-center bg-surface-muted px-4 py-12">
        <div class="mb-6 flex items-center gap-2">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-lg font-bold text-white">C</span>
            <span class="text-lg font-semibold tracking-tight text-ink">{{ t('app.name') }}</span>
        </div>
        <div class="w-full max-w-md">
            <slot />
        </div>
    </div>
</template>
