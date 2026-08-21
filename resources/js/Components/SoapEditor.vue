<script setup lang="ts">
/*
 * The SOAP editor.
 *
 * PURELY PRESENTATIONAL (P0D.GU). Every character in a section is the CLINICIAN'S OWN TEXT: this
 * component renders four text areas and emits what was typed. It composes nothing, suggests
 * nothing, completes nothing and interprets nothing — there is no assist affordance here, and
 * the note's content is authored by a human end to end.
 *
 * The per-section marker added in PC.P4 states TWO things the template already knows: whether the
 * section is required, and whether the clinician has typed anything into it. Emptiness is an
 * administrative fact about a text box, not a clinical judgment — and the three markers are
 * styled IDENTICALLY, so the WORD carries the state and nothing is tinted by it (D-169). The
 * footer count on the page has worked this way since the editor was built; this is the same fact
 * shown per section, as the wireframe draws it.
 */

type Soap = { subjective: string | null; objective: string | null; assessment: string | null; plan: string | null };
type SoapKey = 'subjective' | 'objective' | 'assessment' | 'plan';

const props = defineProps<{
    modelValue: Soap;
    readonly?: boolean;
    requiredSections?: string[];
}>();

defineEmits<{ (e: 'update:modelValue', value: Soap): void }>();

const sections = ['subjective', 'objective', 'assessment', 'plan'] as const;

/** The section's initial, as the wireframe letters them. Decoration — it carries no fact. */
const initials: Record<SoapKey, string> = { subjective: 'S', objective: 'O', assessment: 'A', plan: 'P' };

function isRequired(section: SoapKey): boolean {
    return props.requiredSections?.includes(section) ?? false;
}

/** Has the clinician typed anything here? Whitespace is not content. */
function isFilled(section: SoapKey): boolean {
    return ((props.modelValue[section] ?? '') as string).trim() !== '';
}

function update(
    key: SoapKey,
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
            <p class="mb-1.5 flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
                <span
                    class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-euca-100 text-xs font-bold text-euca-800"
                    aria-hidden="true"
                >{{ initials[section] }}</span>
                {{ $t(`clinical.note.sections.${section}`) }}
                <!-- Required/optional and filled/empty, in words. Every marker carries the SAME
                     classes: the state is stated, never signalled by colour (D-169). -->
                <span class="rounded-full bg-euca-100 px-2 py-0.5 text-xs font-medium text-euca-800">
                    <template v-if="isRequired(section)">
                        {{ $t('clinical.note.required') }} ·
                        {{ isFilled(section) ? $t('clinical.note.sectionFilled') : $t('clinical.note.sectionEmpty') }}
                    </template>
                    <template v-else>{{ $t('clinical.note.sectionOptional') }}</template>
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
