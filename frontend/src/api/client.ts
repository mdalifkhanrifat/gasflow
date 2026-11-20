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
        throw new Error(`Request failed with HTTP ${response.status}`);
    }
    return response;
}

export async function readJson(path: string): Promise<unknown> {
    const response = await request(path);
    return await response.json();
}

export async function writeJson(path: string, data: object): Promise<unknown> {
    await request('/sanctum/csrf-cookie');
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='));
    if (!token) {
        throw new Error('Missing CSRF token');
    }

    const response = await request(path, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
                token.slice('XSRF-TOKEN='.length),
            ),
        },
        body: JSON.stringify(data),
    });
    return await response.json();
}
