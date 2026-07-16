<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();

type Snippet = { id: string; scope: string; trigger: string; title: string; body: string; specialty: string | null; active: boolean };

const props = defineProps<{
    personal: Snippet[];
    shared: Snippet[];
    canManageShared: boolean;
    placeholders: string[];
    actions: { store_url: string; update_url: string; delete_url: string };
}>();

const create = reactive({ scope: 'personal', trigger: '', title: '', body: '', specialty: '' });

function wrap(placeholder: string): string {
    return `{{${placeholder}}}`;
}

function submitCreate(): void {
    useForm({ ...create }).post(props.actions.store_url, {
        preserveScroll: true,
        onSuccess: () => {
            create.trigger = '';
            create.title = '';
            create.body = '';
            create.specialty = '';
        },
    });
}

function remove(id: string): void {
    useForm({ id }).post(props.actions.delete_url, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('clinical.snippets.title')" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-semibold text-ink">{{ t('clinical.snippets.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('clinical.snippets.subtitle') }}</p>
            </div>

            <Card :title="t('clinical.snippets.placeholders')">
                <p class="text-sm text-ink-muted">{{ t('clinical.snippets.placeholdersHint') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <code v-for="p in placeholders" :key="p" class="rounded bg-surface-muted px-2 py-1 text-xs text-ink">{{ wrap(p) }}</code>
                </div>
            </Card>

            <Card :title="t('clinical.snippets.add')">
                <form class="space-y-3" @submit.prevent="submitCreate">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block text-sm">
                            <span class="mb-1.5 block font-medium text-ink">{{ t('clinical.snippets.scope') }}</span>
                            <select v-model="create.scope" class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                                <option value="personal">{{ t('clinical.snippets.personal') }}</option>
                                <option v-if="canManageShared" value="shared">{{ t('clinical.snippets.shared') }}</option>
                            </select>
                        </label>
                        <Input id="sn-trigger" v-model="create.trigger" :label="t('clinical.snippets.trigger')" />
                        <Input id="sn-title" v-model="create.title" :label="t('clinical.snippets.name')" />
                    </div>
                    <label class="block text-sm">
                        <span class="mb-1.5 block font-medium text-ink">{{ t('clinical.snippets.body') }}</span>
                        <textarea v-model="create.body" rows="4" class="w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink"></textarea>
                    </label>
                    <Button type="submit" :disabled="!create.trigger || !create.title || !create.body">{{ t('clinical.snippets.addButton') }}</Button>
                </form>
            </Card>

            <Card :title="t('clinical.snippets.personalLibrary')">
                <p v-if="personal.length === 0" class="text-sm text-ink-muted">{{ t('clinical.snippets.empty') }}</p>
                <div v-for="s in personal" :key="s.id" class="flex items-start justify-between border-b border-line/60 py-2">
                    <div>
                        <p class="text-sm font-semibold text-ink">.{{ s.trigger }} — {{ s.title }}</p>
                        <p class="whitespace-pre-line text-sm text-ink-muted">{{ s.body }}</p>
                    </div>
                    <button type="button" class="text-sm font-medium text-danger hover:opacity-80" @click="remove(s.id)">{{ t('clinical.snippets.delete') }}</button>
                </div>
            </Card>

            <Card :title="t('clinical.snippets.sharedLibrary')">
                <p v-if="shared.length === 0" class="text-sm text-ink-muted">{{ t('clinical.snippets.empty') }}</p>
                <div v-for="s in shared" :key="s.id" class="flex items-start justify-between border-b border-line/60 py-2">
                    <div>
                        <p class="text-sm font-semibold text-ink">.{{ s.trigger }} — {{ s.title }} <span v-if="s.specialty" class="text-xs text-ink-muted">· {{ s.specialty }}</span></p>
                        <p class="whitespace-pre-line text-sm text-ink-muted">{{ s.body }}</p>
                    </div>
                    <button v-if="canManageShared" type="button" class="text-sm font-medium text-danger hover:opacity-80" @click="remove(s.id)">{{ t('clinical.snippets.delete') }}</button>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
