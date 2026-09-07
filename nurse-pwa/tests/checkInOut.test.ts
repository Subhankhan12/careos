import { beforeEach, describe, expect, test, vi } from 'vitest';
import {
    clearSessionKey,
    db,
    loadOutboxForReplay,
    pendingOutboxCount,
    setSessionToken,
} from '../src/storage/dayPackStore';
import { NO_LOCATION_REASON, queueCheckIn, queueCheckOut } from '../src/visitActions';
import type { VisitSummary } from '../src/types';

/*
 * QA-FIX.4e — the PWA can check in and out of a visit (P4-H3, D-205).
 *
 * The server has always implemented check_in/check_out with full EVV handling
 * (location / accuracy / manual reason). App.vue never imported either, so no visit could be started
 * or closed from the field, EVV was unreachable, queued vitals and notes referenced a visit that did
 * not exist, and no in_progress or missed visit could exist at all.
 *
 * This is WIRING, not a feature: the client already produced the exact payload both handlers need.
 * These tests pin the client half — the server half is covered by CheckInOutWiringTest.php.
 */

const sessionToken = 'plain-session-token-never-persisted';

function visit(id = 'visit-1', executionVisitId: string | null = null): VisitSummary {
    return {
        id,
        execution_visit_id: executionVisitId,
        scheduled_date: '2026-08-03',
        window_start_at: '2026-08-03T07:00:00Z',
        window_end_at: '2026-08-03T08:00:00Z',
        duration_minutes: 60,
        required_qualification: 'RN',
        status: 'assigned',
        nurse_resource_id: 'resource-1',
        address: { line1: '1 Care Street', line2: null, city: 'Zurich', postal: '8001', country: 'CH' },
        patient: {
            id: 'patient-1', mrn: 'MRN-1', name: 'Margrit Ackermann',
            date_of_birth: '1940-01-02', sex: 'female',
            allergies: [], medications: [], problems: [], care_plan_goals: [], vitals_history: {},
        },
        tasks: [],
    } as unknown as VisitSummary;
}

beforeEach(async () => {
    vi.restoreAllMocks();
    clearSessionKey();
    await db.delete();
    await db.open();
    await setSessionToken(sessionToken);
});

describe('P4-H3 — check in and check out are queueable from the client', () => {
    test('a check-in queues the EXISTING check_in action with the payload the server expects', async () => {
        await queueCheckIn(visit());

        const [entry] = await loadOutboxForReplay();

        expect(entry.type).toBe('check_in');
        // The server resolves the planned visit and creates/finds the execution visit by this uuid.
        expect(entry.payload.planned_visit_id).toBe('visit-1');
        expect(entry.payload.client_visit_uuid).toBe('offline-visit-1');
        expect(entry.payload.nurse_resource_id).toBe('resource-1');
    });

    test('a check-out queues the EXISTING check_out action against the same visit uuid', async () => {
        await queueCheckOut(visit('visit-1', 'exec-1'));

        const [entry] = await loadOutboxForReplay();

        expect(entry.type).toBe('check_out');
        expect(entry.payload.client_visit_uuid).toBe('offline-visit-1');
        expect(entry.payload.visit_id).toBe('exec-1');
    });

    test('both go through the SAME offline queue as every other action — they work offline', async () => {
        // No network is touched here at all: the actions land in the encrypted outbox exactly like
        // vitals and notes, and sync replays them when the nurse has signal.
        await queueCheckIn(visit());
        await queueCheckOut(visit());

        await expect(pendingOutboxCount()).resolves.toBe(2);
    });

    test('EVV HONESTY — a check-in with no GPS records the ABSENCE of a location, never a fabricated one', async () => {
        await queueCheckIn(visit());

        const [entry] = await loadOutboxForReplay();

        // The server accepts EITHER location OR manual_reason. This client captures no GPS, so it
        // says so (D-176/D-179) and invents no coordinates, accuracy or distance (D-170).
        expect(entry.payload.manual_reason).toBe(NO_LOCATION_REASON);
        expect(entry.payload.location).toBeUndefined();
        expect(entry.payload.accuracy_meters).toBeUndefined();
        expect(entry.payload.distance_meters).toBeUndefined();
        expect(JSON.stringify(entry.payload)).not.toContain('latitude');
        expect(JSON.stringify(entry.payload)).not.toContain('longitude');
    });

    test('the reason is a real sentence, not a code the server would store raw', async () => {
        // VisitService rejects an empty manual reason ("Manual location fallback requires a reason").
        expect(NO_LOCATION_REASON.trim().length).toBeGreaterThan(10);
    });
});
