<script setup lang="ts">
defineProps<{
    steps: string[];
    current: number;
}>();

defineEmits<{ (e: 'select', index: number): void }>();
</script>

<template>
    <nav class="grid gap-2 sm:grid-cols-4">
        <button
            v-for="(step, index) in steps"
            :key="step"
            type="button"
            class="rounded-md border px-3 py-2 text-left text-sm font-medium transition"
            :class="index === current ? 'border-brand-600 bg-brand-50 text-brand-900' : 'border-line bg-surface text-ink-muted hover:text-ink'"
            @click="$emit('select', index)"
        >
            <span class="block text-xs text-ink-subtle">{{ index + 1 }}</span>
            {{ step }}
        </button>
    </nav>
</template>
