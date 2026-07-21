<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// A shared sub-nav so the dental surfaces are a COHERENT, navigable section: from any
// dental page a dentist can jump to that patient's other dental surfaces by clicking
// (DENTAL.G9). Presentational only — it links the EXISTING patient-scoped dental routes.
const props = defineProps<{
    patientId: string;
    active: 'chart' | 'perio' | 'diagnoses' | 'plans' | 'images';
}>();

const items = computed(() => [
    { key: 'chart', label: t('dentalSection.chart'), href: `/dental/chart/${props.patientId}` },
    { key: 'perio', label: t('dentalSection.perio'), href: `/dental/perio/${props.patientId}` },
    { key: 'diagnoses', label: t('dentalSection.diagnoses'), href: `/dental/diagnoses/${props.patientId}` },
    { key: 'plans', label: t('dentalSection.plans'), href: `/dental/plans/${props.patientId}` },
    { key: 'images', label: t('dentalSection.images'), href: `/dental/images/${props.patientId}` },
]);
</script>

<template>
    <nav class="flex flex-wrap items-center gap-1 rounded-full bg-euca-50/80 p-1" :aria-label="t('dentalSection.label')">
        <Link
            v-for="item in items"
            :key="item.key"
            :href="item.href"
            class="rounded-full px-3.5 py-1.5 text-sm font-medium transition"
            :class="item.key === active ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'"
        >
            {{ item.label }}
        </Link>
    </nav>
</template>
