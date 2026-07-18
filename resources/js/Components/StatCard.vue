<script setup lang="ts">
// Glass KPI tile. Value defaults to the em-dash placeholder until real stat props exist
// (per the design's "prop-less reality" — sparkline stays hidden until then).
withDefaults(defineProps<{ label: string; value?: string; hint?: string }>(), { value: '—' });
</script>

<template>
    <div class="glass-card p-5">
        <div class="flex items-center gap-2.5 text-ink-muted">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-euca-50 text-euca-700">
                <slot name="icon" />
            </span>
            <span class="text-sm font-medium">{{ label }}</span>
        </div>
        <p class="mt-3 text-3xl font-semibold text-ink">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-ink-subtle">{{ hint }}</p>
    </div>
</template>
