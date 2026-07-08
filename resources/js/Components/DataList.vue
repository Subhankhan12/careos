<script setup lang="ts">
defineProps<{
    items: Array<{ label: string; value: string | number | null | undefined }>;
}>();
</script>

<template>
    <dl class="grid gap-4 sm:grid-cols-2">
        <div v-for="item in items" :key="item.label" class="border-b border-line pb-3">
            <dt class="text-xs font-semibold uppercase text-ink-subtle">{{ item.label }}</dt>
            <dd class="mt-1 text-sm text-ink">{{ item.value || '-' }}</dd>
        </div>
    </dl>
</template>
