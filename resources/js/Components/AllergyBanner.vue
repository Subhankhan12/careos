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
</script>

<template>
    <section class="rounded-md border-2 border-danger bg-danger/10 p-4">
        <h2 class="text-lg font-bold text-danger">{{ $t('clinical.chart.allergies.title') }}</h2>
        <div v-if="allergies.length > 0" class="mt-3 grid gap-3 md:grid-cols-2">
            <div v-for="allergy in allergies" :key="allergy.id" class="rounded-md border border-danger/30 bg-surface p-3">
                <p class="font-semibold text-ink">{{ allergy.substance }}</p>
                <p class="text-sm text-ink-muted">{{ allergy.severity }} | {{ allergy.status }}</p>
                <p v-if="allergy.reaction" class="mt-1 text-sm text-ink-muted">{{ allergy.reaction }}</p>
            </div>
        </div>
        <p v-else class="mt-2 text-sm text-ink-muted">{{ $t('clinical.chart.empty') }}</p>
    </section>
</template>
