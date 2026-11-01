export async function api(url, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.body && !(options.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, { ...options, headers });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(payload.message || 'Request failed');
        error.status = response.status;
        throw error;
    }

    return payload;
}
