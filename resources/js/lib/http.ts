/**
 * Minimal JSON HTTP helper for endpoints that return JSON (not Inertia
 * responses) — e.g. the network tools. Sends the XSRF token Laravel expects
 * and shapes success/failure like axios so callers read `res.data` /
 * `err.response.data`.
 */
function xsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T = unknown>(method: string, url: string, body?: unknown): Promise<{ data: T }> {
    const res = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const data = (await res.json().catch(() => ({}))) as T;

    if (!res.ok) {
        throw { response: { status: res.status, data } };
    }

    return { data };
}

export default {
    post: <T = unknown>(url: string, body?: unknown) => request<T>('POST', url, body),
    get: <T = unknown>(url: string) => request<T>('GET', url),
};
