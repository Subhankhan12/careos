import Dexie, { type Table } from 'dexie';
import type { DayPack, OutboxEntry } from '../types';

interface EncryptedRecord {
    id: string;
    iv: string;
    ciphertext: string;
    updatedAt: string;
}

class NursePwaDatabase extends Dexie {
    encryptedRecords!: Table<EncryptedRecord, string>;

    constructor() {
        super('careos-nurse-pwa');
        this.version(1).stores({
            encryptedRecords: 'id',
        });
    }
}

export const db = new NursePwaDatabase();

const DAY_PACK_ID = 'today-day-pack';
const OUTBOX_PREFIX = 'outbox-';
const encoder = new TextEncoder();
const decoder = new TextDecoder();
let sessionKey: CryptoKey | null = null;

/**
 * D-E2 hard requirement:
 * - The AES key is derived from the session token with HKDF.
 * - The token and CryptoKey live only in this module's memory.
 * - Dexie stores only iv + AES-GCM ciphertext, never plaintext PHI.
 */
export async function setSessionToken(token: string): Promise<void> {
    const material = await crypto.subtle.importKey(
        'raw',
        encoder.encode(token),
        'HKDF',
        false,
        ['deriveKey'],
    );

    sessionKey = await crypto.subtle.deriveKey(
        {
            name: 'HKDF',
            hash: 'SHA-256',
            salt: await crypto.subtle.digest('SHA-256', encoder.encode(`careos-nurse-session:${token}`)),
            info: encoder.encode('careos-nurse-day-pack'),
        },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

export function clearSessionKey(): void {
    sessionKey = null;
}

export function hasSessionKey(): boolean {
    return sessionKey !== null;
}

export async function saveDayPack(dayPack: DayPack): Promise<void> {
    await putEncryptedRecord(DAY_PACK_ID, dayPack);
}

export async function enqueueOutboxAction(
    type: string,
    payload: Record<string, unknown>,
    deviceTimestamp: string = new Date().toISOString(),
): Promise<OutboxEntry> {
    const entry: OutboxEntry = {
        client_uuid: crypto.randomUUID?.() ?? fallbackUuid(),
        type,
        payload,
        device_timestamp: deviceTimestamp,
        sequence: await nextOutboxSequence(),
    };

    await putEncryptedRecord(outboxRecordId(entry), entry);

    return entry;
}

export async function loadDayPack(): Promise<DayPack | null> {
    const record = await db.encryptedRecords.get(DAY_PACK_ID);

    if (!record) {
        return null;
    }

    return decryptRecord<DayPack>(record);
}

export async function loadOutboxForReplay(): Promise<OutboxEntry[]> {
    const records = await db.encryptedRecords
        .where('id')
        .startsWith(OUTBOX_PREFIX)
        .toArray();
    const entries = await Promise.all(records.map((record) => decryptRecord<OutboxEntry>(record)));

    return entries.sort((left, right) => {
        const leftVisit = visitKey(left);
        const rightVisit = visitKey(right);

        if (leftVisit !== rightVisit) {
            return leftVisit.localeCompare(rightVisit);
        }

        return left.sequence - right.sequence;
    });
}

export async function removeOutboxEntries(clientUuids: string[]): Promise<void> {
    const records = await db.encryptedRecords
        .where('id')
        .startsWith(OUTBOX_PREFIX)
        .toArray();
    const toDelete: string[] = [];

    for (const record of records) {
        const entry = await decryptRecord<OutboxEntry>(record);
        if (clientUuids.includes(entry.client_uuid)) {
            toDelete.push(record.id);
        }
    }

    await db.encryptedRecords.bulkDelete(toDelete);
}

export async function pendingOutboxCount(): Promise<number> {
    return db.encryptedRecords
        .where('id')
        .startsWith(OUTBOX_PREFIX)
        .count();
}

export async function wipeLocalStore(): Promise<void> {
    await db.transaction('rw', db.encryptedRecords, async () => {
        await db.encryptedRecords.clear();
    });
    clearSessionKey();
}

function requireSessionKey(): CryptoKey {
    if (sessionKey === null) {
        throw new Error('No in-memory day-pack key is available.');
    }

    return sessionKey;
}

async function putEncryptedRecord(id: string, value: unknown): Promise<void> {
    const key = requireSessionKey();
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const plaintext = encoder.encode(JSON.stringify(value));
    const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext);

    await db.encryptedRecords.put({
        id,
        iv: bytesToBase64(iv),
        ciphertext: bytesToBase64(new Uint8Array(ciphertext)),
        updatedAt: new Date().toISOString(),
    });
}

async function decryptRecord<T>(record: EncryptedRecord): Promise<T> {
    const key = requireSessionKey();
    const plaintext = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: base64ToBytes(record.iv) },
        key,
        base64ToBytes(record.ciphertext),
    );

    return JSON.parse(decoder.decode(plaintext)) as T;
}

async function nextOutboxSequence(): Promise<number> {
    const records = await db.encryptedRecords
        .where('id')
        .startsWith(OUTBOX_PREFIX)
        .toArray();

    if (records.length === 0) {
        return 1;
    }

    const entries = await Promise.all(records.map((record) => decryptRecord<OutboxEntry>(record)));

    return Math.max(...entries.map((entry) => entry.sequence)) + 1;
}

function outboxRecordId(entry: OutboxEntry): string {
    return `${OUTBOX_PREFIX}${entry.sequence.toString().padStart(12, '0')}-${entry.client_uuid}`;
}

function visitKey(entry: OutboxEntry): string {
    return String(
        entry.payload.visit_id
        ?? entry.payload.client_visit_uuid
        ?? entry.payload.planned_visit_id
        ?? '',
    );
}

function fallbackUuid(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function bytesToBase64(bytes: Uint8Array): string {
    let value = '';
    bytes.forEach((byte) => {
        value += String.fromCharCode(byte);
    });

    return btoa(value);
}

function base64ToBytes(value: string): Uint8Array {
    const binary = atob(value);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}
