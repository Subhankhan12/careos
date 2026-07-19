<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    versions: Array<{
        id: string;
        version: number;
        status: string;
        author_name: string;
        created_at: string | null;
        signed_at: string | null;
        amendment_reason: string | null;
        edit_url: string;
    }>;
}>();
</script>

<template>
    <!-- Every version is always reachable; nothing here deletes. -->
    <div class="space-y-3">
        <div
            v-for="version in versions"
            :key="version.id"
            class="rounded-xl border border-line bg-surface-2 p-4"
            :class="version.status === 'signed' ? 'border-l-4 border-l-euca-700' : ''"
        >
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-semibold text-ink">{{ $t('clinical.note.versionLabel', { version: version.version }) }}</p>
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="version.status === 'signed' ? 'bg-euca-100 text-euca-800' : 'bg-surface text-ink-muted'"
                        >
                            <svg v-if="version.status === 'signed'" class="h-3 w-3" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.6" />
                                <path d="M8 10V8a4 4 0 0 1 8 0v2" stroke="currentColor" stroke-width="1.6" />
                            </svg>
                            {{ version.status }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-ink-muted">
                        {{ version.author_name }} · {{ version.signed_at || version.created_at || '—' }}
                    </p>
                    <p v-if="version.amendment_reason" class="mt-2 text-sm text-ink-muted">{{ version.amendment_reason }}</p>
                </div>
                <Link :href="version.edit_url" class="shrink-0 text-sm font-semibold text-euca-700 transition hover:text-euca-800">
                    {{ $t('clinical.note.openVersion') }} →
                </Link>
            </div>
        </div>
    </div>
</template>
