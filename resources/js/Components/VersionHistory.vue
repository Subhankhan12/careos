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
    <div class="space-y-3">
        <div v-for="version in versions" :key="version.id" class="rounded-md border border-line p-4">
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
                <div>
                    <p class="text-sm font-semibold text-ink">
                        {{ $t('clinical.note.versionLabel', { version: version.version }) }}
                    </p>
                    <p class="mt-1 text-xs text-ink-muted">
                        {{ version.author_name }} | {{ version.signed_at || version.created_at || '-' }} | {{ version.status }}
                    </p>
                    <p v-if="version.amendment_reason" class="mt-2 text-sm text-ink-muted">
                        {{ version.amendment_reason }}
                    </p>
                </div>
                <Link :href="version.edit_url" class="text-sm font-semibold text-brand-700 hover:text-brand-900">
                    {{ $t('clinical.note.openVersion') }}
                </Link>
            </div>
        </div>
    </div>
</template>
