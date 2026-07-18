<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import BrandMark from '@/Components/BrandMark.vue';

const { t } = useI18n();
</script>

<template>
    <div class="euca-wash relative flex min-h-full flex-col items-center justify-center px-4 py-12">
        <!-- Faint botanical watermark (decorative). -->
        <svg
            class="pointer-events-none absolute -right-16 -top-10 h-80 w-80 text-euca-300 opacity-40"
            viewBox="0 0 200 200"
            fill="none"
            aria-hidden="true"
        >
            <path
                d="M40 170C40 100 70 55 160 45c-4 78-44 118-120 125z"
                fill="currentColor"
                fill-opacity="0.5"
            />
            <path d="M70 140C95 108 128 78 160 60" stroke="#ffffff" stroke-opacity="0.6" stroke-width="2" />
        </svg>

        <div class="relative z-10 mb-6 flex items-center gap-2.5">
            <BrandMark size="md" />
            <span class="text-lg font-semibold tracking-tight text-ink">{{ t('app.name') }}</span>
        </div>
        <div class="relative z-10 w-full max-w-md">
            <slot />
        </div>
    </div>
</template>
