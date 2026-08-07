<script setup lang="ts">
// `animate` opts the card into the prototype's eucardIn entrance (SETTINGS.P1). Default off, so
// no existing card animates; the effect is reduced-motion guarded in app.css.
defineProps<{ title?: string; subtitle?: string; animate?: boolean }>();
</script>

<template>
    <div class="glass-card p-6 sm:p-8" :class="{ 'euca-card-in': animate }">
        <div v-if="title || subtitle" class="mb-6">
            <h1 v-if="title" class="text-2xl font-semibold tracking-tight text-ink">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-ink-muted">{{ subtitle }}</p>
        </div>
        <slot />
    </div>
</template>
