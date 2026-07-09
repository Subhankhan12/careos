<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { login, logout, syncDayPack, syncOutboxWithRetry } from './api';
import { hasSessionKey, loadDayPack, pendingOutboxCount, wipeLocalStore } from './storage/dayPackStore';
import { startIdleWipe } from './idle';
import {
    autosaveVisitNote,
    queueTaskDone,
    queueTaskNotDone,
    queueVisitPhoto,
    queueVisitSignature,
    queueVisitVitals,
} from './visitActions';
import type { DayPack, TaskSummary, VisitSummary } from './types';

const { t } = useI18n();
const email = ref('');
const password = ref('');
const authenticated = ref(false);
const dayPack = ref<DayPack | null>(null);
const selectedVisitId = ref<string | null>(null);
const statusKey = ref('visits.empty');
const pendingCount = ref(0);
const lastSyncedAt = ref<string | null>(null);
const errorKey = ref<string | null>(null);
const notDoneReasons = reactive<Record<string, string>>({});
const noteBody = ref('');
const signatureCanvas = ref<HTMLCanvasElement | null>(null);
const signatureDrawing = ref(false);
const rawVitals = reactive({
    systolic: null as number | null,
    diastolic: null as number | null,
    heart_rate: null as number | null,
    temperature_c: null as number | null,
    spo2: null as number | null,
    weight_g: null as number | null,
    height_mm: null as number | null,
});

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
    try {
        await syncOutboxWithRetry();
        dayPack.value = await syncDayPack(today);
        selectedVisitId.value = dayPack.value.visits[0]?.id ?? null;
        statusKey.value = 'visits.offline';
        lastSyncedAt.value = new Date().toISOString();
        errorKey.value = null;
    } catch {
        errorKey.value = 'sync.error';
    } finally {
        pendingCount.value = await pendingOutboxCount();
    }
}

async function signOut(): Promise<void> {
    await logout();
    authenticated.value = false;
    dayPack.value = null;
    selectedVisitId.value = null;
}

async function refreshPending(): Promise<void> {
    pendingCount.value = await pendingOutboxCount();
}

async function markTaskDone(visit: VisitSummary, task: TaskSummary): Promise<void> {
    await queueTaskDone(visit, task);
    await refreshPending();
}

async function markTaskNotDone(visit: VisitSummary, task: TaskSummary): Promise<void> {
    await queueTaskNotDone(visit, task, notDoneReasons[task.id] ?? '');
    await refreshPending();
}

async function queueVitals(visit: VisitSummary): Promise<void> {
    await queueVisitVitals(visit, { ...rawVitals });
    await refreshPending();
}

async function saveNote(visit: VisitSummary): Promise<void> {
    await autosaveVisitNote(visit, noteBody.value);
    await refreshPending();
}

async function queuePhotoFromInput(visit: VisitSummary, event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    await queueVisitPhoto(visit, {
        data: await readFileAsDataUrl(file),
        mime_type: file.type,
        size_bytes: file.size,
    });
    input.value = '';
    await refreshPending();
}

async function queueSignatureFromCanvas(visit: VisitSummary): Promise<void> {
    const data = signatureCanvas.value?.toDataURL('image/png') ?? '';

    await queueVisitSignature(visit, {
        data,
        mime_type: 'image/png',
        size_bytes: data.length,
    });
    await refreshPending();
}

function startSignature(event: PointerEvent): void {
    signatureDrawing.value = true;
    drawSignature(event);
}

function drawSignature(event: PointerEvent): void {
    if (!signatureDrawing.value || signatureCanvas.value === null) {
        return;
    }

    const canvas = signatureCanvas.value;
    const rect = canvas.getBoundingClientRect();
    const context = canvas.getContext('2d');

    if (context === null) {
        return;
    }

    context.fillStyle = '#0f172a';
    context.beginPath();
    context.arc(
        ((event.clientX - rect.left) / rect.width) * canvas.width,
        ((event.clientY - rect.top) / rect.height) * canvas.height,
        2,
        0,
        Math.PI * 2,
    );
    context.fill();
}

function endSignature(): void {
    signatureDrawing.value = false;
}

function readFileAsDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result ?? ''));
        reader.onerror = () => reject(reader.error ?? new Error('file.read.failed'));
        reader.readAsDataURL(file);
    });
}

