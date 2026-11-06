import type { KinetixFetchError } from '@/composables/useKinetixHttp';

/** Whether a rejection is a throttled request (HTTP 429). */
export function isKinetixThrottled(error: unknown): error is KinetixFetchError {
    return (
        error instanceof Error &&
        (error as Partial<KinetixFetchError>).status === 429
    );
}

/**
 * The message to show for a failed request: the server's own (localized
 * "too many attempts, try again in N seconds") for a throttled one, the
 * surface's fallback otherwise. A 429 never shows a generic "failed".
 */
export function kinetixFailureMessage(
    error: unknown,
    fallback: string,
): string {
    return isKinetixThrottled(error) && error.message
        ? error.message
        : fallback;
}
