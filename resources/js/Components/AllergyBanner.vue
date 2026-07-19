<script setup lang="ts">
defineProps<{
    allergies: Array<{
        id: string;
        substance: string;
        reaction: string | null;
        severity: string;
        status: string;
    }>;
}>();

function label(a: { substance: string; reaction: string | null; severity: string }): string {
    const detail = [a.reaction, a.severity ? `(${a.severity})` : ''].filter(Boolean).join(' ');
    return detail ? `${a.substance} — ${detail}` : a.substance;
}
</script>

<template>
    <!-- The loudest element on the chart: prominent amber-soft, impossible to miss — calm, not red.
         It only surfaces documented allergies; it never interprets or grades clinical risk. -->
    <section
        v-if="allergies.length > 0"
        class="flex items-start gap-3 rounded-2xl border-2 border-warning/50 bg-warning-soft px-5 py-4"
    >
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-warning/20 text-warning">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 4 21 19H3L12 4Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                <path d="M12 10v4M12 16.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            </svg>
        </span>
        <p class="text-sm leading-relaxed">
            <span class="font-bold text-ink">{{ $t('clinical.chart.allergies.title') }}:</span>
            <span class="ml-1 font-semibold text-ink">{{ allergies.map(label).join(' · ') }}</span>
        </p>
    </section>
    <section
        v-else
        class="rounded-2xl border border-line bg-surface-2 px-5 py-3 text-sm text-ink-muted"
    >
        {{ $t('clinical.chart.allergies.none') }}
    </section>
</template>
