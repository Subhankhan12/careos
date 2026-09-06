import { beforeEach, describe, expect, test, vi } from 'vitest';
import {
    clearSessionKey,
    db,
    loadOutboxForReplay,
    pendingOutboxCount,
    setSessionToken,
} from '../src/storage/dayPackStore';
import {
    emptyNoteDraftMemo,
    queueIncidentReport,
    queueVisitSignature,
    queueVisitVitals,
    saveVisitNoteOnce,
} from '../src/visitActions';
import type { VisitSummary } from '../src/types';

/*
 * QA-FIX.4d — one save gesture writes one note (P4-C5, D-204).
 *
 * App.vue binds @change on the note textarea AND @click on the Save button, both to saveNote().
 * Clicking the button BLURS the textarea, so one nurse gesture — type, press Save — fired both
 * handlers. Each enqueued an action with its own client_uuid, which the server's
 * client_action_uuid dedupe cannot collapse: Phase 4 drove it and got TWO identical visit_notes
 * rows on the server from one note typed once.
 *
 * Both affordances are kept; the save is made idempotent for unchanged text instead.
 */

const sessionToken = 'plain-session-token-never-persisted';

function visit(id = 'visit-1'): VisitSummary {
    return {
        id,
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

describe('P4-C5 — one gesture, one note', () => {
    test('THE GESTURE: blur then click (what a nurse actually does) enqueues exactly ONE note', async () => {
        const memo = emptyNoteDraftMemo();
        const v = visit();
        const body = 'QA4D single note typed once and saved once';

        // @change fires as the button takes focus…
        await saveVisitNoteOnce(v, body, memo);
        // …then @click fires. Pre-fix this queued a second, independent action.
        await saveVisitNoteOnce(v, body, memo);

        await expect(pendingOutboxCount()).resolves.toBe(1);

        const entries = await loadOutboxForReplay();
        expect(entries).toHaveLength(1);
        expect(entries[0].payload.body).toBe(body);
    });

    test('THE CONCURRENT GESTURE — both handlers in flight at once still enqueue exactly ONE', async () => {
        const memo = emptyNoteDraftMemo();
        const v = visit();
        const body = 'QA4D concurrent gesture';

        // This is what the browser actually does: @change and @click fire back-to-back, so BOTH
        // calls are in flight before either finishes. An earlier version of this fix recorded the
        // memo only AFTER awaiting the enqueue — it passed the sequential test above and still wrote
        // two notes in a real browser. Firing them without awaiting the first is the case that
        // catches it.
        const [a, b] = await Promise.all([
            saveVisitNoteOnce(v, body, memo),
            saveVisitNoteOnce(v, body, memo),
        ]);

        await expect(pendingOutboxCount()).resolves.toBe(1);
        expect([a, b].filter((entry) => entry !== null)).toHaveLength(1);
    });

    test('a FAILED enqueue does not leave the memo claiming work that was never recorded', async () => {
        const memo = emptyNoteDraftMemo();
        const v = visit();

        // Break the store so the enqueue throws.
        await db.close();
        await expect(saveVisitNoteOnce(v, 'will fail', memo)).rejects.toBeTruthy();
        await db.open();

        // The memo must be back to its previous state, so a retry of the SAME text still records.
        const retried = await saveVisitNoteOnce(v, 'will fail', memo);

        expect(retried).not.toBeNull();
        await expect(pendingOutboxCount()).resolves.toBe(1);
    });

    test('the second call returns null so the caller can tell nothing was recorded', async () => {
        const memo = emptyNoteDraftMemo();
        const v = visit();

        const first = await saveVisitNoteOnce(v, 'once', memo);
        const second = await saveVisitNoteOnce(v, 'once', memo);

        expect(first).not.toBeNull();
        expect(second).toBeNull();
    });

    test('AUTOSAVE-ON-BLUR IS PRESERVED — blurring without ever clicking still records the note', async () => {
        const memo = emptyNoteDraftMemo();
        const v = visit();

        // Only the @change handler runs: the nurse typed and navigated away.
        await saveVisitNoteOnce(v, 'typed then walked away', memo);

        await expect(pendingOutboxCount()).resolves.toBe(1);
        const entries = await loadOutboxForReplay();
        expect(entries[0].payload.body).toBe('typed then walked away');
    });

    test('EDITING AFTER SAVING records the new text — the guard must not swallow real work', async () => {
        const memo = emptyNoteDraftMemo();
        const v = visit();

        await saveVisitNoteOnce(v, 'first version', memo);
        await saveVisitNoteOnce(v, 'first version, now amended', memo);

        await expect(pendingOutboxCount()).resolves.toBe(2);
        const bodies = (await loadOutboxForReplay()).map((entry) => entry.payload.body);
        expect(bodies).toContain('first version');
        expect(bodies).toContain('first version, now amended');
    });

    test('THE SAME WORDS ON A DIFFERENT VISIT are a real, separate note', async () => {
        const memo = emptyNoteDraftMemo();
        const shared = 'Patient settled, no concerns.';

        await saveVisitNoteOnce(visit('visit-1'), shared, memo);
        await saveVisitNoteOnce(visit('visit-2'), shared, memo);

        // Two patients can legitimately receive the same sentence.
        await expect(pendingOutboxCount()).resolves.toBe(2);
    });
});

describe('POSITIVE CONTROLS — the other controls, and what must NOT be deduped', () => {
    test('the study found only the note double-bound: vitals, incident and signature each queue per call', async () => {
        const v = visit();

        // Every other control in App.vue has a single @click handler, so one gesture is already one
        // action. These assert the actions themselves are not accidentally suppressed by the fix.
        await queueVisitVitals(v, { systolic: 128, diastolic: 82 });
        await queueIncidentReport(v, {
            occurred_at: '2026-08-03T07:35:00.000Z', category: 'fall', severity: 'low', description: 'x',
        });
        await queueVisitSignature(v, { data: 'data:image/png;base64,AA==', mime_type: 'image/png', size_bytes: 12 });

        await expect(pendingOutboxCount()).resolves.toBe(3);
    });

    test('IDENTICAL VITALS TAKEN TWICE ARE TWO OBSERVATIONS — never deduped', async () => {
        const v = visit();

        // This is why the fix is scoped to the note draft and is NOT a general "drop identical
        // consecutive actions" rule: suppressing the second reading would DROP recorded care.
        await queueVisitVitals(v, { systolic: 128, diastolic: 82 });
        await queueVisitVitals(v, { systolic: 128, diastolic: 82 });

        await expect(pendingOutboxCount()).resolves.toBe(2);
    });
});
