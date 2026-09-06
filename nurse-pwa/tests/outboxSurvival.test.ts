import { beforeEach, describe, expect, test, vi } from 'vitest';
import { login, syncOutbox } from '../src/api';
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
import type { DayPack } from '../src/types';

/*
 * QA-FIX.4c — queued care survives a reload and a session expiry (P4-C2, P4-C3, D-203).
 *
 * Phase 4 measured two ways to destroy recorded care:
 *   P4-C2  a page reload discarded the in-memory session key, so outbox ciphertext written under it
 *          could never be decrypted again — the queue was stranded for ever and no sync request was
 *          ever issued.
 *   P4-C3  a 401/403 on reconnect called wipeLocalStore(), which cleared the OUTBOX along with the
 *          cache — deleting care the server had never received, with the UI then reading "Pending
 *          offline actions: 0".
 *
 * The fix splits the two lifetimes: the day-pack CACHE keeps the session-derived key (wiped on
 * 401/403, unreadable once the tab closes), while the OUTBOX uses a device-lifetime non-extractable
 * key stored in IndexedDB. These tests pin both halves AND the security property that must survive.
 */

const sessionToken = 'plain-session-token-never-persisted';
const laterToken = 'a-different-token-after-re-login';
const knownAllergy = 'Penicillin';

function pack(): DayPack {
    return {
        date: '2026-08-03',
        nurse: { id: 1, name: 'Nora Nurse' },
        visits: [
            {
                id: 'visit-1',
                execution_visit_id: null,
                scheduled_date: '2026-08-03',
                window_start_at: '2026-08-03T07:00:00Z',
                window_end_at: '2026-08-03T08:00:00Z',
                duration_minutes: 60,
                required_qualification: 'RN',
                status: 'assigned',
                nurse_resource_id: 'resource-1',
                address: { line1: '1 Care Street', line2: null, city: 'Zurich', postal: '8001', country: 'CH' },
                patient: {
                    id: 'patient-1',
                    mrn: 'MRN-1',
                    name: 'Margrit Ackermann',
                    date_of_birth: '1940-01-02',
                    sex: 'female',
                    allergies: [{ substance: knownAllergy, reaction: 'Anaphylaxis', severity: 'severe' }],
                    medications: [],
                    problems: [],
                    care_plan_goals: [],
                    vitals_history: {},
                },
                tasks: [],
            },
        ],
    } as unknown as DayPack;
}

/** Everything Dexie actually holds, as text — used to prove no plaintext PHI is written. */
async function rawIndexedDbPayload(): Promise<string> {
    return JSON.stringify(await db.encryptedRecords.toArray());
}

/**
 * A page reload, as faithfully as this environment allows: the module-level session key and token
 * are gone, the IndexedDB file is not. This is exactly the state P4-C2 produced.
 */
function simulateReload(): void {
    clearSessionKey();
}

beforeEach(async () => {
    vi.restoreAllMocks();
    localStorage.clear();
    sessionStorage.clear();
    clearSessionKey();
    await db.delete();
    await db.open();
});

describe('P4-C2 — a reload must not strand queued care', () => {
    test('queued actions are still READABLE after the in-memory session key is discarded', async () => {
        await setSessionToken(sessionToken);
        await enqueueOutboxAction('visit_vitals', { client_visit_uuid: 'v-1', systolic: 199, diastolic: 91 });

        simulateReload();
        expect(hasSessionKey()).toBe(false);

        // Pre-fix this threw "No in-memory day-pack key is available" and the queue was lost.
        const entries = await loadOutboxForReplay();

        expect(entries).toHaveLength(1);
        expect(entries[0].payload.systolic).toBe(199);
    });

    test('queued actions survive a reload FOLLOWED BY a re-login under a DIFFERENT token', async () => {
        await setSessionToken(sessionToken);
        await enqueueOutboxAction('visit_note', { client_visit_uuid: 'v-1', body: 'recorded before the reload' });

        simulateReload();
        // A new login mints a new token — the exact condition that made the old ciphertext
        // permanently undecryptable.
        await setSessionToken(laterToken);

        const entries = await loadOutboxForReplay();

        expect(entries).toHaveLength(1);
        expect(entries[0].payload.body).toBe('recorded before the reload');
        await expect(pendingOutboxCount()).resolves.toBe(1);
    });

    test('the pending count is accurate with NO session key at all — it never reads 0 for work that exists', async () => {
        await setSessionToken(sessionToken);
        await enqueueOutboxAction('visit_vitals', { client_visit_uuid: 'v-1', systolic: 141 });
        await enqueueOutboxAction('visit_note', { client_visit_uuid: 'v-1', body: 'second' });

        simulateReload();

        await expect(pendingOutboxCount()).resolves.toBe(2);
    });
});

