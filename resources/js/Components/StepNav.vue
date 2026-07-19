<script setup lang="ts">
defineProps<{
    steps: string[];
    current: number;
}>();

defineEmits<{ (e: 'select', index: number): void }>();
</script>

<template>
    <nav class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-full bg-euca-50/70 p-1">
        <button
            v-for="(step, index) in steps"
            :key="step"
            type="button"
            class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium transition"
            :class="index === current ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
            @click="$emit('select', index)"
        >
            <span
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                :class="
                    index === current
                        ? 'bg-euca-800 text-euca-50'
                        : index < current
                          ? 'bg-euca-300 text-euca-900'
                          : 'bg-euca-100 text-euca-700'
                "
            >
                {{ index + 1 }}
            </span>
            {{ step }}
        </button>
    </nav>
</template>
