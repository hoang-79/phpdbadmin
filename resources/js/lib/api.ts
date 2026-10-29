/**
 * JSON-Anfragen des Arbeitsbildschirms ausserhalb von Inertia (Raster nachladen, zählen, Zeile). Fehler kommen mit
 * Ursache zurück, damit die Oberfläche sie erklären kann (O-09): Sitzung abgelaufen, Passwort fehlt, Abfrage
 * abgelehnt, Verbindung weg.
 */
export type ApiFailure =
    | { reason: 'session' }
    | { reason: 'password' }
    | { reason: 'missing' }
    | {
          reason: 'query';
          error: {
              reason: string;
              message: string;
              serverMessage: string | null;
          };
      }
    | {
          reason: 'connection';
          problem: {
              title: string;
              explanation: string;
              serverMessage: string | null;
          };
      }
    | { reason: 'network'; message: string }
    | {
          reason: 'confirm';
          dangers: { sql: string; reason: string }[];
          production: boolean;
          wrongName?: boolean;
      }
    | { reason: 'empty' };

export class ApiError extends Error {
    constructor(public readonly failure: ApiFailure) {
        super(failure.reason);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(
    url: string,
    init: { method?: string; body?: string } = {},
): Promise<T> {
    let response: Response;

    try {
        response = await fetch(url, {
            credentials: 'same-origin',
            ...init,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(init.body ? { 'Content-Type': 'application/json' } : {}),
            },
        });
    } catch (error) {
        throw new ApiError({ reason: 'network', message: String(error) });
    }

    if (response.status === 401 || response.status === 419) {
        throw new ApiError({ reason: 'session' });
    }

    if (response.status === 404) {
        throw new ApiError({ reason: 'missing' });
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const body = (await response.json().catch(() => null)) as
        | (ApiFailure & Record<string, unknown>)
        | null;

    if (!response.ok) {
        throw new ApiError(
            body && 'reason' in body
                ? body
                : { reason: 'network', message: `HTTP ${response.status}` },
        );
    }

    return body as T;
}

export function getJson<T>(url: string): Promise<T> {
    return request<T>(url);
}

export function putJson(url: string, data: unknown): Promise<void> {
    return request<void>(url, { method: 'PUT', body: JSON.stringify(data) });
}

export function postJson<T>(url: string, data: unknown): Promise<T> {
    return request<T>(url, { method: 'POST', body: JSON.stringify(data) });
}

export function deleteJson(url: string): Promise<void> {
    return request<void>(url, { method: 'DELETE' });
}
