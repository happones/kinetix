import { usePage } from '@inertiajs/vue3';
import { getCurrentInstance, onBeforeUnmount, ref } from 'vue';
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
 * Apply a recompute's `changes` to the form values. Keys are field names or
 * dot paths into nested state (`items.0.qty`): a path updates just that
 * value, copying the containers on its way, so the rest of the array or
 * object it lives in survives.
 */
export function applyFormChanges(
    values: Record<string, unknown>,
    changes: Record<string, unknown>,
): Record<string, unknown> {
    const next: Record<string, unknown> = { ...values };

    for (const [path, value] of Object.entries(changes)) {
        const keys = path.split('.');
        let target: any = next;

        keys.slice(0, -1).forEach((key, i) => {
            const current = target[key];
            const copy = Array.isArray(current)
                ? [...current]
                : current !== null && typeof current === 'object'
                  ? { ...current }
                  : /^\d+$/.test(keys[i + 1])
                    ? []
                    : {};

            target[key] = copy;
            target = copy;
        });

        target[keys[keys.length - 1]] = value;
    }

    return next;
}

/**
 * The client half of server-driven form reactivity ($get/$set). When a
 * `live()` field changes, it POSTs the form's signed descriptor + the current
 * values to `kinetix.forms.recompute` and applies the recomputed schema and any
 * `afterStateUpdated` changes.
 *
 * What keeps it correct and smooth:
 *   - DEBOUNCE: rapid typing collapses into one request, which names the live
 *     fields that changed (`changed`) so only THEIR afterStateUpdated hooks
 *     run on the server.
 *   - ABORT + VERSIONING: a new live change aborts the request in flight and
 *     invalidates its response at once (not only when the next request is
 *     sent), so values computed from what the user has since replaced never
 *     land. The fields it named go with the next request (as do a failed
 *     one's), so their hooks still run against the final values.
 *   - FOCUS PRESERVATION: if the schema swap blurs the input the user was in,
 *     focus and caret go back to it. Focus the user moved elsewhere is left
 *     alone.
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
    // Live fields changed since the last request, in the order they changed.
    let changed: string[] = [];
    // The fields the request in flight carries. A request that is superseded
    // or fails hands them back, so their hooks still run on the next one.
    let inFlight: string[] = [];

    const requeue = (fields: string[]): void => {
        changed = [...new Set([...fields, ...changed])];
    };

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

            // Only repair focus the swap took away: if it is still on this
            // input (the user may have typed on), or the user moved it to
            // another element, leave it where it is.
            const active = document.activeElement;

            if (
                !el ||
                active === el ||
                (active !== null && active !== document.body)
            ) {
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
        const fields = changed;
        changed = [];
        inFlight = fields;

        recomputing.value = true;
        const focus = captureFocus();

        try {
            const result = await kinetixFetch<RecomputeResponse>(
                `/${kinetixRoutePrefix(page)}/forms/recompute`,
                {
                    method: 'POST',
                    body: {
                        descriptor,
                        data: options.getValues(),
                        changed: fields,
                    },
                    signal: controller.signal,
                },
            );

            // Discard a response that a newer change has already superseded
            // (its fields went back into `changed` then).
            if (mySeq !== seq) {
                return;
            }

            inFlight = [];

            if (!result) {
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
                // form stays usable rather than breaking on a transient error
                // — and its fields ride along with the next one.
                recomputing.value = false;

                if (mySeq === seq) {
                    requeue(fields);
                    inFlight = [];
                }
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
    const onFieldChange = (
        isLive: boolean | undefined,
        name?: string,
        debounce?: number,
    ): void => {
        if (!isLive || !options.descriptor()) {
            return;
        }

        if (name && !changed.includes(name)) {
            changed.push(name);
        }

        // A response computed from the value the user just replaced must not
        // land: drop the request in flight now, not when the next one is sent.
        // The fields it carried go with the next one.
        requeue(inFlight);
        inFlight = [];
        controller?.abort();
        controller = null;
        seq++;

        if (timer) {
            clearTimeout(timer);
        }

        // A field's own `live(debounce: …)` wins over the form default.
        timer = setTimeout(
            () => void send(),
            debounce ?? options.debounce ?? 300,
        );
    };

    const dispose = (): void => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }

        controller?.abort();
        controller = null;
    };

    if (getCurrentInstance()) {
        onBeforeUnmount(dispose);
    }

    return { onFieldChange, recomputing, dispose };
}
