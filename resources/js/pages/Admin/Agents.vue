<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

type Level = { value: string; allowed: boolean };
type Tool = {
    key: string;
    name: string;
    category: string;
    permission: string;
    ceiling: string;
    level: string;
    levels: Level[];
};

const props = defineProps<{
    tools: Tool[];
    levelOrder: string[];
    updateUrl: string;
    settingsUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// One form for the whole card — per-section Save (the wireframe pattern). Pre-set to each
// tool's current level; the server clamps anything above a tool's ceiling on save.
const form = useForm<{ levels: Record<string, string> }>({
    levels: Object.fromEntries(props.tools.map((tool) => [tool.key, tool.level])),
});

const categoryLabel = (category: string): string =>
    t(`agents.categories.${category}`, category);

function select(tool: Tool, level: Level): void {
    if (level.allowed) form.levels[tool.key] = level.value;
}

function save(): void {
    form.post(props.updateUrl, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('agents.title')" />
        <div class="settings-surface space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('agents.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('agents.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('agents.subtitle') }}</p>
                <Link :href="settingsUrl" class="mt-2 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('agents.backToSettings') }}</Link>
            </div>

            <p v-if="flash === 'saved'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t('agents.flash.saved') }}
            </p>

            <Card animate :style="{ '--euca-card-delay': '0.02s' }" :title="t('agents.title')" :subtitle="t('agents.subtitle')">
                <!-- Governance banner: the suggest-cap thesis. The ceiling is enforced server-side. -->
                <div class="mb-6 flex items-start gap-3 rounded-2xl border border-euca-200 bg-euca-50/70 p-4">
                    <svg class="mt-0.5 h-5 w-5 flex-none text-euca-700" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                        <path d="M9.5 12l1.8 1.8L15 10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <p class="text-sm text-ink">{{ t('agents.banner') }}</p>
                </div>

                <p v-if="tools.length === 0" class="text-sm text-ink-muted">{{ t('agents.empty') }}</p>

                <form v-else @submit.prevent="save">
                    <ul class="divide-y divide-line">
                        <li v-for="tool in tools" :key="tool.key" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="font-medium text-ink">{{ tool.name }}</p>
                                    <span class="rounded-full bg-euca-100 px-2 py-0.5 text-[11px] font-medium text-euca-800">{{ categoryLabel(tool.category) }}</span>
                                </div>
                                <p class="mt-0.5 font-mono text-xs text-ink-subtle">{{ tool.key }}</p>
                                <p class="mt-1 text-xs text-ink-muted">
                                    {{ t('agents.ceiling.label') }}: <span class="font-medium text-ink">{{ t(`agents.levels.${tool.ceiling}`) }}</span>
                                </p>
                            </div>

                            <!-- Autonomy control: allowed levels are selectable; levels above the tool's
                                 ceiling render locked (the server enforces the cap regardless). -->
                            <div class="flex flex-none items-center gap-1 rounded-full bg-euca-50/80 p-1" role="group" :aria-label="t('agents.columns.autonomy') + ' — ' + tool.name">
                                <button
                                    v-for="level in tool.levels"
                                    :key="level.value"
                                    type="button"
                                    :disabled="!level.allowed"
                                    :aria-pressed="form.levels[tool.key] === level.value"
                                    :title="level.allowed ? t(`agents.levelHint.${level.value}`) : t('agents.ceiling.locked', { level: t(`agents.levels.${tool.ceiling}`) })"
                                    class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium transition"
                                    :class="[
                                        form.levels[tool.key] === level.value ? 'nav-pill-active text-ink' : 'text-ink-muted',
                                        level.allowed ? 'hover:text-ink' : 'cursor-not-allowed opacity-45',
                                    ]"
                                    @click="select(tool, level)"
                                >
                                    <svg v-if="!level.allowed" class="h-3 w-3" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7" />
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                    </svg>
                                    {{ t(`agents.levels.${level.value}`) }}
                                </button>
                            </div>
                        </li>
                    </ul>

                    <div class="mt-6">
                        <Button type="submit" pill :block="false" :disabled="form.processing">{{ t('agents.save') }}</Button>
                    </div>
                </form>
            </Card>
        </div>
    </AppLayout>
</template>
