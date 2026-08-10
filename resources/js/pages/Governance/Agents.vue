<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';

const { t } = useI18n();
const page = usePage();

type Level = { value: string; allowed: boolean };
type ToolRow = { key: string; name: string; category: string };
type Agent = {
    id: string;
    key: string;
    name: string;
    status: string;
    level: string;
    ceiling: string;
    levels: Level[];
    tools: ToolRow[];
    configureUrl: string;
};

const props = defineProps<{
    agents: Agent[];
    levelOrder: string[];
    governanceUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// Per-agent draft (level + status), keyed by id — switching the selection just re-renders the same
// wired control for the selected agent. The server clamps the level to the ceiling on save.
const draft = reactive<Record<string, { level: string; status: string }>>({});
props.agents.forEach((agent) => {
    draft[agent.id] = { level: agent.level, status: agent.status };
});

// ── Tabs (Agents · Action ledger). The ledger tab content is P5 — the shell exists here. ──────
const tab = ref<'agents' | 'ledger'>('agents');

// ── Master-detail (over the already-loaded agents) ──────────────────────────────
const selectedId = ref<string>(props.agents[0]?.id ?? '');
const selected = computed<Agent | undefined>(() => props.agents.find((a) => a.id === selectedId.value) ?? props.agents[0]);

// The REAL runtime flow the pipeline visual describes — message → grounded draft → checked vs the
// ceiling → the electric fence → human review. Presentational; it names the pipeline that already
// runs, it does not add logic.
const flowSteps = ['message', 'draft', 'checked', 'fence', 'review'] as const;

function selectLevel(agent: Agent, level: Level): void {
    if (level.allowed) draft[agent.id].level = level.value;
}

function toggleStatus(agent: Agent): void {
    draft[agent.id].status = draft[agent.id].status === 'active' ? 'paused' : 'active';
}

function save(agent: Agent): void {
    router.post(agent.configureUrl, { autonomy_level: draft[agent.id].level, status: draft[agent.id].status }, { preserveScroll: true });
}

const dirty = (agent: Agent): boolean =>
    draft[agent.id].level !== agent.level || draft[agent.id].status !== agent.status;
</script>

<template>
    <AppLayout>
        <Head :title="t('agentConfig.title')" />
        <div class="settings-surface space-y-6">
            <!-- Header -->
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('agentConfig.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('agentConfig.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('agentConfig.subtitle') }}</p>
                <Link :href="governanceUrl" class="mt-2 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('agentConfig.backToGovernance') }}</Link>
            </div>

            <p v-if="flash === 'saved'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t('agentConfig.flash.saved') }}
            </p>

            <!-- Tabs: Agents · Action ledger (the ledger content is P5; the shell exists) -->
            <div class="flex items-center gap-1 rounded-full bg-euca-50/80 p-1 text-sm font-medium" role="tablist">
                <button type="button" role="tab" :aria-selected="tab === 'agents'" class="rounded-full px-4 py-1.5 transition" :class="tab === 'agents' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="tab = 'agents'">{{ t('agentConfig.tabs.agents') }}</button>
                <button type="button" role="tab" :aria-selected="tab === 'ledger'" class="rounded-full px-4 py-1.5 transition" :class="tab === 'ledger' ? 'nav-pill-active text-ink' : 'text-ink-muted hover:text-ink'" @click="tab = 'ledger'">{{ t('agentConfig.tabs.ledger') }}</button>
            </div>

            <!-- Action ledger tab — the activity feed lands in P5. Honest placeholder, no faked data. -->
            <div v-if="tab === 'ledger'" class="glass-card euca-card-in p-6 text-sm text-ink-muted">
                {{ t('agentConfig.ledger.placeholder') }}
            </div>

            <p v-else-if="agents.length === 0" class="glass-card euca-card-in p-6 text-sm text-ink-muted">{{ t('agentConfig.empty') }}</p>

            <!-- ── AGENTS: master-detail — 280px agent list | detail shell ───────────── -->
            <div v-else class="grid items-start gap-5 lg:grid-cols-[280px_1fr]">
                <!-- LEFT: selectable agent list -->
                <div class="glass-card euca-card-in space-y-1 p-3">
                    <button
                        v-for="agent in agents"
                        :key="agent.id"
                        type="button"
                        class="w-full rounded-xl border border-transparent p-3 text-left transition hover:bg-euca-50/60"
                        :class="agent.id === selectedId ? 'border-l-[3px] border-l-euca-600 bg-euca-50/80' : ''"
                        @click="selectedId = agent.id"
                    >
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-euca-100 text-euca-800">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3a4 4 0 0 1 4 4v1h1a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h1V7a4 4 0 0 1 4-4Zm-2 11h4M9.5 11h.01M14.5 11h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </span>
                            <span class="min-w-0 truncate font-semibold text-ink">{{ agent.name }}</span>
                        </div>
                        <p class="mt-1 font-mono text-[11px] text-ink-subtle">{{ agent.key }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="agent.status === 'active' ? 'bg-success-soft text-success' : 'bg-surface-2 text-ink-muted'">
                                <span class="h-1.5 w-1.5 rounded-full" :class="agent.status === 'active' ? 'bg-success' : 'bg-ink-subtle'"></span>{{ agent.status === 'active' ? t('agentConfig.status.active') : t('agentConfig.status.paused') }}
                            </span>
                            <span class="inline-flex items-center rounded-full bg-euca-50 px-2 py-0.5 text-[11px] font-medium text-euca-800">{{ t(`agents.levels.${agent.level}`) }}</span>
                        </div>
                    </button>
                </div>

                <!-- RIGHT: the selected agent's detail shell -->
                <div v-if="selected" :key="selected.id" class="space-y-5">
                    <!-- Dark hero — identity + effective ceiling. Live metrics arrive with the ledger (P5). -->
                    <div class="euca-card-in overflow-hidden rounded-3xl bg-euca-900 p-6 text-white/90 shadow-lg" :style="{ '--euca-card-delay': '0.02s' }">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200/80">{{ t('agentConfig.hero.eyebrow') }}</p>
                                <h2 class="mt-1 text-xl font-semibold text-white">{{ selected.name }}</h2>
                                <p class="mt-0.5 font-mono text-xs text-euca-200/70">{{ selected.key }}</p>
                            </div>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium">
                                <span class="h-1.5 w-1.5 rounded-full" :class="selected.status === 'active' ? 'bg-emerald-300' : 'bg-white/40'"></span>
                                {{ selected.status === 'active' ? t('agentConfig.status.active') : t('agentConfig.status.paused') }}
                            </span>
                        </div>
                        <div class="mt-5 flex flex-wrap items-center gap-x-8 gap-y-3">
                            <div>
                                <p class="text-[11px] uppercase tracking-wide text-euca-200/70">{{ t('agentConfig.hero.ceilingLabel') }}</p>
                                <p class="mt-0.5 text-lg font-semibold text-white">{{ t(`agents.levels.${selected.ceiling}`) }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide text-euca-200/70">{{ t('agentConfig.hero.configuredLabel') }}</p>
                                <p class="mt-0.5 text-lg font-semibold text-white">{{ t(`agents.levels.${selected.level}`) }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] uppercase tracking-wide text-euca-200/70">{{ t('agentConfig.hero.toolsLabel') }}</p>
                                <p class="mt-0.5 text-lg font-semibold text-white">{{ selected.tools.length }}</p>
                            </div>
                        </div>
                        <p class="mt-4 text-xs text-euca-200/70">{{ t('agentConfig.hero.metricsNote') }}</p>
                    </div>

                    <!-- Flow pipeline — the REAL runtime path, presented. No new logic. -->
                    <div class="glass-card euca-card-in p-6" :style="{ '--euca-card-delay': '0.08s' }">
                        <h3 class="text-base font-semibold text-ink">{{ t('agentConfig.pipeline.title') }}</h3>
                        <p class="mt-0.5 text-sm text-ink-muted">{{ t('agentConfig.pipeline.subtitle') }}</p>
                        <ol class="mt-4 flex flex-wrap items-stretch gap-2">
                            <li v-for="(step, i) in flowSteps" :key="step" class="flex items-center gap-2">
                                <div class="rounded-xl border border-euca-200 bg-euca-50/60 px-3 py-2">
                                    <p class="text-xs font-semibold text-euca-800">{{ t(`agentConfig.pipeline.steps.${step}.label`) }}</p>
                                    <p class="text-[11px] text-ink-muted">{{ t(`agentConfig.pipeline.steps.${step}.hint`) }}</p>
                                </div>
                                <svg v-if="i < flowSteps.length - 1" class="h-4 w-4 flex-none text-euca-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </li>
                        </ol>
                    </div>

                    <!-- THE AUTONOMY LADDER — offers only levels ≤ the effective ceiling; higher LOCKED. -->
                    <div class="glass-card euca-card-in p-6" :style="{ '--euca-card-delay': '0.14s' }">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-ink">{{ t('agentConfig.ladder.title') }}</h3>
                                <p class="mt-0.5 text-sm text-ink-muted">{{ t('agentConfig.ladder.subtitle') }}</p>
                            </div>
                            <Button type="button" pill :block="false" :disabled="!dirty(selected)" @click="save(selected)">{{ t('agentConfig.ladder.save') }}</Button>
                        </div>

                        <ul class="mt-4 space-y-2">
                            <li v-for="level in selected.levels" :key="level.value">
                                <button
                                    type="button"
                                    :disabled="!level.allowed"
                                    :aria-pressed="draft[selected.id].level === level.value"
                                    class="flex w-full items-start gap-3 rounded-2xl border p-3 text-left transition"
                                    :class="[
                                        draft[selected.id].level === level.value ? 'border-euca-500 bg-euca-50/70 ring-1 ring-euca-300' : 'border-line',
                                        level.allowed ? 'hover:border-euca-300' : 'cursor-not-allowed opacity-55',
                                    ]"
                                    @click="selectLevel(selected, level)"
                                >
                                    <span class="mt-0.5 inline-flex h-5 w-5 flex-none items-center justify-center rounded-full border" :class="draft[selected.id].level === level.value ? 'border-euca-600 bg-euca-600 text-white' : 'border-line text-transparent'">
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12l5 5L19 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2">
                                            <span class="font-medium text-ink">{{ t(`agents.levels.${level.value}`) }}</span>
                                            <span v-if="!level.allowed" class="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2 py-0.5 text-[11px] font-medium text-ink-muted">
                                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7" /><path d="M8 11V8a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" /></svg>
                                                {{ t('agentConfig.ladder.lockedBadge') }}
                                            </span>
                                        </span>
                                        <span class="mt-0.5 block text-xs text-ink-muted">{{ t(`agents.levelHint.${level.value}`) }}</span>
                                        <span v-if="!level.allowed" class="mt-0.5 block text-xs text-ink-subtle">{{ t('agentConfig.ladder.locked', { level: t(`agents.levels.${selected.ceiling}`) }) }}</span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                        <p class="mt-3 text-xs text-ink-subtle">{{ t('agentConfig.ladder.forgedNote') }}</p>
                    </div>

                    <!-- Status: active / paused. Paused → the agent is off (P1 resolver). -->
                    <div class="glass-card euca-card-in flex flex-wrap items-center gap-3 p-6" :style="{ '--euca-card-delay': '0.2s' }">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-ink">{{ t('agentConfig.pauseToggle.title') }}</h3>
                            <p class="mt-0.5 text-sm text-ink-muted">{{ t('agentConfig.pauseToggle.help') }}</p>
                        </div>
                        <span class="grow"></span>
                        <Button
                            type="button"
                            :variant="draft[selected.id].status === 'active' ? 'secondary' : 'primary'"
                            pill
                            :block="false"
                            @click="toggleStatus(selected)"
                        >
                            {{ draft[selected.id].status === 'active' ? t('agentConfig.pauseToggle.pause') : t('agentConfig.pauseToggle.resume') }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
