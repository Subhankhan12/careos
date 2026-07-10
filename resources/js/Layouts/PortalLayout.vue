<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

function logout(): void {
    router.post('/portal/logout');
}
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <header class="border-b bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                <span class="font-semibold">{{ t('portal.title') }}</span>
                <nav class="flex flex-wrap items-center gap-3 text-sm">
                    <Link href="/portal" class="hover:underline">{{ t('portal.nav.home') }}</Link>
                    <Link href="/portal/appointments" class="hover:underline">{{ t('portal.nav.appointments') }}</Link>
                    <Link href="/portal/documents" class="hover:underline">{{ t('portal.nav.documents') }}</Link>
                    <Link href="/portal/messages" class="hover:underline">{{ t('portal.nav.messages') }}</Link>
                    <Link href="/portal/invoices" class="hover:underline">{{ t('portal.nav.invoices') }}</Link>
                    <Link href="/portal/consents" class="hover:underline">{{ t('portal.nav.consents') }}</Link>
                    <Link href="/portal/telehealth" class="hover:underline">{{ t('portal.nav.telehealth') }}</Link>
                    <button type="button" class="text-gray-500 hover:underline" @click="logout">
                        {{ t('portal.signOut') }}
                    </button>
                </nav>
            </div>
        </header>
        <main class="mx-auto max-w-5xl px-4 py-6">
            <slot />
        </main>
    </div>
</template>
