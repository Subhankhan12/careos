<script setup lang="ts">
defineProps<{
    slots: Array<{ starts_at: string; ends_at: string; resource_ids: string[] }>;
    selected: string;
}>();

defineEmits<{ (e: 'select', slot: { starts_at: string; ends_at: string; resource_ids: string[] }): void }>();

function label(value: string): string {
    return value.slice(11, 16);
}
</script>

<template>
    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <button
            v-for="slot in slots"
            :key="slot.starts_at"
            type="button"
            class="rounded-md border px-3 py-2 text-left text-sm font-semibold transition"
            :class="selected === slot.starts_at ? 'border-brand-600 bg-brand-50 text-brand-950' : 'border-line bg-surface text-ink hover:bg-surface-muted'"
            @click="$emit('select', slot)"
        >
            {{ label(slot.starts_at) }}-{{ label(slot.ends_at) }}
        </button>
        <p v-if="slots.length === 0" class="text-sm text-ink-muted">{{ $t('scheduling.slots.empty') }}</p>
    </div>
</template>
