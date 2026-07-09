import {
    loadOutboxForReplay,
    removeOutboxEntries,
    saveDayPack,
    setSessionToken,
    wipeLocalStore,
} from './storage/dayPackStore';
import type { DayPack, SyncResult } from './types';

interface LoginResponse {
    token: string;
    token_type: 'Bearer';
    expires_at: string;
    user: {
        id: number;
        name: string;
        tenant_id: string;
    };
}

let bearerToken: string | null = null;

export async function login(email: string, password: string): Promise<LoginResponse> {
    const response = await fetch('/api/nurse/login', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email, password }),
    });

    if (!response.ok) {
        throw new Error('nurse.login.failed');
    }

    const payload = (await response.json()) as LoginResponse;
    bearerToken = payload.token;
    await setSessionToken(payload.token);

    return payload;
}

export async function logout(): Promise<void> {
    if (bearerToken !== null) {
        await fetch('/api/nurse/logout', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${bearerToken}`,
            },
        });
    }

    bearerToken = null;
    await wipeLocalStore();
}

export async function syncDayPack(date: string): Promise<DayPack> {
    const response = await fetch(`/api/nurse/day-pack?date=${encodeURIComponent(date)}`, {
        headers: {
            Accept: 'application/json',
            ...(bearerToken !== null ? { Authorization: `Bearer ${bearerToken}` } : {}),
        },
    });

    if (response.status === 401 || response.status === 403) {
        bearerToken = null;
        await wipeLocalStore();
        throw new Error('nurse.sync.revoked');
    }

    if (!response.ok) {
        throw new Error('nurse.sync.failed');
    }

    const dayPack = (await response.json()) as DayPack;
    await saveDayPack(dayPack);

    return dayPack;
}

export async function syncOutbox(): Promise<SyncResult[]> {
    const actions = await loadOutboxForReplay();

    if (actions.length === 0) {
        return [];
    }

    const response = await fetch('/api/nurse/sync', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(bearerToken !== null ? { Authorization: `Bearer ${bearerToken}` } : {}),
        },
        body: JSON.stringify({ actions }),
    });

    if (response.status === 401 || response.status === 403) {
        bearerToken = null;
        await wipeLocalStore();
        throw new Error('nurse.sync.revoked');
    }

    if (!response.ok) {
        throw new Error('nurse.outbox.failed');
    }

    const payload = (await response.json()) as { results: SyncResult[] };
    await removeOutboxEntries(payload.results.map((result) => result.client_uuid));

    return payload.results;
}

export async function syncOutboxWithRetry(
    maxAttempts = 3,
    sleep: (delayMs: number) => Promise<void> = delay,
): Promise<SyncResult[]> {
    let lastError: unknown = null;

    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
        try {
            return await syncOutbox();
        } catch (error) {
            lastError = error;

            if (attempt < maxAttempts - 1) {
                await sleep(nextBackoffDelayMs(attempt));
            }
        }
    }

    throw lastError instanceof Error ? lastError : new Error('nurse.outbox.failed');
}

export function nextBackoffDelayMs(attempt: number, baseMs = 1000, maxMs = 60000): number {
    return Math.min(maxMs, baseMs * 2 ** Math.max(0, attempt));
}

export function currentBearerToken(): string | null {
    return bearerToken;
}

function delay(delayMs: number): Promise<void> {
    return new Promise((resolve) => {
        window.setTimeout(resolve, delayMs);
    });
}
