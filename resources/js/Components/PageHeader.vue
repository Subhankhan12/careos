<script setup lang="ts">
// Shared page-header pattern: eyebrow caps · title · subtitle · right-aligned actions slot.
withDefaults(defineProps<{ eyebrow?: string; title: string; subtitle?: string; display?: boolean }>(), {
    display: false,
});
</script>

<template>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p v-if="eyebrow" class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">
                {{ eyebrow }}
            </p>
            <h1
                class="mt-1 text-ink"
                :class="display ? 'text-4xl font-semibold leading-[1.05] tracking-tight sm:text-5xl' : 'text-2xl font-semibold tracking-tight'"
            >
                {{ title }}
            </h1>
            <p v-if="subtitle" class="mt-2 max-w-2xl text-sm text-ink-muted">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
