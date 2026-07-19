<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import DataList from '@/Components/DataList.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();
const page = usePage();

const props = defineProps<{
    profile: { name: string | null; slug: string | null; region: string | null; status: string | null; plan: string | null };
    billing: { currency: string; seller_name: string; seller_vat_id: string };
    currencies: string[];
    branches: Array<{ id: string; name: string; code: string; city: string | null; country: string | null; timezone: string; active: boolean }>;
    rolesUrl: string;
    updateUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

const profileItems = computed(() => [
    { label: t('settings.profile.name'), value: props.profile.name ?? '—' },
    { label: t('settings.profile.slug'), value: props.profile.slug ?? '—' },
    { label: t('settings.profile.region'), value: (props.profile.region ?? '—').toUpperCase() },
    { label: t('settings.profile.status'), value: props.profile.status ?? '—' },
    { label: t('settings.profile.plan'), value: props.profile.plan ?? t('settings.profile.noPlan') },
]);

const form = useForm({
    currency: props.billing.currency,
    seller_name: props.billing.seller_name,
    seller_vat_id: props.billing.seller_vat_id,
});

function save(): void {
    form.post(props.updateUrl, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('settings.title')" />
        <div class="space-y-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-700">{{ t('settings.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('settings.title') }}</h1>
                <p class="mt-1 text-sm text-ink-muted">{{ t('settings.subtitle') }}</p>
            </div>

            <p v-if="flash === 'saved' || flash === 'unchanged'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t(`settings.flash.${flash}`) }}
            </p>

            <!-- Read-only practice profile (real data; editing has no backend yet). -->
            <Card :title="t('settings.profile.title')" :subtitle="t('settings.profile.subtitle')">
                <DataList :items="profileItems" />
            </Card>

            <!-- Editable — persisted through SettingsService. -->
            <Card :title="t('settings.billing.title')" :subtitle="t('settings.billing.subtitle')">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('settings.billing.currency') }}</span>
                        <select v-model="form.currency" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option v-for="code in currencies" :key="code" :value="code">{{ code }}</option>
                        </select>
                    </label>
                    <div class="hidden sm:block"></div>
                    <Input id="seller-name" v-model="form.seller_name" :label="t('settings.billing.sellerName')" :placeholder="t('settings.billing.sellerNamePlaceholder')" :error="form.errors.seller_name" />
                    <Input id="seller-vat" v-model="form.seller_vat_id" :label="t('settings.billing.sellerVatId')" :placeholder="t('settings.billing.sellerVatIdPlaceholder')" :error="form.errors.seller_vat_id" />
                    <div class="sm:col-span-2">
                        <Button type="submit" :block="false" :disabled="form.processing">{{ t('settings.billing.save') }}</Button>
                    </div>
                </form>
            </Card>

            <!-- Read-only branch list — no create/edit backend yet. -->
            <Card :title="t('settings.branches.title')" :subtitle="t('settings.branches.subtitle')">
                <p v-if="branches.length === 0" class="text-sm text-ink-muted">{{ t('settings.branches.empty') }}</p>
                <table v-else class="w-full text-left text-sm">
                    <thead class="text-ink-muted">
                        <tr class="border-b border-line">
                            <th class="py-2 pr-4 font-medium">{{ t('settings.profile.name') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('settings.branches.code') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('settings.branches.city') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ t('settings.branches.timezone') }}</th>
                            <th class="py-2 font-medium">{{ t('settings.branches.active') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="branch in branches" :key="branch.id" class="border-b border-line/60">
                            <td class="py-2 pr-4 text-ink">{{ branch.name }}</td>
                            <td class="py-2 pr-4 font-mono text-ink-muted">{{ branch.code }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ branch.city ?? '—' }}</td>
                            <td class="py-2 pr-4 text-ink-muted">{{ branch.timezone }}</td>
                            <td class="py-2 text-ink-muted">{{ branch.active ? t('settings.branches.active') : t('settings.branches.inactive') }}</td>
                        </tr>
                    </tbody>
                </table>
            </Card>

            <!-- Roles & access cross-link. -->
            <Card :title="t('settings.access.title')" :subtitle="t('settings.access.subtitle')">
                <Link :href="rolesUrl" class="inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('settings.access.link') }}</Link>
            </Card>

            <!-- Honest gaps note. -->
            <Card :title="t('settings.gaps.title')" :subtitle="t('settings.gaps.subtitle')">
                <p class="text-sm text-ink-muted">{{ t('settings.gaps.items') }}</p>
            </Card>
        </div>
    </AppLayout>
</template>
