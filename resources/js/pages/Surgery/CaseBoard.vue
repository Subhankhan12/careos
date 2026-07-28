<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Surgical case board (SURGERY.G2) — PRESENTATIONAL. The OR worklist + a lean "schedule a case" form. From a
// case, the detail page drives the legal-only lifecycle. Record-not-judge: nothing here computes a risk.
const { t } = useI18n();

type Case = { id: string; patient: string; surgeon: string | null; procedure: string; status: string; scheduled_at: string; show_url: string };
type Option = { id: string; name: string };

const props = defineProps<{
    cases: Case[];
    patients: Option[];
    surgeons: Option[];
    actions: { can_schedule: boolean; store_url: string };
}>();

const blank = { patient_id: '', primary_surgeon_id: '', procedure_description: '', scheduled_at: '' };
const form = reactive({ ...blank });

function submit(): void {
    router.post(props.actions.store_url, { ...form }, { preserveScroll: true, onSuccess: () => Object.assign(form, blank) });
}

function fmt(iso: string): string {
    return iso ? iso.replace('T', ' ').slice(0, 16) : '';
}
</script>

<template>
    <AppLayout>
        <Head :title="t('surgery.board.title')" />
        <div class="space-y-5">
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('surgery.board.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ t('surgery.board.title') }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ t('surgery.board.subtitle') }}</p>
            </div>

            <!-- Schedule a case -->
            <form v-if="actions.can_schedule" class="glass-card grid gap-3 p-6 sm:grid-cols-2 xl:grid-cols-4" @submit.prevent="submit">
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="c-patient">{{ t('surgery.board.patient') }}</label>
                    <select id="c-patient" v-model="form.patient_id" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">—</option>
                        <option v-for="p in patients" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="c-surgeon">{{ t('surgery.board.surgeon') }}</label>
                    <select id="c-surgeon" v-model="form.primary_surgeon_id" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">—</option>
                        <option v-for="s in surgeons" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="c-proc">{{ t('surgery.board.procedure') }}</label>
                    <input id="c-proc" v-model="form.procedure_description" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-wide text-ink-subtle" for="c-at">{{ t('surgery.board.scheduledAt') }}</label>
                    <input id="c-at" v-model="form.scheduled_at" type="datetime-local" class="mt-1 w-full rounded-xl border border-euca-200 bg-white/70 px-3 py-2 text-sm text-ink focus:border-euca-400 focus:outline-none" />
                </div>
                <div class="sm:col-span-2 xl:col-span-4">
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.board.schedule') }}</button>
                </div>
            </form>

            <!-- Case list -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.board.heading') }}</h2>
                <p v-if="cases.length === 0" class="mt-3 text-sm text-ink-muted">{{ t('surgery.board.empty') }}</p>
                <ul v-else class="mt-4 divide-y divide-euca-100">
                    <li v-for="c in cases" :key="c.id" class="flex items-center justify-between py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">{{ c.patient }} <span class="text-ink-muted">· {{ c.procedure }}</span></p>
                            <p class="text-xs text-ink-muted">{{ fmt(c.scheduled_at) }}<template v-if="c.surgeon"> · {{ c.surgeon }}</template></p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="rounded-full bg-white/40 px-2.5 py-0.5 text-xs font-semibold text-ink-muted">{{ t(`surgery.status.${c.status}`) }}</span>
                            <Link :href="c.show_url" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.board.open') }}</Link>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
