<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ size?: 'sm' | 'md' | 'lg' }>(), { size: 'md' });

const tile = computed(
    () =>
        ({
            sm: 'h-7 w-7 rounded-lg',
            md: 'h-9 w-9 rounded-xl',
            lg: 'h-11 w-11 rounded-2xl',
        })[props.size],
);
const glyph = computed(() => ({ sm: 'h-4 w-4', md: 'h-5 w-5', lg: 'h-6 w-6' })[props.size]);
</script>

<template>
    <!-- Deep-eucalyptus tile + ivory leaf: the CareOS wordmark lockup. -->
    <span :class="tile" class="euca-tile-dark flex items-center justify-center">
        <svg :class="glyph" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M5 19C5 11 9 5.2 19 4.8c-.4 9.2-5 13.8-14 14.2z" fill="currentColor" />
            <path
                d="M8.6 15.4C11.1 12.4 14 9.7 17 8"
                stroke="#ffffff"
                stroke-opacity="0.55"
                stroke-width="1.2"
                stroke-linecap="round"
            />
        </svg>
    </span>
</template>