describe('P4-C3 — a session expiry must not delete un-transmitted care', () => {
    test('wipeLocalStore PRESERVES the outbox while still clearing the cached day pack', async () => {
        await setSessionToken(sessionToken);
        await saveDayPack(pack());
        await enqueueOutboxAction('visit_vitals', { client_visit_uuid: 'v-1', systolic: 177, diastolic: 91 });

        await wipeLocalStore();

        // The care survives…
        await expect(pendingOutboxCount()).resolves.toBe(1);
        const entries = await loadOutboxForReplay();
        expect(entries[0].payload.systolic).toBe(177);

        // …and the session key is still cleared.
        expect(hasSessionKey()).toBe(false);
    });

    test('a 401 from /api/nurse/sync no longer destroys the queue — the D-182 shape', async () => {
        await setSessionToken(sessionToken);
        await enqueueOutboxAction('visit_vitals', { client_visit_uuid: 'v-1', systolic: 177 });

        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response('', { status: 401 }) as unknown as Response,
        );

        // The client still reports the revocation…
        await expect(syncOutbox()).rejects.toThrow('nurse.sync.revoked');

        // …but the un-transmitted care is still here. Pre-fix this was 0: the only copy was gone
        // and the UI then read "Pending offline actions: 0".
        await expect(pendingOutboxCount()).resolves.toBe(1);
        const entries = await loadOutboxForReplay();
        expect(entries[0].payload.systolic).toBe(177);
    });

    test('a 403 behaves the same way', async () => {
        await setSessionToken(sessionToken);
        await enqueueOutboxAction('visit_note', { client_visit_uuid: 'v-1', body: 'still mine' });

        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response('', { status: 403 }) as unknown as Response,
        );

        await expect(syncOutbox()).rejects.toThrow('nurse.sync.revoked');
        await expect(pendingOutboxCount()).resolves.toBe(1);
    });
});

describe('POSITIVE CONTROLS — the security properties this fix must not trade away', () => {
    test('the cached day pack IS still wiped on a session wipe (the property P4-C3 was protecting)', async () => {
        await setSessionToken(sessionToken);
        await saveDayPack(pack());
        await enqueueOutboxAction('visit_note', { client_visit_uuid: 'v-1', body: 'queued' });

        await wipeLocalStore();

        // The cache is gone: a stolen device yields no day pack. Re-establish a key and confirm
        // there is genuinely nothing to load, rather than something merely unreadable.
        await setSessionToken(laterToken);
        await expect(loadDayPack()).resolves.toBeNull();
    });

    test('NO plaintext PHI is written to the device by the new outbox path', async () => {
        await setSessionToken(sessionToken);
        await saveDayPack(pack());
        await enqueueOutboxAction('visit_note', {
            client_visit_uuid: 'v-1',
            body: 'QA4C plaintext probe — must never appear at rest',
        });

        const raw = await rawIndexedDbPayload();

        expect(raw).not.toContain('QA4C plaintext probe');
        expect(raw).not.toContain(knownAllergy);
        expect(raw).not.toContain('Margrit Ackermann');
        expect(raw).not.toContain(sessionToken);
    });

    test('the device key is NON-EXTRACTABLE and the session token is never persisted', async () => {
        await setSessionToken(sessionToken);
        await enqueueOutboxAction('visit_note', { client_visit_uuid: 'v-1', body: 'x' });

        const stored = await db.deviceKeys.get('outbox-device-key');

        expect(stored).toBeDefined();
        expect(stored!.key.extractable).toBe(false);

        // Its bytes cannot be read back out, even by our own code.
        await expect(crypto.subtle.exportKey('raw', stored!.key)).rejects.toBeTruthy();

        expect(localStorage.length).toBe(0);
        expect(sessionStorage.length).toBe(0);
        expect(JSON.stringify(await db.deviceKeys.toArray())).not.toContain(sessionToken);
    });

    test('a successful sync still DRAINS the outbox — preserving work must not mean never clearing it', async () => {
        await setSessionToken(sessionToken);
        const entry = await enqueueOutboxAction('visit_note', { client_visit_uuid: 'v-1', body: 'drain me' });

        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({ results: [{ client_uuid: entry.client_uuid, status: 'accepted', code: 'accepted', payload: {} }] }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            ) as unknown as Response,
        );

        await syncOutbox();

        await expect(pendingOutboxCount()).resolves.toBe(0);
    });
});
