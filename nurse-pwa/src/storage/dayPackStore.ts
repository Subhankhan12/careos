import Dexie, { type Table } from 'dexie';
import type { DayPack, OutboxEntry } from '../types';

interface EncryptedRecord {
    id: string;
    iv: string;
    ciphertext: string;
    updatedAt: string;
}

interface DeviceKeyRecord {
    id: string;
    key: CryptoKey;
}

class NursePwaDatabase extends Dexie {
    encryptedRecords!: Table<EncryptedRecord, string>;

    deviceKeys!: Table<DeviceKeyRecord, string>;

    constructor() {
        super('careos-nurse-pwa');
        this.version(1).stores({
            encryptedRecords: 'id',
        });
        // v2 adds the device-lifetime key store that lets the OUTBOX outlive a session (QA-FIX.4c).
        this.version(2).stores({
            encryptedRecords: 'id',
            deviceKeys: 'id',
        });
    }
}

export const db = new NursePwaDatabase();

const DAY_PACK_ID = 'today-day-pack';
const OUTBOX_PREFIX = 'outbox-';
const DEVICE_KEY_ID = 'outbox-device-key';
const encoder = new TextEncoder();
const decoder = new TextDecoder();
let sessionKey: CryptoKey | null = null;

/**
 * TWO KEYS, TWO LIFETIMES — because the data has two lifetimes (QA-FIX.4c, D-203).
 *
 * THREAT MODEL: a lost or stolen field device. Ciphertext at rest must not hand an attacker the
 * patient's allergies, medications, problems and vitals history.
 *
 * THE CACHE (day pack) keeps the original D-E2 property: its key is HKDF-derived from the session
 * token, lives only in this module's memory, and is never persisted. Close the tab and the cached
 * PHI is unreadable. `wipeLocalStore()` still clears it on 401/403.
 *
 * THE OUTBOX CANNOT SHARE THAT KEY, and that mismatch was the defect. The outbox holds care the
 * nurse has already recorded and the server has never seen; it MUST outlive the session, or a page
 * reload strands it for ever (P4-C2) and a token expiry deletes it outright (P4-C3). So the outbox
 * is encrypted under a DEVICE-lifetime AES-GCM key, generated `extractable: false` and stored as a
 * CryptoKey in IndexedDB: script can use it on this device but can never read its bytes out.
 *
 * RESIDUAL RISK, STATED PLAINLY: an attacker with the unlocked device who can run script in this
 * origin can now read QUEUED entries — what this nurse recorded on this round — where previously
 * they could read nothing. They still cannot read the day-pack cache, cannot extract the key, and
 * the outbox drains to empty on a successful sync. That is accepted deliberately: silently
 * destroying documented patient care is the worse failure.
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

    // Outbox entries use the DEVICE key so they survive a reload and a session expiry.
    await putEncryptedRecord(outboxRecordId(entry), entry, await deviceKey());

    return entry;
}

/**
 * The device-lifetime outbox key: generated once, non-extractable, stored in IndexedDB.
 *
 * `extractable: false` means script may USE this key on this device but can never read its raw
 * bytes, so it cannot be exfiltrated even if the page is compromised.
 */
async function deviceKey(): Promise<CryptoKey> {
    const existing = await db.deviceKeys.get(DEVICE_KEY_ID);

    if (existing) {
        return existing.key;
    }

    const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, [
        'encrypt',
        'decrypt',
    ]);

    await db.deviceKeys.put({ id: DEVICE_KEY_ID, key });

    return key;
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
    const entries = await decryptOutboxRecords(records);

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
    const key = await deviceKey();

    for (const record of records) {
        // A record we cannot read is one the server has never been told about; leave it alone
        // rather than deleting work on a guess.
        const entry = await tryDecrypt<OutboxEntry>(record, key);

        if (entry !== null && clientUuids.includes(entry.client_uuid)) {
            toDelete.push(record.id);
        }
    }

    await db.encryptedRecords.bulkDelete(toDelete);
}

/**
 * Decrypt outbox records, skipping any that cannot be read.
 *
 * A device upgrading from the pre-QA-FIX.4c build carries outbox rows encrypted under a session key
 * that no longer exists — they were already unrecoverable (P4-C2). They are SKIPPED, not deleted:
 * skipping stops one unreadable row jamming every later action (the P4-H1 shape), and not deleting
 * means nothing is destroyed on this path.
 */
async function decryptOutboxRecords(records: EncryptedRecord[]): Promise<OutboxEntry[]> {
    const key = await deviceKey();
    const entries: OutboxEntry[] = [];

    for (const record of records) {
        const entry = await tryDecrypt<OutboxEntry>(record, key);

        if (entry !== null) {
            entries.push(entry);
        }
    }

    return entries;
}

async function tryDecrypt<T>(record: EncryptedRecord, key: CryptoKey): Promise<T | null> {
    try {
        return await decryptRecord<T>(record, key);
    } catch {
        return null;
    }
}

export async function pendingOutboxCount(): Promise<number> {
    return db.encryptedRecords
        .where('id')
        .startsWith(OUTBOX_PREFIX)
        .count();
}

/**
 * Wipe the cached PHI — and ONLY the cached PHI (QA-FIX.4c, P4-C3, D-203).
 *
 * This used to call `encryptedRecords.clear()`, which took the OUTBOX with it: a 401/403 on
 * reconnect silently deleted care the nurse had recorded and the server had never received, with no
 * warning and no export. The security intent — drop cached PHI when the session dies — is preserved
 * exactly: the day pack is removed and the session key is cleared. Un-transmitted work is NOT the
 * cache and is kept, so it can still be synced once the nurse signs in again.
 */
export async function wipeLocalStore(): Promise<void> {
    await db.transaction('rw', db.encryptedRecords, async () => {
        const cached = await db.encryptedRecords
            .where('id')
            .startsWith(OUTBOX_PREFIX)
            .primaryKeys();
        const keep = new Set(cached);
        const all = await db.encryptedRecords.toCollection().primaryKeys();

        await db.encryptedRecords.bulkDelete(all.filter((id) => ! keep.has(id)));
    });
    clearSessionKey();
}

function requireSessionKey(): CryptoKey {
    if (sessionKey === null) {
        throw new Error('No in-memory day-pack key is available.');
    }

    return sessionKey;
}

async function putEncryptedRecord(id: string, value: unknown, withKey?: CryptoKey): Promise<void> {
    const key = withKey ?? requireSessionKey();
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

async function decryptRecord<T>(record: EncryptedRecord, withKey?: CryptoKey): Promise<T> {
    const key = withKey ?? requireSessionKey();
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

    const entries = await decryptOutboxRecords(records);

    if (entries.length === 0) {
        return 1;
    }

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
