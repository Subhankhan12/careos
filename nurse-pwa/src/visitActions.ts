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
