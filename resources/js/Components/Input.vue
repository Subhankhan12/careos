<script setup lang="ts">
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        type?: string;
        modelValue: string;
        error?: string;
        autocomplete?: string;
        required?: boolean;
        placeholder?: string;
        reveal?: boolean;
        toggleLabel?: string;
    }>(),
    { type: 'text', reveal: false },
);

defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const shown = ref(false);
const isPassword = computed(() => props.reveal && props.type === 'password');
const effectiveType = computed(() => (isPassword.value ? (shown.value ? 'text' : 'password') : (props.type ?? 'text')));
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-ink">{{ label }}</label>
        <div class="relative">
            <input
                :id="id"
                :type="effectiveType"
                :value="modelValue"
                :autocomplete="autocomplete"
                :required="required"
                :placeholder="placeholder"
                class="block w-full rounded-xl border bg-surface-2 px-3.5 py-2.5 text-sm text-ink shadow-sm transition placeholder:text-ink-subtle focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                :class="[
                    error ? 'border-danger focus:border-danger focus:ring-danger/30' : 'border-line focus:border-euca-600',
                    isPassword ? 'pr-11' : '',
                ]"
                @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            />
            <button
                v-if="isPassword"
                type="button"
                :aria-label="toggleLabel"
                class="absolute inset-y-0 right-0 flex items-center px-3 text-ink-subtle transition hover:text-ink-muted"
                @click="shown = !shown"
            >
                <svg v-if="!shown" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                        d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"
                        stroke="currentColor"
                        stroke-width="1.5"
                    />
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5" />
                </svg>
                <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                        d="M4 4l16 16M9.9 5.2A9.6 9.6 0 0 1 12 5c6 0 9.5 6.5 9.5 6.5a16 16 0 0 1-3 3.6M6.4 7A16 16 0 0 0 2.5 11.5S6 18 12 18a9 9 0 0 0 3-.5"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                    />
                </svg>
            </button>
        </div>
        <p v-if="error" class="mt-1.5 flex items-center gap-1 text-sm text-danger">
            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                <path d="M12 7v6M12 16.5v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            {{ error }}
        </p>
    </div>
</template>
