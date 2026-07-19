<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PortalLayout from '@/Layouts/PortalLayout.vue';

const { t } = useI18n();

const props = defineProps<{
    documents: Array<{
        id: string;
        category: string;
        title: string;
        original_filename: string;
        mime_type: string;
        uploaded_at: string;
        shared_at: string | null;
        size_bytes?: number;
        download_url: string;
    }>;
}>();

const activeCategory = ref('all');

const categories = computed(() => {
    const counts: Record<string, number> = {};
    for (const d of props.documents) counts[d.category] = (counts[d.category] ?? 0) + 1;
    return Object.entries(counts).map(([key, count]) => ({ key, count }));
});

const filtered = computed(() =>
    activeCategory.value === 'all' ? props.documents : props.documents.filter((d) => d.category === activeCategory.value),
);

function fileType(mime: string): string {
    if (mime?.includes('pdf')) return 'PDF';
    const tail = mime?.split('/')?.[1] ?? '';
    return tail ? tail.toUpperCase() : t('portal.documents.file');
}

function formatSize(bytes?: number): string {
    if (!bytes || bytes <= 0) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <PortalLayout>
        <Head :title="t('portal.documents.title')" />

        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">{{ t('portal.documents.eyebrow') }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{{ t('portal.documents.title') }}</h1>
        <p class="mt-1 max-w-2xl text-ink-muted">{{ t('portal.documents.subtitle') }}</p>

        <div v-if="documents.length" class="mt-6 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-full px-3.5 py-1.5 text-sm font-medium transition"
                :class="activeCategory === 'all' ? 'nav-pill-active text-ink' : 'bg-euca-50 text-ink-muted hover:text-ink'"
                @click="activeCategory = 'all'"
            >
                {{ t('portal.documents.all') }} · {{ documents.length }}
            </button>
            <button
                v-for="cat in categories"
                :key="cat.key"
                type="button"
                class="rounded-full px-3.5 py-1.5 text-sm font-medium capitalize transition"
                :class="activeCategory === cat.key ? 'nav-pill-active text-ink' : 'bg-euca-50 text-ink-muted hover:text-ink'"
                @click="activeCategory = cat.key"
            >
                {{ cat.key }} · {{ cat.count }}
            </button>
        </div>

        <div class="glass-card mt-4 overflow-hidden p-2">
            <ul v-if="filtered.length" class="divide-y divide-line/70">
                <li v-for="document in filtered" :key="document.id" class="flex items-center gap-4 px-3 py-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-euca-50 text-euca-700">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M7 3h7l4 4v14H7V3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                            <path d="M14 3v4h4" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-ink">{{ document.title }}</span>
                        <span class="block text-sm text-ink-subtle">
                            <span class="capitalize">{{ document.category }}</span> · {{ fileType(document.mime_type) }}
                            <template v-if="formatSize(document.size_bytes)"> · {{ formatSize(document.size_bytes) }}</template>
                            <template v-if="document.shared_at"> · {{ document.shared_at }}</template>
                        </span>
                    </span>
                    <a
                        :href="document.download_url"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-line bg-surface/70 px-3.5 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 4v10m0 0l-3.5-3.5M12 14l3.5-3.5M5 19h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        {{ t('portal.documents.download') }}
                    </a>
                </li>
            </ul>
            <p v-else class="px-4 py-12 text-center text-ink-muted">{{ t('portal.documents.empty') }}</p>
        </div>

        <p v-if="documents.length" class="mt-4 text-sm text-ink-subtle">{{ t('portal.documents.footer') }}</p>
    </PortalLayout>
</template>
