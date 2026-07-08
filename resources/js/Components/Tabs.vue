<script setup lang="ts">
defineProps<{
    tabs: Array<{ key: string; label: string }>;
    active: string;
}>();

defineEmits<{ (e: 'update:active', key: string): void }>();
</script>

<template>
    <div>
        <div class="border-b border-line">
            <nav class="-mb-px flex gap-4 overflow-x-auto">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="border-b-2 px-1 py-3 text-sm font-semibold transition"
                    :class="active === tab.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-ink-muted hover:text-ink'"
                    @click="$emit('update:active', tab.key)"
                >
                    {{ tab.label }}
                </button>
            </nav>
        </div>
        <div class="pt-6">
            <slot />
        </div>
    </div>
</template>
