<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ageFromDateOnly, formatDateOnly } from '@/lib/date';

const { t } = useI18n();
const page = usePage();
const locale = computed(() => (page.props.locale as string) || 'en');

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

function clearSearch(): void {
    filters.q = '';
    filters.date_of_birth = '';
    router.get('/patients', {}, { preserveState: true, replace: true });
}

function initials(first: string, last: string): string {
    return `${first?.[0] ?? ''}${last?.[0] ?? ''}`.toUpperCase();
}

function formatDob(dob: string): string {
    // Date-only → parsed as local midnight so the calendar day never shifts (M-2).
    return formatDateOnly(dob, locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' }, dob);
}

function age(dob: string): number | null {
    return ageFromDateOnly(dob);
}

function statusLabel(status: string): string {
    return status ? status.charAt(0).toUpperCase() + status.slice(1) : '';
}
</script>

<template>
    <AppLayout>
        <Head :title="t('patients.index.title')" />
        <div class="space-y-6">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-subtle">
                        {{ t('patients.index.eyebrow') }}
                    </p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight text-ink">{{ t('patients.index.title') }}</h1>
                    <p class="mt-1 text-sm text-ink-muted">{{ t('patients.index.subtitle') }}</p>
                </div>
                <Link
                    href="/patients/register"
                    class="btn-glow inline-flex items-center gap-2 self-start rounded-xl px-4 py-2.5 text-sm font-semibold sm:self-auto"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                    {{ t('patients.index.register') }}
                </Link>
            </div>

            <div class="glass-card p-5">
                <form class="grid gap-3 md:grid-cols-[1fr_220px_140px]" @submit.prevent="search">
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-subtle">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="1.6" />
                                <path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                            </svg>
                        </span>
                        <input
                            id="patient-search"
                            v-model="filters.q"
                            :aria-label="t('patients.index.searchName')"
                            :placeholder="t('patients.index.searchPlaceholder')"
                            class="block w-full rounded-xl border border-line bg-surface-2 py-2.5 pl-10 pr-3 text-sm text-ink shadow-sm transition placeholder:text-ink-subtle focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                        />
                    </div>
                    <input
                        id="patient-dob"
                        v-model="filters.date_of_birth"
                        type="date"
                        :aria-label="t('patients.index.searchDob')"
                        class="block w-full rounded-xl border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink shadow-sm transition focus:border-euca-600 focus:outline-none focus:ring-2 focus:ring-euca-500/30"
                    />
                    <button
                        type="submit"
                        class="btn-glow inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold"
                    >
                        {{ t('patients.index.search') }}
                    </button>
                </form>
                <p class="mt-3 text-xs text-ink-subtle">{{ t('patients.index.searchHint') }}</p>
            </div>

            <div class="glass-card overflow-hidden p-2">
                <div v-if="patients.length > 0" class="flex items-center justify-between px-3 py-2">
                    <p class="text-sm font-medium text-ink">
                        {{ t('patients.index.matches', { count: patients.length }) }}
                    </p>
                    <p class="text-xs text-ink-subtle">{{ t('patients.index.sorted') }}</p>
                </div>

                <ul v-if="patients.length > 0" class="divide-y divide-line/70">
                    <li v-for="patient in patients" :key="patient.id">
                        <Link
                            :href="patient.show_url"
                            class="flex items-center gap-4 rounded-xl px-3 py-3 transition hover:bg-euca-50"
                        >
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-euca-200 text-sm font-semibold text-euca-900"
                            >
                                {{ initials(patient.first_name, patient.last_name) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-ink">
                                    {{ patient.first_name }} {{ patient.last_name }}
                                </span>
                                <span class="block text-xs text-ink-subtle">{{ patient.sex }}</span>
                            </span>
                            <span class="hidden shrink-0 items-center gap-4 sm:flex">
                                <span class="rounded-md bg-surface-2 px-2 py-0.5 font-mono text-xs text-ink-muted">
                                    {{ patient.mrn }}
                                </span>
                                <span class="w-36 text-right text-sm text-ink-muted">
                                    {{ formatDob(patient.date_of_birth) }}
                                    <template v-if="age(patient.date_of_birth) !== null">
                                        · {{ age(patient.date_of_birth) }} {{ t('patients.index.ageUnit') }}
                                    </template>
                                </span>
                                <span
                                    class="w-20 shrink-0 rounded-full px-2.5 py-1 text-center text-xs font-semibold"
                                    :class="
                                        patient.status === 'active'
                                            ? 'bg-euca-100 text-euca-800'
                                            : 'bg-surface-2 text-ink-muted'
                                    "
                                >
                                    {{ statusLabel(patient.status) }}
                                </span>
                            </span>
                            <svg class="h-4 w-4 shrink-0 text-ink-subtle" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </Link>
                    </li>
                </ul>

                <div v-else class="flex flex-col items-center justify-center px-6 py-16 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-euca-100 text-euca-700">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="1.6" />
                            <path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                    </span>
                    <p class="mt-4 text-sm text-ink-muted">{{ t('patients.index.empty') }}</p>
                    <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-xl border border-line bg-surface/70 px-4 py-2 text-sm font-semibold text-ink transition hover:bg-surface-2"
                            @click="clearSearch"
                        >
                            {{ t('patients.index.clearSearch') }}
                        </button>
                        <Link
                            href="/patients/register"
                            class="btn-glow inline-flex items-center rounded-xl px-4 py-2 text-sm font-semibold"
                        >
                            {{ t('patients.index.register') }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
