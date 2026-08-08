<script setup lang="ts">
// The Settings surface layout (SETTINGS.P6): AppLayout + the wireframe's sticky 220px left sub-nav
// tying the Settings cards/pages (P1–P6) together. Each Settings page renders its content in the
// slot (the right column).
import AppLayout from '@/Layouts/AppLayout.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
</script>

<template>
    <AppLayout>
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-[220px_minmax(0,1fr)]">
            <SettingsNav class="hidden lg:block" />
            <div class="min-w-0">
                <slot />
            </div>
        </div>
    </AppLayout>
</template>
