import Dexie, { type Table } from 'dexie';
import type { DayPack } from '../types';

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
const encoder = new TextEncoder();
const decoder = new TextDecoder();
let sessionKey: CryptoKey | null = null;
let sessionSalt: Uint8Array | null = null;

/**
 * D-E2 hard requirement:
 * - The AES key is derived from the session token and a random salt.
 * - The token, salt, and CryptoKey live only in this module's memory.
 * - Dexie stores only iv + AES-GCM ciphertext, never plaintext PHI.
 */
export async function setSessionToken(token: string): Promise<void> {
    sessionSalt = crypto.getRandomValues(new Uint8Array(16));
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
            salt: sessionSalt,
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
    sessionSalt = null;
}

export function hasSessionKey(): boolean {
    return sessionKey !== null;
}

export async function saveDayPack(dayPack: DayPack): Promise<void> {
    const key = requireSessionKey();
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const plaintext = encoder.encode(JSON.stringify(dayPack));
    const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext);

    await db.encryptedRecords.put({
        id: DAY_PACK_ID,
        iv: bytesToBase64(iv),
        ciphertext: bytesToBase64(new Uint8Array(ciphertext)),
        updatedAt: new Date().toISOString(),
    });
}

export async function loadDayPack(): Promise<DayPack | null> {
    const key = requireSessionKey();
    const record = await db.encryptedRecords.get(DAY_PACK_ID);

    if (!record) {
        return null;
    }

    const plaintext = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: base64ToBytes(record.iv) },
        key,
        base64ToBytes(record.ciphertext),
    );

    return JSON.parse(decoder.decode(plaintext)) as DayPack;
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
