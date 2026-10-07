import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import {
    isKinetixAbort,
    kinetixFetch,
    kinetixRoutePrefix,
} from '@/composables/useKinetixHttp';

interface RecomputeResponse {
    schema: unknown[];
    changes: Record<string, unknown>;
}

export interface UseKinetixFormReactivityOptions {
    /** The form's signed recompute descriptor (null = form isn't reactive). */
    descriptor: () => string | null | undefined;
    /** Current form values, read when a recompute is sent. */
    getValues: () => Record<string, unknown>;
    /** Apply the server's fresh schema (replaces the rendered schema). */
    onSchema: (schema: unknown[]) => void;
    /** Apply the values afterStateUpdated pushed back (merge into form state). */
    onChanges: (changes: Record<string, unknown>) => void;
    /** Debounce window (ms) before a live change hits the server. */
    debounce?: number;
}

/**
 * The client half of server-driven form reactivity ($get/$set). When a
 * `live()` field changes, it POSTs the form's signed descriptor + the current
 * values to `kinetix.forms.recompute` and applies the recomputed schema and any
 * `afterStateUpdated` changes.
 *
 * Three things keep it smooth:
 *   - DEBOUNCE: rapid typing collapses into one request.
 *   - ABORT + VERSIONING: each request aborts the previous and carries a
 *     sequence number, so an out-of-order response is discarded (no flash of
 *     a stale schema).
 *   - FOCUS PRESERVATION: the active field + caret are captured before the
 *     schema swaps and restored after, so re-rendering doesn't blur the input
 *     the user is in.
 *
 * A form with no descriptor (not reactive) makes `onFieldChange` a no-op.
 */
export function useKinetixFormReactivity(
    options: UseKinetixFormReactivityOptions,
) {
    const page = usePage();
    const recomputing: Ref<boolean> = ref(false);

    let timer: ReturnType<typeof setTimeout> | null = null;
    let controller: AbortController | null = null;
    let seq = 0;

    const captureFocus = (): { id: string; start: number | null } | null => {
        const el = document.activeElement as
            | HTMLInputElement
            | HTMLTextAreaElement
            | null;

        if (!el || !el.id) {
            return null;
        }

        const start =
            typeof el.selectionStart === 'number' ? el.selectionStart : null;

        return { id: el.id, start };
    };

    const restoreFocus = (
        focus: { id: string; start: number | null } | null,
    ): void => {
        if (!focus) {
            return;
        }

        requestAnimationFrame(() => {
            const el = document.getElementById(focus.id) as
                | HTMLInputElement
                | HTMLTextAreaElement
                | null;

            if (!el) {
                return;
            }

            el.focus();

            if (
                focus.start !== null &&
                typeof el.setSelectionRange === 'function'
            ) {
                try {
                    el.setSelectionRange(focus.start, focus.start);
                } catch {
                    // Some input types (number/email) forbid setSelectionRange.
                }
            }
        });
    };

    const send = async (): Promise<void> => {
        const descriptor = options.descriptor();

        if (!descriptor) {
            return;
        }

        controller?.abort();
        controller = new AbortController();
        const mySeq = ++seq;

        recomputing.value = true;
        const focus = captureFocus();

        try {
            const result = await kinetixFetch<RecomputeResponse>(
                `/${kinetixRoutePrefix(page)}/forms/recompute`,
                {
                    method: 'POST',
                    body: { descriptor, data: options.getValues() },
                    signal: controller.signal,
                },
            );

            // Discard a response that a newer change has already superseded.
            if (mySeq !== seq || !result) {
                return;
            }

            if (result.changes && Object.keys(result.changes).length > 0) {
                options.onChanges(result.changes);
            }

            if (Array.isArray(result.schema)) {
                options.onSchema(result.schema);
            }

            restoreFocus(focus);
        } catch (e) {
            if (!isKinetixAbort(e)) {
                // A failed recompute leaves the current schema in place — the
                // form stays usable rather than breaking on a transient error.
                recomputing.value = false;
            }

            return;
        } finally {
            if (mySeq === seq) {
                recomputing.value = false;
            }
        }
    };

    /**
     * Call after a field updates. Only `live` fields trigger a recompute;
     * everything else is ignored.
     */
    const onFieldChange = (isLive: boolean | undefined): void => {
        if (!isLive || !options.descriptor()) {
            return;
        }

        if (timer) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => void send(), options.debounce ?? 300);
    };

    return { onFieldChange, recomputing };
}
