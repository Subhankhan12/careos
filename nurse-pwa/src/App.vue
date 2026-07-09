<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { login, logout, syncDayPack } from './api';
import { hasSessionKey, loadDayPack, wipeLocalStore } from './storage/dayPackStore';
import { startIdleWipe } from './idle';
import type { DayPack, VisitSummary } from './types';

const { t } = useI18n();
const email = ref('');
const password = ref('');
const authenticated = ref(false);
const dayPack = ref<DayPack | null>(null);
const selectedVisitId = ref<string | null>(null);
const statusKey = ref('visits.empty');

const today = new Date().toISOString().slice(0, 10);
let stopIdle: (() => void) | null = null;

const selectedVisit = computed<VisitSummary | null>(() =>
    dayPack.value?.visits.find((visit) => visit.id === selectedVisitId.value) ?? null,
);

async function submitLogin(): Promise<void> {
    await login(email.value, password.value);
    authenticated.value = true;
    stopIdle?.();
    stopIdle = startIdleWipe(async () => {
        await wipeLocalStore();
        authenticated.value = false;
        dayPack.value = null;
        selectedVisitId.value = null;
    });
    await sync();
}

async function sync(): Promise<void> {
    dayPack.value = await syncDayPack(today);
    selectedVisitId.value = dayPack.value.visits[0]?.id ?? null;
    statusKey.value = 'visits.offline';
}

async function signOut(): Promise<void> {
    await logout();
    authenticated.value = false;
    dayPack.value = null;
    selectedVisitId.value = null;
}

onMounted(async () => {
    if (hasSessionKey()) {
        dayPack.value = await loadDayPack();
        authenticated.value = dayPack.value !== null;
    }
});

onUnmounted(() => {
    stopIdle?.();
});
</script>

<template>
    <main class="shell">
        <section v-if="!authenticated" class="login">
            <h1>{{ t('login.title') }}</h1>
            <label>
                <span>{{ t('login.email') }}</span>
                <input v-model="email" type="email" autocomplete="username" />
            </label>
            <label>
                <span>{{ t('login.password') }}</span>
                <input v-model="password" type="password" autocomplete="current-password" />
            </label>
            <button type="button" @click="submitLogin">{{ t('login.submit') }}</button>
        </section>

        <section v-else class="workspace">
            <header class="topbar">
                <div>
                    <p>{{ t('app.title') }}</p>
                    <strong>{{ dayPack?.date ?? today }}</strong>
                </div>
                <div class="actions">
                    <button type="button" @click="sync">{{ t('app.sync') }}</button>
                    <button type="button" @click="signOut">{{ t('app.logout') }}</button>
                </div>
            </header>

            <p class="status">{{ t(statusKey) }}</p>

            <div class="layout">
                <nav class="visit-list" :aria-label="t('visits.today')">
                    <button
                        v-for="visit in dayPack?.visits ?? []"
                        :key="visit.id"
                        type="button"
                        :class="{ selected: visit.id === selectedVisitId }"
                        @click="selectedVisitId = visit.id"
                    >
                        <strong>{{ visit.patient.name }}</strong>
                        <span>{{ visit.window_start_at }} - {{ visit.window_end_at }}</span>
                    </button>
                    <p v-if="(dayPack?.visits.length ?? 0) === 0">{{ t('visits.empty') }}</p>
                </nav>

                <article v-if="selectedVisit" class="detail">
                    <h2>{{ t('visits.detail') }}</h2>
                    <h3>{{ selectedVisit.patient.name }}</h3>
                    <p>{{ selectedVisit.address.line1 }} {{ selectedVisit.address.city }} {{ selectedVisit.address.postal }}</p>

                    <section class="allergy-banner">
                        <h4>{{ t('visits.allergies') }}</h4>
                        <p v-if="selectedVisit.patient.allergies.length === 0">{{ t('visits.noAllergies') }}</p>
                        <ul>
                            <li v-for="allergy in selectedVisit.patient.allergies" :key="allergy.id">
                                <strong>{{ allergy.substance }}</strong>
                                <span>{{ allergy.reaction }}</span>
                                <span>{{ allergy.severity }}</span>
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h4>{{ t('visits.medications') }}</h4>
                        <ul>
                            <li v-for="medication in selectedVisit.patient.medications" :key="medication.id">
                                {{ medication.name }} {{ medication.dose_text }}
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h4>{{ t('visits.problems') }}</h4>
                        <ul>
                            <li v-for="problem in selectedVisit.patient.problems" :key="problem.id">
                                {{ problem.description }} {{ problem.code }}
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h4>{{ t('visits.goals') }}</h4>
                        <ul>
                            <li v-for="goal in selectedVisit.patient.care_plan_goals" :key="goal.id">
                                {{ goal.description }} {{ goal.target_date }}
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h4>{{ t('visits.tasks') }}</h4>
                        <ul>
                            <li v-for="task in selectedVisit.tasks" :key="task.id">
                                {{ task.title }} {{ task.due_at }}
                            </li>
                        </ul>
                    </section>
                </article>
            </div>
        </section>
    </main>
</template>

<style scoped>
.shell {
    min-height: 100vh;
    background: #f8fafc;
    color: #0f172a;
    font-family: Inter, ui-sans-serif, system-ui, sans-serif;
}

.login,
.workspace {
    margin: 0 auto;
    max-width: 1120px;
    padding: 24px;
}

.login {
    display: grid;
    gap: 16px;
    max-width: 420px;
}

label {
    display: grid;
    gap: 6px;
}

input,
button {
    min-height: 44px;
    border-radius: 8px;
    border: 1px solid #94a3b8;
    font: inherit;
}

input {
    padding: 0 12px;
}

button {
    cursor: pointer;
    padding: 0 14px;
    background: #0f766e;
    color: white;
}

.topbar,
.layout,
.actions {
    display: flex;
    gap: 16px;
}

.topbar {
    align-items: center;
    justify-content: space-between;
}

.layout {
    align-items: flex-start;
}

.visit-list {
    display: grid;
    flex: 0 0 320px;
    gap: 8px;
}

.visit-list button {
    display: grid;
    gap: 4px;
    justify-items: start;
    min-height: 64px;
    background: white;
    color: #0f172a;
}

.visit-list button.selected {
    border-color: #0f766e;
    box-shadow: 0 0 0 2px #99f6e4;
}

.detail {
    display: grid;
    flex: 1;
    gap: 16px;
    min-width: 0;
}

.detail section {
    border-top: 1px solid #cbd5e1;
    padding-top: 12px;
}

.allergy-banner {
    border: 2px solid #b91c1c;
    background: #fff1f2;
    padding: 16px;
}

.status {
    color: #475569;
}

@media (max-width: 760px) {
    .topbar,
    .layout {
        display: grid;
    }

    .visit-list {
        flex-basis: auto;
    }
}
</style>
