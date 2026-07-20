<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();
const page = usePage();

interface Article {
    id: string;
    title: string;
    body: string;
    tags: string[];
    is_active: boolean;
    updateUrl: string;
    toggleUrl: string;
}

const props = defineProps<{ articles: Article[]; storeUrl: string }>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

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

            <!-- Existing articles. -->
            <Card :title="t('kb.list.title')" :subtitle="t('kb.list.subtitle')">
                <p v-if="!articles.length" class="text-sm text-ink-muted">{{ t('kb.list.empty') }}</p>
                <ul v-else class="space-y-3">
                    <li v-for="article in articles" :key="article.id" class="rounded-2xl border border-line p-4">
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
            </Card>
        </div>
    </AppLayout>
</template>
