<script setup lang="ts">
defineProps<{ title?: string; subtitle?: string }>();
</script>

<template>
    <div class="rounded-xl border border-line bg-surface p-6 shadow-sm sm:p-8">
        <div v-if="title || subtitle" class="mb-6">
            <h1 v-if="title" class="text-2xl font-semibold tracking-tight text-ink">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-ink-muted">{{ subtitle }}</p>
        </div>
        <slot />
    </div>
</template>
