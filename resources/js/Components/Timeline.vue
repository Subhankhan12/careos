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
        <div
            v-for="item in items"
            :key="item.id"
            class="flex gap-3 rounded-xl border border-line bg-surface-2 p-4"
        >
            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-euca-400"></span>
            <div class="min-w-0">
                <p class="font-semibold text-ink">{{ item.title }}</p>
                <p class="mt-0.5 text-sm text-ink-muted">{{ item.subtitle || '—' }}</p>
                <p class="mt-0.5 text-xs text-ink-subtle">{{ item.at || '—' }}</p>
            </div>
        </div>
        <p v-if="items.length === 0" class="text-sm text-ink-muted">{{ $t('clinical.chart.empty') }}</p>
    </div>
</template>
