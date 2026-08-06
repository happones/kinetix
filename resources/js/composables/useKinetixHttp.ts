import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * Read Laravel's `XSRF-TOKEN` cookie for stateful (cookie/session) fetch calls.
 */
export function xsrfToken(): string {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : '';
}

export interface KinetixFetchOptions {
    method?: string;
    /** JSON-serialized automatically, unless it's a `FormData` (sent as multipart). */
    body?: unknown;
    /** Extra headers, merged over the defaults. */
    headers?: Record<string, string>;
}

/**
 * Single fetch wrapper for Kinetix's stateful endpoints. Always sends the XSRF
 * token, `Accept: application/json` and `X-Requested-With` (so Laravel returns
 * JSON errors instead of redirects), with `credentials: same-origin`. Throws on
 * a non-2xx response; returns the parsed JSON body (or `null` for empty/204).
 *
 * `FormData` bodies are sent as multipart (no `Content-Type`, so the browser
 * sets the boundary); everything else is JSON-encoded.
 */
export async function kinetixFetch<T = unknown>(
    url: string,
    options: KinetixFetchOptions = {},
): Promise<T | null> {
    const { method = 'GET', body, headers = {} } = options;
    const isFormData =
        typeof FormData !== 'undefined' && body instanceof FormData;

    const baseHeaders: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
    };

    if (body !== undefined && !isFormData) {
        baseHeaders['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        method: method.toUpperCase(),
        headers: { ...baseHeaders, ...headers },
        credentials: 'same-origin',
        body:
            body === undefined
                ? undefined
                : isFormData
                  ? (body as FormData)
                  : JSON.stringify(body),
    });

    if (!response.ok) {
        // Surface the server's error message (e.g. validation) when present.
        let message = `HTTP error! status: ${response.status}`;

        try {
            const payload = await response.clone().json();

            if (
                payload &&
                typeof payload.message === 'string' &&
                payload.message
            ) {
                message = payload.message;
            }
        } catch {
            // non-JSON error body — keep the status message
        }

        throw new Error(message);
    }

    if (response.status === 204) {
        return null;
    }

    const text = await response.text();

    return text ? (JSON.parse(text) as T) : null;
}

/**
 * The Kinetix internal route prefix (incl. any `{team}` segment) from the shared
 * `kinetix_config`. Build endpoint URLs as `/${kinetixRoutePrefix(page)}/…`.
 */
export function kinetixRoutePrefix(page: {
    props: KinetixSharedProps;
}): string {
    // `page?.props?.` — outside a mounted Inertia app (component tests, SSR
    // edges) usePage yields no page; fall back to the default prefix.
    return page?.props?.kinetix_config?.route_prefix ?? '_kinetix';
}
