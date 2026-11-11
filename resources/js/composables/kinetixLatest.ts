import { ref } from 'vue';
import type { Ref } from 'vue';

// Inlined (not imported from useKinetixHttp) so specs that mock that module
// partially keep working.
const isAbort = (error: unknown): boolean =>
    error instanceof DOMException && error.name === 'AbortError';

/** What `run()` resolves to when a newer run (or `cancel()`) replaced it. */
export const KINETIX_SUPERSEDED: unique symbol = Symbol('kinetix.superseded');

export interface KinetixLatest {
    /**
     * Runs `task`, aborting the previous run. Resolves to `KINETIX_SUPERSEDED`
     * when this run was replaced or cancelled (an abort never throws, and a
     * superseded run's error is swallowed); a real error from the newest run
     * rethrows.
     */
    run<T>(
        task: (signal: AbortSignal) => Promise<T>,
    ): Promise<T | typeof KINETIX_SUPERSEDED>;
    /** Aborts the in-flight run and releases the spinner. */
    cancel(): void;
    /** True while the newest run is in flight. */
    pending: Readonly<Ref<boolean>>;
}

/**
 * "Latest wins" guard for typed searches: typing outruns the round-trip, so an
 * older response must never paint over a newer one nor switch its spinner off.
 */
export function createKinetixLatest(): KinetixLatest {
    const pending = ref(false);
    let controller: AbortController | undefined;
    let sequence = 0;

    async function run<T>(
        task: (signal: AbortSignal) => Promise<T>,
    ): Promise<T | typeof KINETIX_SUPERSEDED> {
        controller?.abort();
        const own = new AbortController();
        controller = own;

        const current = ++sequence;
        pending.value = true;

        try {
            const result = await task(own.signal);

            return current === sequence ? result : KINETIX_SUPERSEDED;
        } catch (error) {
            if (current !== sequence || isAbort(error)) {
                return KINETIX_SUPERSEDED;
            }

            throw error;
        } finally {
            if (current === sequence) {
                pending.value = false;
            }
        }
    }

    function cancel(): void {
        controller?.abort();
        controller = undefined;
        sequence++;
        pending.value = false;
    }

    return { run, cancel, pending };
}

/**
 * The message to show for a failed lookup: the server's own (localized)
 * message when it sent one, otherwise `fallback`. A network failure
 * ("Failed to fetch") or a bare status line is never shown to the user.
 */
export function kinetixLookupErrorMessage(
    error: unknown,
    fallback: string,
): string {
    if (
        error instanceof Error &&
        typeof (error as { status?: unknown }).status === 'number' &&
        error.message &&
        !error.message.startsWith('HTTP error!')
    ) {
        return error.message;
    }

    return fallback;
}
