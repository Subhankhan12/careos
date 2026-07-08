<script setup lang="ts">
withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'primary' | 'secondary';
        disabled?: boolean;
    }>(),
    { type: 'button', variant: 'primary', disabled: false },
);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        class="inline-flex w-full items-center justify-center rounded-md px-4 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
        :class="
            variant === 'primary'
                ? 'bg-brand-600 text-white hover:bg-brand-700'
                : 'border border-line bg-surface text-ink hover:bg-surface-muted'
        "
    >
        <slot />
    </button>
</template>
