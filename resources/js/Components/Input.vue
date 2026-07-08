<script setup lang="ts">
defineProps<{
    id: string;
    label: string;
    type?: string;
    modelValue: string;
    error?: string;
    autocomplete?: string;
    required?: boolean;
}>();

defineEmits<{ (e: 'update:modelValue', value: string): void }>();
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-ink">{{ label }}</label>
        <input
            :id="id"
            :type="type ?? 'text'"
            :value="modelValue"
            :autocomplete="autocomplete"
            :required="required"
            class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm transition placeholder:text-ink-subtle focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30"
            :class="error ? 'border-danger focus:border-danger focus:ring-danger/30' : ''"
            @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        />
        <p v-if="error" class="mt-1.5 text-sm text-danger">{{ error }}</p>
    </div>
</template>
