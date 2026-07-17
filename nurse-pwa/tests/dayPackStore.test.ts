import { beforeEach, describe, expect, test, vi } from 'vitest';
import { currentBearerToken, login, logout, nextBackoffDelayMs, syncDayPack, syncOutbox, syncOutboxWithRetry } from '../src/api';
import {
    clearSessionKey,
    db,
    enqueueOutboxAction,
    hasSessionKey,
    loadDayPack,
    loadOutboxForReplay,
    pendingOutboxCount,
    saveDayPack,
    setSessionToken,
    wipeLocalStore,
} from '../src/storage/dayPackStore';
import { createIdleWipeScheduler } from '../src/idle';
import type { DayPack } from '../src/types';
import {
    autosaveVisitNote,
    queueIncidentReport,
    queueTaskDone,
    queueTaskNotDone,
    queueVisitPhoto,
    queueVisitSignature,
    queueVisitVitals,
} from '../src/visitActions';

const knownAllergy = 'Penicillin';
const sessionToken = 'plain-session-token-never-persisted';
const distinctiveVitalsAt = '2099-12-31 23:59:59';

function pack(): DayPack {
    return {
        date: '2026-08-03',
        nurse: { id: 1, name: 'Nora Nurse' },
        visits: [
            {
                id: 'visit-1',
                scheduled_date: '2026-08-03',
                window_start_at: '2026-08-03T07:00:00Z',
                window_end_at: '2026-08-03T08:00:00Z',
                duration_minutes: 60,
                required_qualification: 'RN',
                status: 'assigned',
                nurse_resource_id: 'resource-1',
                address: {
                    line1: '1 Care Street',
                    line2: null,
                    city: 'Zurich',
                    postal: '8001',
                    country: 'CH',
                },
                patient: {
                    id: 'patient-1',
                    mrn: 'MRN-000001',
                    name: 'Day Pack',
                    date_of_birth: '1940-01-02',
                    sex: 'female',
                    allergies: [{ id: 'allergy-1', substance: knownAllergy, reaction: 'Rash', severity: 'moderate' }],
                    medications: [],
                    problems: [],
                    care_plan_goals: [],
                    vitals_history: {
                        systolic: [
                            { recorded_at: distinctiveVitalsAt, value: 131, source: 'visit' },
                            { recorded_at: '2026-08-01 09:00:00', value: 118, source: 'clinic' },
                        ],
                        diastolic: [{ recorded_at: '2026-08-01 09:00:00', value: 76, source: 'clinic' }],
                        heart_rate: [],
                        temperature_c: [],
                        spo2: [],
                        weight_g: [],
                        height_mm: [],
                    },
                },
                tasks: [{ id: 'task-1', title: 'Bring supplies', description: null, due_at: '2026-08-03T07:30:00Z', priority: 'normal', status: 'open' }],
            },
        ],
    };
}

async function rawIndexedDbPayload(): Promise<string> {
    const rows = await db.encryptedRecords.toArray();

    return JSON.stringify(rows);
}

beforeEach(async () => {
    vi.restoreAllMocks();
    localStorage.clear();
    sessionStorage.clear();
    clearSessionKey();
    await db.delete();
    await db.open();
});

