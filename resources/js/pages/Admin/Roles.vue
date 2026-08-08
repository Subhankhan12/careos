<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';

const { t } = useI18n();
const page = usePage();

type Invite = { id: string; email: string; role: string | null; status: string; expiresAt: string; resendUrl: string; revokeUrl: string };

const props = defineProps<{
    currentUserId: number;
    users: Array<{ id: number; name: string; email: string; roles: string[]; currentRoleId: string | null }>;
    roles: Array<{ id: string; key: string; name: string }>;
    catalog: Array<{ key: string; name: string; permissions: Array<{ key: string; label: string }> }>;
    invites: Invite[];
    inviteUrl: string;
    assignUrl: string;
    settingsUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);
const inviteFlash = computed(() => ['invited', 'inviteResent', 'inviteRevoked'].includes(flash.value ?? ''));

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

// Invite a member — grants a built-in role template via the real RBAC path (server-side).
const inviteForm = useForm<{ email: string; role_id: string }>({ email: '', role_id: props.roles[0]?.id ?? '' });
function submitInvite(): void {
    inviteForm.post(props.inviteUrl, { preserveScroll: true, onSuccess: () => inviteForm.reset('email') });
}
function resendInvite(url: string): void {
    router.post(url, {}, { preserveScroll: true });
}
function revokeInvite(url: string): void {
    router.post(url, {}, { preserveScroll: true });
}

const flashKey = computed(() => {
    switch (flash.value) {
        case 'invited':
            return 'staffInvite.flash.invited';
        case 'inviteResent':
            return 'staffInvite.flash.resent';
        case 'inviteRevoked':
            return 'staffInvite.flash.revoked';
        default:
            return '';
    }
});
</script>

<template>
    <SettingsLayout>
        <Head :title="t('roles.title')" />
        <div class="settings-surface space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('roles.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('roles.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('roles.subtitle') }}</p>
            </div>

            <p v-if="lastAdminBlocked" class="rounded-2xl border border-danger/30 bg-danger-soft p-4 text-sm text-danger">{{ t('roles.lastAdmin') }}</p>
            <p v-else-if="flash === 'assigned' || flash === 'unchanged'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t(`roles.flash.${flash}`) }}
            </p>
            <p v-else-if="inviteFlash" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">{{ t(flashKey) }}</p>

            <!-- Team: current role + assign one of the built-in templates. -->
            <Card animate :style="{ '--euca-card-delay': '0.02s' }" :title="t('roles.team.title')" :subtitle="t('roles.team.subtitle')">
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

            <!-- Invite a member — email + a built-in role from the real catalog. Provisioning goes
                 through the real user-creation + RBAC path on accept (server-side). -->
            <Card animate :style="{ '--euca-card-delay': '0.06s' }" :title="t('staffInvite.form.title')" :subtitle="t('staffInvite.form.subtitle')">
                <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="submitInvite">
                    <label class="block flex-1">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('staffInvite.form.email') }}</span>
                        <input
                            v-model="inviteForm.email"
                            type="email"
                            :placeholder="t('staffInvite.form.emailPlaceholder')"
                            class="block w-full rounded-xl border bg-surface-2 px-3.5 py-2.5 text-sm text-ink shadow-sm transition focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                            :class="inviteForm.errors.email ? 'border-danger' : 'border-line focus:border-euca-600'"
                        />
                        <span v-if="inviteForm.errors.email" class="mt-1 block text-xs text-danger">{{ inviteForm.errors.email }}</span>
                    </label>
                    <label class="block sm:w-56">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('staffInvite.form.role') }}</span>
                        <select v-model="inviteForm.role_id" class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink">
                            <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                        </select>
                    </label>
                    <Button type="submit" pill :block="false" :disabled="inviteForm.processing">{{ t('staffInvite.form.submit') }}</Button>
                </form>

                <!-- Pending invites -->
                <div class="mt-6 border-t border-line pt-4">
                    <p class="text-sm font-semibold text-ink">{{ t('staffInvite.pending.title') }}</p>
                    <p class="mt-0.5 text-xs text-ink-muted">{{ t('staffInvite.pending.subtitle') }}</p>
                    <p v-if="invites.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('staffInvite.pending.empty') }}</p>
                    <ul v-else class="mt-3 divide-y divide-line">
                        <li v-for="invite in invites" :key="invite.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink">{{ invite.email }}</p>
                                <p class="text-xs text-ink-muted">{{ invite.role }}</p>
                            </div>
                            <div class="flex flex-none items-center gap-2">
                                <span class="rounded-full bg-warning-soft px-2.5 py-0.5 text-[11px] font-medium text-warning">{{ t('staffInvite.pending.badge') }}</span>
                                <Button type="button" variant="ghost" :block="false" @click="resendInvite(invite.resendUrl)">{{ t('staffInvite.pending.resend') }}</Button>
                                <Button type="button" variant="ghost" :block="false" @click="revokeInvite(invite.revokeUrl)">{{ t('staffInvite.pending.revoke') }}</Button>
                            </div>
                        </li>
                    </ul>
                </div>
            </Card>

            <!-- Read-only: what each role template grants. REFLECT-ONLY — no editable permission grid. -->
            <Card animate :style="{ '--euca-card-delay': '0.1s' }" :title="t('roles.catalog.title')" :subtitle="t('roles.catalog.subtitle')">
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
    </SettingsLayout>
</template>
