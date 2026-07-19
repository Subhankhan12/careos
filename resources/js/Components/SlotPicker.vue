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
    <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-4">
        <button
            v-for="slot in slots"
            :key="slot.starts_at"
            type="button"
            class="rounded-xl px-3.5 py-2.5 text-sm font-semibold transition"
            :class="selected === slot.starts_at ? 'btn-glow' : 'border border-line bg-surface-2 text-ink hover:border-euca-400'"
            @click="$emit('select', slot)"
        >
            {{ label(slot.starts_at) }}–{{ label(slot.ends_at) }}
        </button>
        <p v-if="slots.length === 0" class="col-span-full text-sm text-ink-muted">{{ $t('scheduling.slots.empty') }}</p>
    </div>
</template>