describe('offline visit execution actions', () => {
    test('task and raw vitals screens queue encrypted offline actions', async () => {
        const dayPack = pack();
        const visit = dayPack.visits[0];
        const task = visit.tasks[0];

        await setSessionToken(sessionToken);
        await queueTaskDone(visit, task);
        await queueTaskNotDone(visit, task, 'Patient declined this task');
        await queueVisitVitals(visit, {
            heart_rate: 72,
            spo2: 98,
            temperature_c: 36.7,
        });

        await expect(loadOutboxForReplay()).resolves.toMatchObject([
            { type: 'visit_task_done' },
            { type: 'visit_task_not_done', payload: { not_done_reason: 'Patient declined this task' } },
            { type: 'visit_vitals', payload: { heart_rate: 72, spo2: 98, temperature_c: 36.7 } },
        ]);
        await expect(pendingOutboxCount()).resolves.toBe(3);
    });

    test('note autosave writes to the encrypted outbox and survives reload', async () => {
        const knownNote = 'Patient drank tea before the visit ended';
        const visit = pack().visits[0];

        await setSessionToken(sessionToken);
        await autosaveVisitNote(visit, knownNote);

        expect(await rawIndexedDbPayload()).not.toContain(knownNote);

        clearSessionKey();
        await setSessionToken(sessionToken);

        await expect(loadOutboxForReplay()).resolves.toMatchObject([
            { type: 'visit_note', payload: { body: knownNote } },
        ]);
    });

    test('photo and signature blobs are stored encrypted with no plaintext in IndexedDB', async () => {
        const visit = pack().visits[0];
        const knownPhoto = btoa('plain photo content');
        const knownSignature = btoa('plain signature content');

        await setSessionToken(sessionToken);
        await queueVisitPhoto(visit, {
            data: `data:image/png;base64,${knownPhoto}`,
            mime_type: 'image/png',
            size_bytes: knownPhoto.length,
        });
        await queueVisitSignature(visit, {
            data: `data:image/png;base64,${knownSignature}`,
            mime_type: 'image/png',
            size_bytes: knownSignature.length,
        });

        const raw = await rawIndexedDbPayload();

        expect(raw).not.toContain(knownPhoto);
        expect(raw).not.toContain(knownSignature);
        expect(raw).not.toContain('plain photo content');
        expect(raw).not.toContain('plain signature content');
        await expect(loadOutboxForReplay()).resolves.toMatchObject([
            { type: 'visit_photo' },
            { type: 'visit_signature' },
        ]);
    });

    test('incident reports queue offline with reporter-selected severity', async () => {
        const visit = pack().visits[0];
        const knownIncident = 'Loose rug documented by nurse';

        await setSessionToken(sessionToken);
        await queueIncidentReport(visit, {
            occurred_at: '2026-08-03T07:15:00.000Z',
            category: 'safety',
            severity: 'high',
            description: knownIncident,
        });

        const raw = await rawIndexedDbPayload();

        expect(raw).not.toContain(knownIncident);
        await expect(loadOutboxForReplay()).resolves.toMatchObject([
            {
                type: 'incident_report',
                payload: {
                    category: 'safety',
                    severity: 'high',
                    description: knownIncident,
                },
            },
        ]);
    });
});

describe('encrypted day-pack store', () => {
    test('round-trips encrypted values and stores no plaintext PHI in IndexedDB', async () => {
        await setSessionToken(sessionToken);
        await saveDayPack(pack());

        await expect(loadDayPack()).resolves.toMatchObject({
            visits: [{ patient: { allergies: [{ substance: knownAllergy }] } }],
        });

        const raw = await rawIndexedDbPayload();

        expect(raw).not.toContain(knownAllergy);
        expect(raw).not.toContain('Day Pack');
        expect(raw).not.toContain(sessionToken);
    });

    test('recent vitals history round-trips through the encrypted store with no plaintext in IndexedDB', async () => {
        await setSessionToken(sessionToken);
        await saveDayPack(pack());

        const loaded = await loadDayPack();
        const systolic = loaded?.visits[0].patient.vitals_history.systolic ?? [];

        expect(systolic).toHaveLength(2);
        expect(systolic[0]).toEqual({ recorded_at: distinctiveVitalsAt, value: 131, source: 'visit' });
        expect(systolic[1].source).toBe('clinic');

        // The distinctive vitals timestamp must not appear as plaintext at rest.
        expect(await rawIndexedDbPayload()).not.toContain(distinctiveVitalsAt);
    });

    test('wipeLocalStore empties encrypted records and clears the in-memory key', async () => {
        await setSessionToken(sessionToken);
        await saveDayPack(pack());

        await wipeLocalStore();

        await expect(db.encryptedRecords.count()).resolves.toBe(0);
        expect(hasSessionKey()).toBe(false);
    });

    test('the session key is never written to persistent browser stores', async () => {
        await setSessionToken(sessionToken);
        await saveDayPack(pack());

        expect(localStorage.length).toBe(0);
        expect(sessionStorage.length).toBe(0);
        expect(await rawIndexedDbPayload()).not.toContain(sessionToken);
    });
});

describe('wipe triggers', () => {
    test('logout revokes through the API and wipes the local store', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    token_type: 'Bearer',
                    token: sessionToken,
                    expires_at: '2026-08-03T12:00:00Z',
                    user: { id: 1, name: 'Nora Nurse', tenant_id: 'tenant-1' },
                }),
            })
            .mockResolvedValueOnce({ ok: true, status: 204 }));

        await login('nora@example.test', 'password');
        await saveDayPack(pack());
        await logout();

        await expect(db.encryptedRecords.count()).resolves.toBe(0);
        expect(hasSessionKey()).toBe(false);
        expect(currentBearerToken()).toBeNull();
    });

    test('a 401 sync response wipes the local store for remote revocation', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 401 }));

        await setSessionToken(sessionToken);
        await saveDayPack(pack());

        await expect(syncDayPack('2026-08-03')).rejects.toThrow('nurse.sync.revoked');
        await expect(db.encryptedRecords.count()).resolves.toBe(0);
        expect(hasSessionKey()).toBe(false);
    });

    test('idle timeout invokes the same wipe path', async () => {
        await setSessionToken(sessionToken);
        await saveDayPack(pack());

        vi.useFakeTimers();

        try {
            createIdleWipeScheduler(() => wipeLocalStore(), 1000);
            await vi.advanceTimersByTimeAsync(1000);
        } finally {
            vi.useRealTimers();
        }

        await expect(db.encryptedRecords.count()).resolves.toBe(0);
        expect(hasSessionKey()).toBe(false);
    });
});

