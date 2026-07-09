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

const knownAllergy = 'Penicillin';
const sessionToken = 'plain-session-token-never-persisted';

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
