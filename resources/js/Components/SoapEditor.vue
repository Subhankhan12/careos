<script setup lang="ts">
type Soap = { subjective: string | null; objective: string | null; assessment: string | null; plan: string | null };

defineProps<{
    modelValue: Soap;
    readonly?: boolean;
    requiredSections?: string[];
}>();

defineEmits<{ (e: 'update:modelValue', value: Soap): void }>();

const sections = ['subjective', 'objective', 'assessment', 'plan'] as const;

function update(
    key: 'subjective' | 'objective' | 'assessment' | 'plan',
    value: string,
    current: Soap,
    emit: (e: 'update:modelValue', value: Soap) => void,
): void {
    emit('update:modelValue', { ...current, [key]: value });
}
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-2">
        <div v-for="section in sections" :key="section">
            <p class="mb-1.5 flex items-center gap-2 text-sm font-semibold text-ink">
                {{ $t(`clinical.note.sections.${section}`) }}
                <span
                    v-if="requiredSections?.includes(section)"
                    class="rounded-full bg-euca-100 px-2 py-0.5 text-xs font-medium text-euca-800"
                >
                    {{ $t('clinical.note.required') }}
                </span>
            </p>
            <!-- Signed notes render as plain text on ivory wells — no edit cursor, no delete. -->
            <p
                v-if="readonly"
                class="min-h-24 whitespace-pre-line rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink"
            >
                {{ modelValue[section] || '—' }}
            </p>
            <textarea
                v-else
                :value="modelValue[section] ?? ''"
                class="min-h-40 w-full resize-y rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink shadow-sm transition focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                @input="update(section, ($event.target as HTMLTextAreaElement).value, modelValue, $emit)"
            />
        </div>
    </div>
</template>
