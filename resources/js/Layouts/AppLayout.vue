<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const page = usePage();

const user = computed(() => (page.props.auth as { user: { name: string } | null }).user);

function signOut(): void {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-full bg-surface-muted">
        <header class="border-b border-line bg-surface">
            <div class="mx-auto flex h-14 max-w-6xl items-center justify-between px-4">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-md bg-brand-600 text-sm font-bold text-white">C</span>
                    <span class="font-semibold tracking-tight text-ink">{{ t('app.name') }}</span>
                </div>
                <div class="flex items-center gap-4">
                    <span v-if="user" class="text-sm text-ink-muted">{{ user.name }}</span>
                    <button
                        type="button"
                        class="text-sm font-medium text-ink-muted transition hover:text-ink"
                        @click="signOut"
                    >
                        {{ t('app.signOut') }}
                    </button>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-4 py-10">
            <slot />
        </main>
    </div>
</template>
