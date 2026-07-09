import { saveDayPack, setSessionToken, wipeLocalStore } from './storage/dayPackStore';
import type { DayPack } from './types';

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

export function currentBearerToken(): string | null {
    return bearerToken;
}
