<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        length?: number;
        autofocus?: boolean;
        ariaLabel?: string;
        invalid?: boolean;
    }>(),
    { length: 6, autofocus: false, ariaLabel: 'Digit', invalid: false },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const inputs = ref<HTMLInputElement[]>([]);
const cells = ref<string[]>(Array.from({ length: props.length }, () => ''));

function setRef(el: HTMLInputElement | null, i: number): void {
    if (el) inputs.value[i] = el;
}

// Distribute the single `modelValue` string across the segments (one source of truth).
watch(
    () => props.modelValue,
    (v) => {
        const chars = (v ?? '').slice(0, props.length).split('');
        cells.value = Array.from({ length: props.length }, (_, i) => chars[i] ?? '');
    },
    { immediate: true },
);

function commit(): void {
    emit('update:modelValue', cells.value.join(''));
}

function onInput(i: number, event: Event): void {
    const el = event.target as HTMLInputElement;
    const raw = el.value.replace(/\D/g, '');
    if (raw.length > 1) {
        const chars = raw.split('');
        for (let k = 0; k < chars.length && i + k < props.length; k++) cells.value[i + k] = chars[k];
        const next = Math.min(i + chars.length, props.length - 1);
        nextTick(() => inputs.value[next]?.focus());
    } else {
        cells.value[i] = raw;
        if (raw && i < props.length - 1) nextTick(() => inputs.value[i + 1]?.focus());
    }
    el.value = cells.value[i];
    commit();
}

function onKeydown(i: number, event: KeyboardEvent): void {
    if (event.key === 'Backspace' && !cells.value[i] && i > 0) {
        inputs.value[i - 1]?.focus();
    } else if (event.key === 'ArrowLeft' && i > 0) {
        inputs.value[i - 1]?.focus();
    } else if (event.key === 'ArrowRight' && i < props.length - 1) {
        inputs.value[i + 1]?.focus();
    }
}

function onPaste(event: ClipboardEvent): void {
    event.preventDefault();
    const raw = (event.clipboardData?.getData('text') ?? '').replace(/\D/g, '').slice(0, props.length);
    cells.value = Array.from({ length: props.length }, (_, k) => raw[k] ?? '');
    commit();
    nextTick(() => inputs.value[Math.min(raw.length, props.length - 1)]?.focus());
}

onMounted(() => {
    if (props.autofocus) inputs.value[0]?.focus();
});
</script>

<template>
    <!-- Six segments compose the one `code` value (autocomplete=one-time-code on the first). -->
    <div class="flex gap-2 sm:gap-3">
        <input
            v-for="(cell, i) in cells"
            :key="i"
            :ref="(el) => setRef(el as HTMLInputElement, i)"
            :value="cell"
            type="text"
            inputmode="numeric"
            maxlength="1"
            :autocomplete="i === 0 ? 'one-time-code' : 'off'"
            :aria-label="`${ariaLabel} ${i + 1}`"
            class="h-14 w-12 rounded-xl border bg-surface-2 text-center text-2xl font-semibold text-ink shadow-sm outline-none transition focus:ring-2 focus:ring-euca-500/30"
            :class="
                invalid
                    ? 'border-danger focus:border-danger focus:ring-danger/30'
                    : cell
                      ? 'border-euca-400 focus:border-euca-600'
                      : 'border-line focus:border-euca-600'
            "
            @input="onInput(i, $event)"
            @keydown="onKeydown(i, $event)"
            @paste="onPaste"
        />
    </div>
</template>
