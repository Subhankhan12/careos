<script setup lang="ts">
defineProps<{
    modelValue: {
        subjective: string | null;
        objective: string | null;
        assessment: string | null;
        plan: string | null;
    };
    readonly?: boolean;
    requiredSections?: string[];
}>();

defineEmits<{
    (e: 'update:modelValue', value: { subjective: string | null; objective: string | null; assessment: string | null; plan: string | null }): void;
}>();

const sections = ['subjective', 'objective', 'assessment', 'plan'] as const;

function update(
    key: 'subjective' | 'objective' | 'assessment' | 'plan',
    value: string,
    current: { subjective: string | null; objective: string | null; assessment: string | null; plan: string | null },
    emit: (e: 'update:modelValue', value: { subjective: string | null; objective: string | null; assessment: string | null; plan: string | null }) => void,
): void {
    emit('update:modelValue', { ...current, [key]: value });
}
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-2">
        <label v-for="section in sections" :key="section" class="block">
            <span class="mb-1.5 flex items-center gap-2 text-sm font-semibold text-ink">
                {{ $t(`clinical.note.sections.${section}`) }}
                <span v-if="requiredSections?.includes(section)" class="text-xs font-medium text-ink-muted">
                    {{ $t('clinical.note.required') }}
                </span>
            </span>
            <textarea
                :value="modelValue[section] ?? ''"
                :readonly="readonly"
                class="min-h-40 w-full resize-y rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 read-only:bg-surface-muted"
                @input="update(section, ($event.target as HTMLTextAreaElement).value, modelValue, $emit)"
            />
        </label>
    </div>
</template>
