<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

const props = defineProps<{
    currentUserId: number;
    users: Array<{ id: number; name: string; email: string; roles: string[]; currentRoleId: string | null }>;
    roles: Array<{ id: string; key: string; name: string }>;
    catalog: Array<{ key: string; name: string; permissions: Array<{ key: string; label: string }> }>;
    assignUrl: string;
    settingsUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

// Per-row role selection, pre-set to each member's current role.
const selected = reactive<Record<number, string>>({});
props.users.forEach((u) => {
    selected[u.id] = u.currentRoleId ?? props.roles[0]?.id ?? '';
});

const form = useForm<{ user_id: number; role_id: string }>({ user_id: 0, role_id: '' });
const lastAdminBlocked = computed(() => !!form.errors.role);

function assign(userId: number): void {
    form.user_id = userId;
    form.role_id = selected[userId];
    form.post(props.assignUrl, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('roles.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('roles.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('roles.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('roles.subtitle') }}</p>
                <Link :href="settingsUrl" class="mt-2 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('roles.backToSettings') }}</Link>
            </div>

            <p v-if="lastAdminBlocked" class="rounded-2xl border border-danger/30 bg-danger-soft p-4 text-sm text-danger">{{ t('roles.lastAdmin') }}</p>
            <p v-else-if="flash === 'assigned' || flash === 'unchanged'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t(`roles.flash.${flash}`) }}
            </p>

            <!-- Team: current role + assign one of the built-in templates. -->
            <Card :title="t('roles.team.title')" :subtitle="t('roles.team.subtitle')">
                <p v-if="users.length === 0" class="text-sm text-ink-muted">{{ t('roles.team.empty') }}</p>
                <table v-else class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('roles.team.member') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('roles.team.role') }}</th>
                            <th class="py-2 font-medium">{{ t('roles.team.assign') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users" :key="user.id" class="border-b border-line/60">
                            <td class="py-3 pr-4">
                                <p class="font-medium text-ink">
                                    {{ user.name }}
                                    <span v-if="user.id === currentUserId" class="ml-1 text-xs font-normal text-ink-subtle">({{ t('roles.team.you') }})</span>
                                </p>
                                <p class="text-xs text-ink-muted">{{ user.email }}</p>
                            </td>
                            <td class="py-3 pr-4 text-ink-muted">{{ user.roles.length ? user.roles.join(', ') : t('roles.team.none') }}</td>
                            <td class="py-3">
                                <div class="flex items-center gap-2">
                                    <select v-model="selected[user.id]" class="rounded-md border border-line bg-surface px-3 py-1.5 text-sm text-ink">
                                        <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                                    </select>
                                    <Button type="button" variant="secondary" :block="false" :disabled="form.processing" @click="assign(user.id)">{{ t('roles.team.assignAction') }}</Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>

            <!-- Read-only: what each role template grants. -->
            <Card :title="t('roles.catalog.title')" :subtitle="t('roles.catalog.subtitle')">
                <div class="space-y-5">
                    <div v-for="role in catalog" :key="role.key">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-sm font-semibold text-ink">{{ role.name }}</h3>
                            <span class="text-xs text-ink-subtle">{{ t('roles.catalog.permissions', { count: role.permissions.length }, role.permissions.length) }}</span>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="perm in role.permissions" :key="perm.key" class="rounded-full bg-euca-50 px-2.5 py-0.5 text-xs text-euca-800" :title="perm.key">{{ perm.label }}</span>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
