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
    profile: {
        name: string;
        contact_email: string;
        contact_phone: string;
        address_line1: string;
        address_line2: string;
        city: string;
        postal_code: string;
        country: string;
        locale: string;
        timezone: string;
    };
    identity: { slug: string | null; region: string | null; status: string | null; plan: string | null };
    locales: string[];
    timezones: string[];
    billing: { currency: string; seller_name: string; seller_vat_id: string };
    currencies: string[];
    branches: Array<{ id: string; name: string; code: string; city: string | null; timezone: string; active: boolean }>;
    rolesUrl: string;
    branchesUrl: string;
    updateUrl: string;
    profileUpdateUrl: string;
}>();

const flash = computed(() => (page.props.flash as { status?: string } | undefined)?.status);

const identityItems = computed(() => [
    { label: t('settings.identity.slug'), value: props.identity.slug ?? '—' },
    { label: t('settings.identity.region'), value: (props.identity.region ?? '—').toUpperCase() },
    { label: t('settings.identity.status'), value: props.identity.status ?? '—' },
    { label: t('settings.identity.plan'), value: props.identity.plan ?? t('settings.identity.noPlan') },
]);

const profileForm = useForm({ ...props.profile });
const billingForm = useForm({ ...props.billing });

function saveProfile(): void {
    profileForm.post(props.profileUpdateUrl, { preserveScroll: true });
}
function saveBilling(): void {
    billingForm.post(props.updateUrl, { preserveScroll: true });
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

            <p v-if="flash === 'saved' || flash === 'unchanged' || flash === 'profileSaved'" class="rounded-2xl border border-success/30 bg-success-soft p-4 text-sm text-success">
                {{ t(`settings.flash.${flash}`) }}
            </p>

            <!-- Editable practice profile → tenant columns + locale/timezone settings. -->
            <Card :title="t('settings.profile.title')" :subtitle="t('settings.profile.subtitle')">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveProfile">
                    <Input id="p-name" v-model="profileForm.name" :label="t('settings.profile.name')" :error="profileForm.errors.name" />
                    <Input id="p-email" v-model="profileForm.contact_email" type="email" :label="t('settings.profile.contactEmail')" :error="profileForm.errors.contact_email" />
                    <Input id="p-phone" v-model="profileForm.contact_phone" :label="t('settings.profile.contactPhone')" :error="profileForm.errors.contact_phone" />
                    <Input id="p-addr1" v-model="profileForm.address_line1" :label="t('settings.profile.addressLine1')" :error="profileForm.errors.address_line1" />
                    <Input id="p-addr2" v-model="profileForm.address_line2" :label="t('settings.profile.addressLine2')" :error="profileForm.errors.address_line2" />
                    <Input id="p-city" v-model="profileForm.city" :label="t('settings.profile.city')" :error="profileForm.errors.city" />
                    <Input id="p-postal" v-model="profileForm.postal_code" :label="t('settings.profile.postalCode')" :error="profileForm.errors.postal_code" />
                    <Input id="p-country" v-model="profileForm.country" :label="t('settings.profile.country')" :error="profileForm.errors.country" />
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('settings.profile.locale') }}</span>
                        <select v-model="profileForm.locale" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option v-for="code in locales" :key="code" :value="code">{{ code.toUpperCase() }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('settings.profile.timezone') }}</span>
                        <select v-model="profileForm.timezone" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </select>
                    </label>
                    <div class="sm:col-span-2">
                        <Button type="submit" :block="false" :disabled="profileForm.processing">{{ t('settings.profile.save') }}</Button>
                    </div>
                </form>
            </Card>

            <!-- Read-only identity/plan. -->
            <Card :title="t('settings.identity.title')" :subtitle="t('settings.identity.subtitle')">
                <DataList :items="identityItems" />
            </Card>

            <!-- Editable billing identity + currency (SettingsService). -->
            <Card :title="t('settings.billing.title')" :subtitle="t('settings.billing.subtitle')">
                <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveBilling">
                    <label class="block">
                        <span class="mb-1.5 block text-sm font-medium text-ink">{{ t('settings.billing.currency') }}</span>
                        <select v-model="billingForm.currency" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-ink">
                            <option v-for="code in currencies" :key="code" :value="code">{{ code }}</option>
                        </select>
                    </label>
                    <div class="hidden sm:block"></div>
                    <Input id="seller-name" v-model="billingForm.seller_name" :label="t('settings.billing.sellerName')" :placeholder="t('settings.billing.sellerNamePlaceholder')" :error="billingForm.errors.seller_name" />
                    <Input id="seller-vat" v-model="billingForm.seller_vat_id" :label="t('settings.billing.sellerVatId')" :placeholder="t('settings.billing.sellerVatIdPlaceholder')" :error="billingForm.errors.seller_vat_id" />
                    <div class="sm:col-span-2">
                        <Button type="submit" :block="false" :disabled="billingForm.processing">{{ t('settings.billing.save') }}</Button>
                    </div>
                </form>
            </Card>

            <!-- Branch summary + management link. -->
            <Card :title="t('settings.branches.title')" :subtitle="t('settings.branches.subtitle')">
                <table v-if="branches.length" class="w-full text-left text-sm">
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
                <p v-else class="text-sm text-ink-muted">{{ t('settings.branches.empty') }}</p>
                <Link :href="branchesUrl" class="mt-4 inline-flex text-sm font-semibold text-euca-700 hover:text-euca-800">{{ t('settings.branches.manage') }}</Link>
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
