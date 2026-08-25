<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';
import StatCard from '@/Components/StatCard.vue';

const { t } = useI18n();
const page = usePage();

interface Article {
    id: string;
    title: string;
    body: string;
    tags: string[];
    is_active: boolean;
    updatedAt: string | null;
    /** G5 — who last saved it, from the audit trail. `null` where nobody has, shown as "—". */
    lastSavedBy: string | null;
    lastSavedAt: string | null;
    /** A recorded COUNT of drafts that cited this article — never a score, never ranked. */
    groundedDrafts: number;
    updateUrl: string;
    toggleUrl: string;
}

const props = defineProps<{
    articles: Article[];
    storeUrl: string;
    counts: { active: number; archived: number };
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

/*
 * Search over the REAL fields only — title, body, tags. This filters client-side, which is safe
 * here and would not be on the governance dashboard: the controller returns the tenant's WHOLE
 * article list, not a page of it, so a local filter cannot disagree with the database (the
 * distinction BILLAR.P6 and GOV.P1 draw between filtering a complete set and re-slicing a page).
 */
const search = ref('');

function matches(article: Article): boolean {
    const needle = search.value.trim().toLowerCase();
    if (needle === '') return true;

    return (
        article.title.toLowerCase().includes(needle) ||
        article.body.toLowerCase().includes(needle) ||
        article.tags.some((tag) => tag.toLowerCase().includes(needle))
    );
}

const activeArticles = computed(() => props.articles.filter((a) => a.is_active && matches(a)));
const archivedArticles = computed(() => props.articles.filter((a) => !a.is_active && matches(a)));
const anyMatch = computed(() => activeArticles.value.length + archivedArticles.value.length > 0);

/** Display only — a full timestamp, so plain locale formatting is right (D-091 does not apply). */
function savedLine(article: Article): string {
    if (article.lastSavedBy === null) return t('kb.savedBy.never');

    return t('kb.savedBy.by', {
        name: article.lastSavedBy,
        when: article.lastSavedAt ? new Date(article.lastSavedAt).toLocaleString() : '—',
    });
}

function splitTags(value: string): string[] {
    return value
        .split(',')
        .map((tag) => tag.trim())
        .filter(Boolean);
}

// Create.
const createForm = useForm({ title: '', body: '', tags: '', is_active: true });
function submitCreate(): void {
    createForm
        .transform((data) => ({ ...data, tags: splitTags(data.tags) }))
        .post(props.storeUrl, { preserveScroll: true, onSuccess: () => createForm.reset() });
}

// Edit (one article open at a time).
const editingId = ref<string | null>(null);
const editForm = useForm({ title: '', body: '', tags: '', is_active: true });
function startEdit(article: Article): void {
    editingId.value = article.id;
    editForm.title = article.title;
    editForm.body = article.body;
    editForm.tags = article.tags.join(', ');
    editForm.is_active = article.is_active;
    editForm.clearErrors();
}
function submitEdit(article: Article): void {
    editForm
        .transform((data) => ({ ...data, tags: splitTags(data.tags) }))
        .post(article.updateUrl, { preserveScroll: true, onSuccess: () => (editingId.value = null) });
}
function toggle(article: Article): void {
    router.post(article.toggleUrl, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('kb.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('kb.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('kb.title') }}</h1>
                <p class="mt-1 max-w-2xl text-sm text-ink-muted">{{ t('kb.subtitle') }}</p>
            </div>

            <p v-if="flash" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">{{ t(`kb.flash.${flash}`) }}</p>

            <!-- Only ACTIVE articles are grounded on by the Front-Desk agent. -->
            <div class="flex items-start gap-2 rounded-2xl border border-euca-200 bg-euca-50 p-4 text-sm text-euca-800">
                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                    <path d="M12 8v5M12 16h.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                {{ t('kb.grounding') }}
            </div>

            <!-- Plain row counts — recorded facts, so a CLOSED tile is the right home for them
                 (D-166). Nothing derived, nothing scored, and neither tile is a filter. -->
            <div class="grid gap-4 sm:grid-cols-2">
                <StatCard :label="t('kb.counts.active')" :value="String(counts.active)" :hint="t('kb.counts.activeHint')" />
                <StatCard :label="t('kb.counts.archived')" :value="String(counts.archived)" :hint="t('kb.counts.archivedHint')" />
            </div>

            <!-- New article. -->
            <Card :title="t('kb.new.title')" :subtitle="t('kb.new.subtitle')">
                <form class="space-y-4" @submit.prevent="submitCreate">
                    <Input id="kb-title" v-model="createForm.title" :label="t('kb.fields.title')" :error="createForm.errors.title" />
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('kb.fields.body') }}</span>
                        <textarea v-model="createForm.body" rows="4" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink"></textarea>
                        <span v-if="createForm.errors.body" class="mt-1 block text-xs text-danger">{{ createForm.errors.body }}</span>
                    </label>
                    <Input id="kb-tags" v-model="createForm.tags" :label="t('kb.fields.tags')" :placeholder="t('kb.fields.tagsPlaceholder')" />
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input v-model="createForm.is_active" type="checkbox" class="rounded border-line text-euca-700" />
                        {{ t('kb.fields.active') }}
                    </label>
                    <Button type="submit" :block="false" :disabled="createForm.processing">{{ t('kb.new.submit') }}</Button>
                </form>
            </Card>

            <!-- Existing articles, grouped by the state that actually matters: an ACTIVE article is
                 one the agent may ground on, an archived one is not. -->
            <Card :title="t('kb.list.title')" :subtitle="t('kb.list.subtitle')">
                <Input id="kb-search" v-model="search" :label="t('kb.search.label')" :placeholder="t('kb.search.placeholder')" />

                <p v-if="!articles.length" class="mt-4 text-sm text-ink-muted">{{ t('kb.list.empty') }}</p>
                <p v-else-if="!anyMatch" class="mt-4 text-sm text-ink-muted">{{ t('kb.search.noMatch', { term: search }) }}</p>

                <template v-for="group in [
                    { key: 'active', rows: activeArticles },
                    { key: 'archived', rows: archivedArticles },
                ]" :key="group.key">
                    <div v-if="group.rows.length" class="mt-5">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ t(`kb.list.group.${group.key}`) }}</h3>
                        <ul class="mt-3 space-y-3">
                            <li v-for="article in group.rows" :key="article.id" class="rounded-2xl border border-line p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-ink">{{ article.title }}</span>
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="article.is_active ? 'bg-success-soft text-success' : 'bg-euca-50 text-ink-muted'"
                                    >
                                        {{ article.is_active ? t('kb.list.active') : t('kb.list.inactive') }}
                                    </span>
                                </div>
                                <p class="mt-1 line-clamp-2 max-w-2xl text-sm text-ink-muted">{{ article.body }}</p>
                                <div v-if="article.tags.length" class="mt-2 flex flex-wrap gap-1.5">
                                    <span v-for="tag in article.tags" :key="tag" class="inline-flex items-center rounded-full bg-euca-50 px-2 py-0.5 text-xs text-euca-700">{{ tag }}</span>
                                </div>
                                <!-- G5: read from the audit trail. An article nobody has saved through
                                     the app says so, rather than being attributed to someone. -->
                                <p class="mt-2 text-xs text-ink-subtle">{{ savedLine(article) }}</p>
                                <!-- Recorded usage: drafts that cited this article. A COUNT, never a
                                     score, and the list is never ordered by it. -->
                                <p v-if="article.groundedDrafts > 0" class="mt-1 text-xs text-ink-subtle">
                                    {{ t('kb.grounded', { count: article.groundedDrafts }) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" class="rounded-xl border border-line px-3 py-1.5 text-sm font-semibold text-ink hover:bg-euca-50" @click="startEdit(article)">{{ t('kb.actions.edit') }}</button>
                                <button type="button" class="rounded-xl border border-line px-3 py-1.5 text-sm font-semibold text-ink hover:bg-euca-50" @click="toggle(article)">
                                    {{ article.is_active ? t('kb.actions.deactivate') : t('kb.actions.activate') }}
                                </button>
                            </div>
                        </div>

                        <!-- Inline edit. -->
                        <form v-if="editingId === article.id" class="mt-4 space-y-3 border-t border-line pt-4" @submit.prevent="submitEdit(article)">
                            <Input :id="`edit-title-${article.id}`" v-model="editForm.title" :label="t('kb.fields.title')" :error="editForm.errors.title" />
                            <label class="block">
                                <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('kb.fields.body') }}</span>
                                <textarea v-model="editForm.body" rows="4" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink"></textarea>
                                <span v-if="editForm.errors.body" class="mt-1 block text-xs text-danger">{{ editForm.errors.body }}</span>
                            </label>
                            <Input :id="`edit-tags-${article.id}`" v-model="editForm.tags" :label="t('kb.fields.tags')" :placeholder="t('kb.fields.tagsPlaceholder')" />
                            <label class="flex items-center gap-2 text-sm text-ink">
                                <input v-model="editForm.is_active" type="checkbox" class="rounded border-line text-euca-700" />
                                {{ t('kb.fields.active') }}
                            </label>
                            <div class="flex items-center gap-2">
                                <Button type="submit" :block="false" :disabled="editForm.processing">{{ t('kb.actions.save') }}</Button>
                                <button type="button" class="rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink hover:bg-euca-50" @click="editingId = null">{{ t('kb.actions.cancel') }}</button>
                            </div>
                        </form>
                            </li>
                        </ul>
                    </div>
                </template>
            </Card>

            <!-- What this page deliberately does NOT show, and why (the GOV.P1 precedent). -->
            <Card :title="t('kb.omitted.title')" :subtitle="t('kb.omitted.subtitle')">
                <ul class="space-y-1.5 text-sm text-ink-muted">
                    <li v-for="key in ['gaps', 'quality']" :key="key" class="flex items-start gap-2">
                        <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-ink-subtle" />
                        <span>{{ t(`kb.omitted.${key}`) }}</span>
                    </li>
                </ul>
            </Card>
        </div>
    </AppLayout>
</template>
