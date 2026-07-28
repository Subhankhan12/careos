<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';

// Surgical case detail (SURGERY.G2) — PRESENTATIONAL. Drives the legal-only lifecycle, records the team + the
// anesthetist-ASSIGNED ASA/Mallampati, and authors op notes by REUSING the sign-and-lock note editor. The
// ASA class is a value the anesthetist ASSIGNS (a recorded fact) — nothing here computes a surgical risk.
const { t } = useI18n();

type Note = { id: string; phase: string; status: string; version: number; edit_url: string };
type TeamMember = { id: string; name: string | null; team_role: string };
type Event = { event_type: string; reason: string | null; occurred_at: string };
type Option = { id: string; name: string };

const props = defineProps<{
    surgicalCase: {
        id: string;
        patient: { id: string; name: string };
        surgeon: string | null;
        procedure: string;
        status: string;
        status_reason: string | null;
        scheduled_at: string;
        stay_id: string | null;
        phase_times: Record<string, string | null>;
        asa: { class: string | null; mallampati: string | null; assessed_at: string | null };
    };
    team: TeamMember[];
    notes: Note[];
    available_transitions: string[];
    events: Event[];
    options: { staff: Option[]; team_roles: string[]; asa_classes: string[]; mallampati_classes: string[]; phases: string[] };
    actions: {
        can_manage: boolean;
        can_write_note: boolean;
        transition_url: string;
        team_url: string;
        anesthesia_url: string;
        note_url: string;
        checklist_url: string;
        supplies_url: string;
    };
}>();

const teamForm = reactive({ staff_profile_id: '', team_role: '' });
const asaForm = reactive({ asa_class: '', mallampati: '', anesthetist_id: '' });

function transition(status: string): void {
    router.post(props.actions.transition_url, { status }, { preserveScroll: true });
}
function addTeam(): void {
    router.post(props.actions.team_url, { ...teamForm }, { preserveScroll: true, onSuccess: () => Object.assign(teamForm, { staff_profile_id: '', team_role: '' }) });
}
function recordAsa(): void {
    router.post(props.actions.anesthesia_url, { ...asaForm }, { preserveScroll: true });
}
function authorNote(phase: string): void {
    router.post(props.actions.note_url, { phase }, { preserveScroll: true });
}
function fmt(iso: string | null): string {
    return iso ? iso.replace('T', ' ').slice(0, 16) : '—';
}
</script>

