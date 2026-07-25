<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

// Shared, on-brand empty state (POLISH.3) — icon + one-line explanation + an optional next-action link.
// Presentational only; the parent passes already-translated strings and a route the actor can reach.
defineProps<{
    title: string;
    message: string;
    actionLabel?: string;
    actionHref?: string;
}>();
</script>

<template>
    <div class="flex flex-col items-center justify-center rounded-2xl bg-euca-50/60 px-6 py-12 text-center">
        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-euca-100 text-euca-700">
            <slot name="icon">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M5 19C5 11 9 5.2 19 4.8c-.4 9.2-5 13.8-14 14.2z" fill="currentColor" />
                </svg>
            </slot>
        </span>
        <p class="mt-4 text-sm font-semibold text-ink">{{ title }}</p>
        <p class="mt-1 max-w-sm text-sm text-ink-muted">{{ message }}</p>
        <Link
            v-if="actionHref && actionLabel"
            :href="actionHref"
            class="btn-glow mt-5 inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold"
        >
            {{ actionLabel }}
        </Link>
    </div>
</template>
