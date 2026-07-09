<script setup lang="ts">
defineProps<{
    items: Array<{
        id: string;
        title: string;
        subtitle: string | null;
        at: string | null;
    }>;
}>();
</script>

<template>
    <div class="space-y-3">
        <div v-for="item in items" :key="item.id" class="rounded-md border border-line p-4">
            <p class="font-semibold text-ink">{{ item.title }}</p>
            <p class="mt-1 text-sm text-ink-muted">{{ item.subtitle || '-' }}</p>
            <p class="mt-1 text-xs text-ink-subtle">{{ item.at || '-' }}</p>
        </div>
        <p v-if="items.length === 0" class="text-sm text-ink-muted">{{ $t('clinical.chart.empty') }}</p>
    </div>
</template>