onMounted(async () => {
    if (hasSessionKey()) {
        dayPack.value = await loadDayPack();
        authenticated.value = dayPack.value !== null;
        pendingCount.value = await pendingOutboxCount();
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
            <p class="status">
                {{ t('sync.pending', { count: pendingCount }) }}
                <span v-if="lastSyncedAt">{{ t('sync.lastSynced', { time: lastSyncedAt }) }}</span>
                <span v-if="errorKey">{{ t(errorKey) }}</span>
            </p>

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
                        <ul class="task-list">
                            <li v-for="task in selectedVisit.tasks" :key="task.id">
                                <span>{{ task.title }} {{ task.due_at }}</span>
                                <div class="task-actions">
                                    <button type="button" @click="markTaskDone(selectedVisit, task)">
                                        {{ t('visits.markDone') }}
                                    </button>
                                    <label>
                                        <span>{{ t('visits.notDoneReason') }}</span>
                                        <input v-model="notDoneReasons[task.id]" type="text" />
                                    </label>
                                    <button type="button" @click="markTaskNotDone(selectedVisit, task)">
                                        {{ t('visits.markNotDone') }}
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <section class="entry-panel">
                        <h4>{{ t('visits.vitals') }}</h4>
                        <div class="grid-fields">
                            <label>
                                <span>{{ t('visits.systolic') }}</span>
                                <input v-model.number="rawVitals.systolic" type="number" inputmode="numeric" />
                            </label>
                            <label>
                                <span>{{ t('visits.diastolic') }}</span>
                                <input v-model.number="rawVitals.diastolic" type="number" inputmode="numeric" />
                            </label>
                            <label>
                                <span>{{ t('visits.heartRate') }}</span>
                                <input v-model.number="rawVitals.heart_rate" type="number" inputmode="numeric" />
                            </label>
                            <label>
                                <span>{{ t('visits.temperatureC') }}</span>
                                <input v-model.number="rawVitals.temperature_c" type="number" step="0.1" inputmode="decimal" />
                            </label>
                            <label>
                                <span>{{ t('visits.spo2') }}</span>
                                <input v-model.number="rawVitals.spo2" type="number" inputmode="numeric" />
                            </label>
                            <label>
                                <span>{{ t('visits.weightG') }}</span>
                                <input v-model.number="rawVitals.weight_g" type="number" inputmode="numeric" />
                            </label>
                            <label>
                                <span>{{ t('visits.heightMm') }}</span>
                                <input v-model.number="rawVitals.height_mm" type="number" inputmode="numeric" />
                            </label>
                        </div>
                        <button type="button" @click="queueVitals(selectedVisit)">
                            {{ t('visits.queueVitals') }}
                        </button>
                    </section>

                    <section class="entry-panel">
                        <h4>{{ t('visits.note') }}</h4>
                        <label>
                            <span>{{ t('visits.noteBody') }}</span>
                            <textarea v-model="noteBody" @change="saveNote(selectedVisit)" />
                        </label>
                        <button type="button" @click="saveNote(selectedVisit)">
                            {{ t('visits.saveNote') }}
                        </button>
                    </section>

                    <section class="entry-panel">
                        <h4>{{ t('visits.attachments') }}</h4>
                        <label>
                            <span>{{ t('visits.photo') }}</span>
                            <input type="file" accept="image/png,image/jpeg,image/webp" capture="environment" @change="queuePhotoFromInput(selectedVisit, $event)" />
                        </label>
                        <canvas
                            ref="signatureCanvas"
                            class="signature-pad"
                            width="320"
                            height="120"
                            @pointerdown="startSignature"
                            @pointermove="drawSignature"
                            @pointerup="endSignature"
                            @pointerleave="endSignature"
                        />
                        <button type="button" @click="queueSignatureFromCanvas(selectedVisit)">
                            {{ t('visits.queueSignature') }}
                        </button>
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
textarea,
button {
    min-height: 44px;
    border-radius: 8px;
    border: 1px solid #94a3b8;
    font: inherit;
}

input {
    padding: 0 12px;
}

textarea {
    min-height: 120px;
    padding: 12px;
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

.task-list,
.task-actions,
.entry-panel,
.grid-fields {
    display: grid;
    gap: 10px;
}

.grid-fields {
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.signature-pad {
    min-height: 120px;
    max-width: 100%;
    border: 1px solid #94a3b8;
    background: white;
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
