<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
</script>

<template>
    <AppLayout>
        <Head :title="t('shell.admin.title')" />
        <Card :title="t('shell.admin.welcome')">
            <p class="text-sm text-ink-muted">{{ t('shell.admin.empty') }}</p>
        </Card>
    </AppLayout>
</template>
