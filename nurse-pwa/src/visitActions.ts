import { enqueueOutboxAction } from './storage/dayPackStore';
import type { OutboxEntry, TaskSummary, VisitSummary } from './types';

export interface RawVitalsPayload {
    systolic?: number | null;
    diastolic?: number | null;
    heart_rate?: number | null;
    temperature_c?: number | null;
    spo2?: number | null;
    weight_g?: number | null;
    height_mm?: number | null;
}

export interface LocalAttachmentPayload {
    data: string;
    mime_type: string;
    size_bytes: number;
}

export interface IncidentReportPayload {
    occurred_at: string;
    category: 'fall' | 'medication' | 'behaviour' | 'safety' | 'other';
    description: string;
    severity: 'low' | 'medium' | 'high';
}

function baseVisitPayload(visit: VisitSummary): Record<string, unknown> {
    const clientVisitUuid = clientVisitUuidFor(visit);

    return {
        planned_visit_id: visit.id,
        visit_id: visit.execution_visit_id ?? undefined,
        client_visit_uuid: clientVisitUuid,
        nurse_resource_id: visit.nurse_resource_id,
        patient_id: visit.patient.id,
    };
}

export function clientVisitUuidFor(visit: VisitSummary): string {
    return `offline-${visit.id}`;
}

export async function queueTaskDone(visit: VisitSummary, task: TaskSummary): Promise<OutboxEntry> {
    return enqueueOutboxAction('visit_task_done', {
        ...baseVisitPayload(visit),
        visit_id: task.visit_id ?? visit.execution_visit_id,
        visit_task_id: task.source === 'visit_task' ? task.id : undefined,
        task_id: task.source !== 'visit_task' ? task.id : undefined,
    });
}

export async function queueTaskNotDone(
    visit: VisitSummary,
    task: TaskSummary,
    reason: string,
): Promise<OutboxEntry> {
    return enqueueOutboxAction('visit_task_not_done', {
        ...baseVisitPayload(visit),
        visit_id: task.visit_id ?? visit.execution_visit_id,
        visit_task_id: task.source === 'visit_task' ? task.id : undefined,
        task_id: task.source !== 'visit_task' ? task.id : undefined,
        not_done_reason: reason,
    });
}

export async function queueVisitVitals(visit: VisitSummary, vitals: RawVitalsPayload): Promise<OutboxEntry> {
    return enqueueOutboxAction('visit_vitals', {
        ...baseVisitPayload(visit),
        ...vitals,
    });
}

export async function autosaveVisitNote(visit: VisitSummary, body: string): Promise<OutboxEntry> {
    return enqueueOutboxAction('visit_note', {
        ...baseVisitPayload(visit),
        body,
    });
}

/** What `saveVisitNoteOnce` last enqueued, so a repeat of the same gesture records nothing. */
export interface NoteDraftMemo {
    visitId: string | null;
    body: string;
}

export function emptyNoteDraftMemo(): NoteDraftMemo {
    return { visitId: null, body: '' };
}

/**
 * Save a note ONCE per gesture (QA-FIX.4d, P4-C5, D-204).
 *
 * The note textarea autosaves on `@change` and the Save button saves on `@click`. Clicking the
 * button BLURS the textarea, so one nurse gesture — type, press Save — fired both handlers, each
 * enqueuing an action with its own `client_uuid`. The server dedupes on `client_action_uuid`, so it
 * could not collapse them: two identical clinical notes reached the patient record.
 *
 * BOTH AFFORDANCES ARE KEPT — autosave-on-blur is deliberate for a field app, and an explicit Save
 * button is what a nurse expects to press. The duplicate is removed by making the save idempotent
 * for UNCHANGED text: the second event of one gesture has nothing new to record.
 *
 * The memo is keyed by visit, so the same words on a DIFFERENT visit are still a real, separate note.
 *
 * DELIBERATELY NOT a general "dedupe identical consecutive actions" rule: two identical vitals
 * readings minutes apart are legitimately two observations, and suppressing the second would DROP
 * recorded care — the failure this gate exists to fix.
 *
 * @returns the queued entry, or null when there was nothing new to record.
 */
export async function saveVisitNoteOnce(
    visit: VisitSummary,
    body: string,
    memo: NoteDraftMemo,
): Promise<OutboxEntry | null> {
    if (memo.visitId === visit.id && memo.body === body) {
        return null;
    }

    // CLAIM THE MEMO BEFORE AWAITING, NOT AFTER.
    //
    // `@change` and `@click` fire back-to-back on one gesture, so both calls are in flight at once.
    // Recording the memo after `await autosaveVisitNote()` let the second call read the stale memo
    // and enqueue anyway — the guard passed its sequential unit tests and still wrote two notes in a
    // real browser. Claiming first makes the second caller return null immediately.
    const previous = { visitId: memo.visitId, body: memo.body };

    memo.visitId = visit.id;
    memo.body = body;

    try {
        return await autosaveVisitNote(visit, body);
    } catch (error) {
        // Never swallow the nurse's work: if the enqueue failed, nothing was recorded, so the memo
        // must not claim it was.
        memo.visitId = previous.visitId;
        memo.body = previous.body;

        throw error;
    }
}

export async function queueVisitPhoto(
    visit: VisitSummary,
    attachment: LocalAttachmentPayload,
): Promise<OutboxEntry> {
    return enqueueOutboxAction('visit_photo', {
        ...baseVisitPayload(visit),
        ...attachment,
    });
}

export async function queueVisitSignature(
    visit: VisitSummary,
    attachment: LocalAttachmentPayload,
): Promise<OutboxEntry> {
    return enqueueOutboxAction('visit_signature', {
        ...baseVisitPayload(visit),
        ...attachment,
    });
}

export async function queueIncidentReport(
    visit: VisitSummary,
    incident: IncidentReportPayload,
): Promise<OutboxEntry> {
    return enqueueOutboxAction('incident_report', {
        ...baseVisitPayload(visit),
        ...incident,
    });
}
