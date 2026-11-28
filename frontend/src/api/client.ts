// A response outside 2xx. `payload` is the JSON body when there is one.
export class ApiError extends Error {
    constructor(
        readonly status: number,
        readonly payload: unknown,
    ) {
        super(`Request failed with HTTP ${status}`);
        this.name = 'ApiError';
    }
}

// Laravel's 422: { message, errors: { field: [messages] } }.
export class ApiValidationError extends ApiError {
    constructor(
        payload: unknown,
        readonly errors: Readonly<Record<string, readonly string[]>>,
    ) {
        super(422, payload);
        this.name = 'ApiValidationError';
    }
}

function fieldErrors(payload: unknown): Record<string, string[]> {
    if (
        typeof payload !== 'object' ||
        payload === null ||
        !('errors' in payload) ||
        typeof payload.errors !== 'object' ||
        payload.errors === null
    ) {
        return {};
    }

    return Object.fromEntries(
        Object.entries(payload.errors).map(([field, messages]) => [
            field,
            Array.isArray(messages)
                ? messages.filter((message) => typeof message === 'string')
                : [],
        ]),
    );
}

async function request(
    path: string,
    options: RequestInit = {},
): Promise<Response> {
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    const response = await fetch(path, {
        ...options,
        headers,
        credentials: 'same-origin',
    });
    if (!response.ok) {
        const payload: unknown = await response.json().catch(() => null);
        throw response.status === 422
            ? new ApiValidationError(payload, fieldErrors(payload))
            : new ApiError(response.status, payload);
    }
    return response;
}

export async function readJson(path: string): Promise<unknown> {
    const response = await request(path);
    return await response.json();
}

export async function writeJson(
    path: string,
    data: object,
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE' = 'PUT',
): Promise<unknown> {
    await request('/sanctum/csrf-cookie');
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='));
    if (!token) {
        throw new Error('Missing CSRF token');
    }

    const response = await request(path, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                token.slice('XSRF-TOKEN='.length),
            ),
        },
        body: JSON.stringify(data),
    });
    return response.status === 204 ? null : await response.json();
}
