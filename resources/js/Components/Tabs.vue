<script setup lang="ts">
defineProps<{
    tabs: Array<{ key: string; label: string; count?: number }>;
    active: string;
}>();

defineEmits<{ (e: 'update:active', key: string): void }>();
</script>

<template>
    <div>
        <nav class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-full bg-euca-50/70 p-1">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium transition"
                :class="active === tab.key ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
                @click="$emit('update:active', tab.key)"
            >
                {{ tab.label }}
                <span
                    v-if="tab.count !== undefined"
                    class="rounded-full bg-euca-100 px-1.5 text-xs font-semibold text-euca-800"
                >
                    {{ tab.count }}
                </span>
            </button>
        </nav>
        <div class="pt-6">
            <slot />
        </div>
    </div>
</template>
