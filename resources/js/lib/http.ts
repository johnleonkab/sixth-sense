function xsrfToken(): string {
    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

export class HttpError extends Error {
    constructor(
        public readonly status: number,
        message: string,
    ) {
        super(message);
    }
}

/**
 * JSON POST to the Laravel app with the session's CSRF token.
 */
export async function postJson<T>(url: string, body: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const data = (await response.json().catch(() => ({}))) as { message?: string };

        throw new HttpError(response.status, data.message ?? `HTTP ${response.status}`);
    }

    return (await response.json()) as T;
}

export const browserTimezone = (): string => Intl.DateTimeFormat().resolvedOptions().timeZone;

/**
 * JSON GET from the Laravel app, with the session.
 */
export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        throw new HttpError(response.status, `HTTP ${response.status}`);
    }

    return (await response.json()) as T;
}
