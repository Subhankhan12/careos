<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Input from '@/Components/Input.vue';

const { t } = useI18n();

const props = defineProps<{
    filters: { q: string; date_of_birth: string };
    patients: Array<{
        id: string;
        mrn: string;
        first_name: string;
        last_name: string;
        date_of_birth: string;
        sex: string;
        status: string;
        show_url: string;
    }>;
}>();

const filters = reactive({ ...props.filters });

function search(): void {
    router.get('/patients', filters, { preserveState: true, replace: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('patients.index.title')" />
        <div class="space-y-6">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-2xl font-semibold text-ink">{{ t('patients.index.title') }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('patients.index.subtitle') }}</p>
                </div>
                <Link href="/patients/register" class="inline-flex rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    {{ t('patients.index.register') }}
                </Link>
            </div>

            <Card>
                <form class="grid gap-4 md:grid-cols-[1fr_220px_140px]" @submit.prevent="search">
                    <Input id="patient-search" v-model="filters.q" :label="t('patients.index.searchName')" />
                    <Input id="patient-dob" v-model="filters.date_of_birth" type="date" :label="t('patients.index.searchDob')" />
                    <div class="flex items-end">
                        <Button type="submit">{{ t('patients.index.search') }}</Button>
                    </div>
                </form>
            </Card>

            <Card>
                <div class="overflow-hidden rounded-md border border-line">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-surface-muted text-left text-xs uppercase text-ink-subtle">
                            <tr>
                                <th class="px-4 py-3">{{ t('patients.fields.name') }}</th>
                                <th class="px-4 py-3">{{ t('patients.fields.mrn') }}</th>
                                <th class="px-4 py-3">{{ t('patients.fields.dateOfBirth') }}</th>
                                <th class="px-4 py-3">{{ t('patients.fields.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line bg-surface">
                            <tr v-for="patient in patients" :key="patient.id">
                                <td class="px-4 py-3">
                                    <Link :href="patient.show_url" class="font-semibold text-brand-700 hover:text-brand-900">
                                        {{ patient.first_name }} {{ patient.last_name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-ink-muted">{{ patient.mrn }}</td>
                                <td class="px-4 py-3 text-ink-muted">{{ patient.date_of_birth }}</td>
                                <td class="px-4 py-3 text-ink-muted">{{ patient.status }}</td>
                            </tr>
                            <tr v-if="patients.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-ink-muted">{{ t('patients.index.empty') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
