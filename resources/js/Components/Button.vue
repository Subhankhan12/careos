<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        disabled?: boolean;
        block?: boolean;
        // Pill radius for prototype-parity primary actions (SETTINGS.P1). Default keeps the
        // app-wide rounded-xl shape, so opting in never changes existing buttons.
        pill?: boolean;
    }>(),
    { type: 'button', variant: 'primary', disabled: false, block: true, pill: false },
);

const variantClass = computed(
    () =>
        ({
            primary: 'btn-glow',
            secondary: 'border border-line bg-surface/70 text-ink hover:bg-surface-2',
            ghost: 'text-ink-muted hover:bg-euca-50 hover:text-ink',
            danger: 'bg-danger text-white hover:opacity-90',
        })[props.variant],
);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        class="inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-euca-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        :class="[block ? 'w-full' : '', pill ? 'rounded-full' : 'rounded-xl', variantClass]"
    >
        <slot />
    </button>
</template>