describe('encrypted offline outbox', () => {
    test('outbox persists across a reload when the same active session token is re-derived', async () => {
        await setSessionToken(sessionToken);
        await enqueueOutboxAction('note', {
            visit_id: 'visit-1',
            note_text: 'Observed note content after reload',
        }, '2026-08-03T07:20:00Z');

        clearSessionKey();
        await setSessionToken(sessionToken);

        await expect(loadOutboxForReplay()).resolves.toMatchObject([
            {
                type: 'note',
                sequence: 1,
                payload: { note_text: 'Observed note content after reload' },
            },
        ]);
    });

    test('outbox replay order preserves sequence order within each visit', async () => {
        await setSessionToken(sessionToken);
        await enqueueOutboxAction('note', { visit_id: 'visit-b', note_text: 'B1' });
        await enqueueOutboxAction('check_in', { visit_id: 'visit-a', manual_reason: 'A1' });
        await enqueueOutboxAction('note', { visit_id: 'visit-a', note_text: 'A2' });

        const replay = await loadOutboxForReplay();

        expect(replay.filter((entry) => entry.payload.visit_id === 'visit-a').map((entry) => entry.sequence))
            .toEqual([2, 3]);
    });

    test('outbox entries are removed only after server acknowledgement', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    token_type: 'Bearer',
                    token: sessionToken,
                    expires_at: '2026-08-03T12:00:00Z',
                    user: { id: 1, name: 'Nora Nurse', tenant_id: 'tenant-1' },
                }),
            })
            .mockResolvedValueOnce({ ok: false, status: 500 })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({
                    results: [{ client_uuid: (await loadOutboxForReplay())[0].client_uuid, status: 'accepted', code: 'accepted', payload: {} }],
                }),
            }));

        await login('nora@example.test', 'password');
        const first = await enqueueOutboxAction('note', { visit_id: 'visit-1', note_text: 'Ack me' });
        await enqueueOutboxAction('note', { visit_id: 'visit-1', note_text: 'Keep me' });

        await expect(syncOutbox()).rejects.toThrow('nurse.outbox.failed');
        await expect(pendingOutboxCount()).resolves.toBe(2);

        await expect(syncOutbox()).resolves.toMatchObject([{ client_uuid: first.client_uuid }]);
        await expect(pendingOutboxCount()).resolves.toBe(1);
    });

    test('retry loop uses exponential backoff before a successful acknowledgement', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    token_type: 'Bearer',
                    token: sessionToken,
                    expires_at: '2026-08-03T12:00:00Z',
                    user: { id: 1, name: 'Nora Nurse', tenant_id: 'tenant-1' },
                }),
            })
            .mockResolvedValueOnce({ ok: false, status: 500 })
            .mockResolvedValueOnce({ ok: false, status: 500 })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({
                    results: (await loadOutboxForReplay()).map((entry) => ({
                        client_uuid: entry.client_uuid,
                        status: 'accepted',
                        code: 'accepted',
                        payload: {},
                    })),
                }),
            }));
        const sleep = vi.fn(async () => undefined);

        await login('nora@example.test', 'password');
        await enqueueOutboxAction('note', { visit_id: 'visit-1', note_text: 'Retry me' });

        await expect(syncOutboxWithRetry(3, sleep)).resolves.toHaveLength(1);
        expect(sleep).toHaveBeenNthCalledWith(1, 1000);
        expect(sleep).toHaveBeenNthCalledWith(2, 2000);
        expect(nextBackoffDelayMs(10)).toBe(60000);
        await expect(pendingOutboxCount()).resolves.toBe(0);
    });

    test('outbox stores no plaintext PHI in IndexedDB', async () => {
        const knownObservation = 'Patient said the doorbell was broken';

        await setSessionToken(sessionToken);
        await enqueueOutboxAction('note', {
            visit_id: 'visit-1',
            note_text: knownObservation,
        });

        const raw = await rawIndexedDbPayload();

        expect(raw).not.toContain(knownObservation);
        expect(raw).not.toContain(sessionToken);
    });
});
