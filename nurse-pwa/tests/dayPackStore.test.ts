import { beforeEach, describe, expect, test, vi } from 'vitest';
import { currentBearerToken, login, logout, syncDayPack } from '../src/api';
import {
    clearSessionKey,
    db,
    hasSessionKey,
    loadDayPack,
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