<template>
    <AppLayout>
        <Head :title="t('surgery.case.title')" />
        <div class="space-y-5">
            <!-- Header -->
            <div class="euca-tile-dark p-6">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-euca-200">{{ t('surgery.case.eyebrow') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-euca-50">{{ surgicalCase.patient.name }}</h1>
                <p class="mt-1 text-sm text-euca-200">{{ surgicalCase.procedure }}<template v-if="surgicalCase.surgeon"> · {{ surgicalCase.surgeon }}</template></p>
                <div class="mt-3 flex items-center gap-3">
                    <span class="inline-block rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-euca-50">{{ t(`surgery.status.${surgicalCase.status}`) }}</span>
                    <Link :href="actions.checklist_url" class="text-xs font-semibold text-euca-100 underline">{{ t('surgery.case.checklist') }}</Link>
                    <Link :href="actions.supplies_url" class="text-xs font-semibold text-euca-100 underline">{{ t('surgery.case.supplies') }}</Link>
                </div>
            </div>

            <!-- Lifecycle -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.case.lifecycle') }}</h2>
                <div v-if="actions.can_manage && available_transitions.length" class="mt-3 flex flex-wrap gap-2">
                    <button v-for="s in available_transitions" :key="s" type="button" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700" @click="transition(s)">
                        {{ t(`surgery.transition.${s}`) }}
                    </button>
                </div>
                <p v-else-if="!available_transitions.length" class="mt-3 text-sm text-ink-muted">{{ t('surgery.case.terminal') }}</p>
                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                    <div v-for="(iso, key) in surgicalCase.phase_times" :key="key">
                        <dt class="text-xs uppercase tracking-wide text-ink-subtle">{{ t(`surgery.phaseTime.${key}`) }}</dt>
                        <dd class="text-ink">{{ fmt(iso) }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Anesthetist-assigned ASA -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.case.anesthesia') }}</h2>
                <p class="mt-1 text-xs text-ink-muted">{{ t('surgery.case.anesthesiaHint') }}</p>
                <p class="mt-3 text-sm text-ink">
                    {{ t('surgery.case.asaClass') }}: <span class="font-semibold">{{ surgicalCase.asa.class ?? '—' }}</span>
                    · {{ t('surgery.case.mallampati') }}: <span class="font-semibold">{{ surgicalCase.asa.mallampati ?? '—' }}</span>
                </p>
                <form v-if="actions.can_manage" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="recordAsa">
                    <select v-model="asaForm.asa_class" class="rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">{{ t('surgery.case.asaClass') }}</option>
                        <option v-for="a in options.asa_classes" :key="a" :value="a">{{ a }}</option>
                    </select>
                    <select v-model="asaForm.mallampati" class="rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">{{ t('surgery.case.mallampati') }}</option>
                        <option v-for="m in options.mallampati_classes" :key="m" :value="m">{{ m }}</option>
                    </select>
                    <select v-model="asaForm.anesthetist_id" class="rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">{{ t('surgery.case.anesthetist') }}</option>
                        <option v-for="s in options.staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.case.recordAsa') }}</button>
                </form>
            </div>

            <!-- Team -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.case.team') }}</h2>
                <ul class="mt-3 divide-y divide-euca-100">
                    <li v-for="m in team" :key="m.id" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ m.name }}</span>
                        <span class="rounded-full bg-white/40 px-2.5 py-0.5 text-xs font-semibold text-ink-muted">{{ t(`surgery.role.${m.team_role}`) }}</span>
                    </li>
                    <li v-if="team.length === 0" class="py-2 text-sm text-ink-muted">{{ t('surgery.case.noTeam') }}</li>
                </ul>
                <form v-if="actions.can_manage" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="addTeam">
                    <select v-model="teamForm.staff_profile_id" class="rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">{{ t('surgery.case.staff') }}</option>
                        <option v-for="s in options.staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <select v-model="teamForm.team_role" class="rounded-xl border border-euca-200 bg-white/70 px-3 py-1.5 text-sm text-ink focus:border-euca-400 focus:outline-none">
                        <option value="">{{ t('surgery.case.role') }}</option>
                        <option v-for="r in options.team_roles" :key="r" :value="r">{{ t(`surgery.role.${r}`) }}</option>
                    </select>
                    <button type="submit" class="rounded-full bg-euca-600 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-euca-700">{{ t('surgery.case.addTeam') }}</button>
                </form>
            </div>

            <!-- Op documentation (reuses the sign-and-lock note editor) -->
            <div class="glass-card p-6">
                <h2 class="text-lg font-semibold tracking-tight text-ink">{{ t('surgery.case.notes') }}</h2>
                <ul class="mt-3 divide-y divide-euca-100">
                    <li v-for="n in notes" :key="n.id" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ t(`surgery.phase.${n.phase}`) }} <span class="text-ink-muted">· v{{ n.version }} · {{ t(`surgery.noteStatus.${n.status}`) }}</span></span>
                        <Link :href="n.edit_url" class="rounded-full bg-white/50 px-3 py-1 text-xs font-semibold text-ink transition hover:bg-white/70">{{ t('surgery.case.openNote') }}</Link>
                    </li>
                    <li v-if="notes.length === 0" class="py-2 text-sm text-ink-muted">{{ t('surgery.case.noNotes') }}</li>
                </ul>
                <div v-if="actions.can_write_note" class="mt-3 flex flex-wrap gap-2">
                    <button v-for="p in options.phases" :key="p" type="button" class="rounded-full bg-white/50 px-3 py-1.5 text-xs font-semibold text-ink transition hover:bg-white/70" @click="authorNote(p)">
                        {{ t('surgery.case.authorNote', { phase: t(`surgery.phase.${p}`) }) }}
                    </button>
                </div>
            </div>

            <!-- History (append-only) -->
            <div v-if="events.length" class="glass-card p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-ink-subtle">{{ t('surgery.case.history') }}</h2>
                <ul class="mt-3 divide-y divide-euca-100">
                    <li v-for="(e, i) in events" :key="i" class="flex items-center justify-between py-2 text-sm">
                        <span class="text-ink">{{ t(`surgery.transition.${e.event_type}`) }}<template v-if="e.reason"> · {{ e.reason }}</template></span>
                        <span class="text-xs text-ink-muted">{{ fmt(e.occurred_at) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
