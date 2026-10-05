import { usePage } from '@inertiajs/vue3';
import {
    computed,
    getCurrentScope,
    onScopeDispose,
    ref,
    toValue,
    watch,
} from 'vue';
import type { ComputedRef, MaybeRefOrGetter } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * How long a closed alert stays closed:
 *
 * - `hide`      — this mounted instance only; it is back on the next page
 *                 (or the next mount of a persistent layout).
 * - `session`   — this browser tab, until it is closed (sessionStorage).
 * - `device`    — this browser, across tabs and restarts (localStorage).
 * - `permanent` — the account, on every device. The server owns that state:
 *                 the Dismissals module (`kinetix.dismissals.enabled`) stores
 *                 it with no wiring, or the caller supplies `persist`. With
 *                 neither, it degrades to `device` rather than pretending.
 *
 * `session` and `device` take an optional duration — "hide it for a week" —
 * after which the alert comes back on its own.
 */
export type KinetixDismissMode = 'hide' | 'session' | 'device' | 'permanent';

type StorageScope = 'session' | 'device';

interface StoredDismissal {
    at: number;
    /** Epoch ms when the dismissal lapses; `null` = for the scope's life. */
    until: number | null;
}

const PREFIX = 'kinetix.dismissed';

/**
 * Where a dismissal lands when the browser refuses storage (private mode,
 * quota, a sandboxed iframe): the tab's memory, which still beats re-showing
 * the alert on every navigation.
 */
const memory = new Map<string, StoredDismissal>();

function storageFor(scope: StorageScope): Storage | null {
    try {
        return scope === 'session'
            ? window.sessionStorage
            : window.localStorage;
    } catch {
        return null;
    }
}

function lapsed(entry: StoredDismissal): boolean {
    return entry.until !== null && entry.until <= Date.now();
}

function readEntry(scope: StorageScope, key: string): StoredDismissal | null {
    const store = storageFor(scope);
    let entry: StoredDismissal | null = null;

    try {
        const raw = store?.getItem(key);
        entry = raw ? (JSON.parse(raw) as StoredDismissal) : null;
    } catch {
        entry = null;
    }

    entry ??= memory.get(`${scope}:${key}`) ?? null;

    if (entry !== null && lapsed(entry)) {
        forgetEntry(scope, key);

        return null;
    }

    return entry;
}

function writeEntry(
    scope: StorageScope,
    key: string,
    entry: StoredDismissal,
): void {
    try {
        const store = storageFor(scope);

        if (store === null) {
            throw new Error('storage unavailable');
        }

        store.setItem(key, JSON.stringify(entry));
    } catch {
        memory.set(`${scope}:${key}`, entry);
    }
}

function forgetEntry(scope: StorageScope, key: string): void {
    memory.delete(`${scope}:${key}`);

    try {
        storageFor(scope)?.removeItem(key);
    } catch {
        // Nothing to undo in a storage the browser won't let us touch.
    }
}

/**
 * Keys restored in this tab while the page payload still lists them as closed
 * (it only learns otherwise on the next response).
 */
const restoredHere = new Set<string>();

function usePageOrNull(): { props: KinetixSharedProps } | null {
    try {
        return usePage<KinetixSharedProps>();
    } catch {
        // Mounted outside a full Inertia app (tests, a standalone widget).
        return null;
    }
}

/**
 * The account-wide ledger: the Dismissals module's keys on the page payload
 * (`kinetix_dismissals`, null when the module is off) and its endpoints.
 */
function useServerLedger(scoped: (key: string) => string) {
    const page = usePageOrNull();

    const keys = (): string[] | null => page?.props?.kinetix_dismissals ?? null;
    const base = (): string =>
        `/${kinetixRoutePrefix(page ?? { props: {} })}/dismissals`;

    return {
        /** Whether a `permanent` close has a server to go to. */
        available: (): boolean => Array.isArray(keys()),

        has: (key: string): boolean =>
            (keys() ?? []).includes(key) && !restoredHere.has(scoped(key)),

        /** `duration` (ms) becomes the close's server-side lifetime. */
        async dismiss(key: string, duration?: number | null): Promise<void> {
            await kinetixFetch(base(), {
                method: 'POST',
                body: {
                    key,
                    minutes:
                        duration && duration > 0
                            ? Math.ceil(duration / 60_000)
                            : null,
                },
            });

            restoredHere.delete(scoped(key));
        },

        async restore(key: string): Promise<void> {
            restoredHere.add(scoped(key));

            await kinetixFetch(`${base()}/${encodeURIComponent(key)}`, {
                method: 'DELETE',
            });
        },
    };
}

/**
 * Who the dismissals belong to: two accounts sharing a browser must not close
 * each other's alerts. Guests share one bucket.
 */
function useOwner(): () => string {
    const page = usePageOrNull();

    return () => String(page?.props?.auth?.user?.id ?? 'guest');
}

/**
 * The browser-side ledger of closed alerts, scoped to the signed-in user —
 * the low-level half of `useKinetixDismissal`, for a component that tracks
 * many entries at once (the announcement banner keys each one by id).
 */
export function useKinetixDismissalStore() {
    const owner = useOwner();

    function storageKey(key: string): string {
        return `${PREFIX}:${owner()}:${key}`;
    }

    const server = useServerLedger(storageKey);

    /** Closed in this tab, this browser, or — with the module — the account. */
    function isDismissed(key: string): boolean {
        const scoped = storageKey(key);

        return (
            readEntry('session', scoped) !== null ||
            readEntry('device', scoped) !== null ||
            server.has(key)
        );
    }

    /** Close `key` for `scope`, optionally for `duration` ms only. */
    function remember(
        key: string,
        scope: StorageScope,
        duration?: number | null,
    ): void {
        const now = Date.now();

        writeEntry(scope, storageKey(key), {
            at: now,
            until: duration && duration > 0 ? now + duration : null,
        });
    }

    /** Bring `key` back, whichever scope closed it. */
    function forget(key: string): void {
        const scoped = storageKey(key);
        forgetEntry('session', scoped);
        forgetEntry('device', scoped);
    }

    return { isDismissed, remember, forget, storageKey, server };
}

export interface KinetixDismissalOptions {
    /** What `dismiss()` does when called without a mode (default `hide`). */
    mode?: MaybeRefOrGetter<KinetixDismissMode | null | undefined>;
    /** `session`/`device` only: ms until the alert comes back. */
    duration?: MaybeRefOrGetter<number | null | undefined>;
    /**
     * `permanent` only: store the dismissal server-side; rejecting undoes it.
     * Unset, the Dismissals module stores it when enabled. A plain function,
     * never a getter function — `toValue()` would call it. (A property getter,
     * `get persist() { … }`, is fine: it is read at close time.)
     */
    persist?: ((key: string) => unknown | Promise<unknown>) | null;
}

export interface KinetixDismissal {
    dismissed: ComputedRef<boolean>;
    dismiss: (mode?: KinetixDismissMode) => Promise<void>;
    restore: () => void;
    /** Whether a `permanent` close reaches a server (a `persist` or the module). */
    canPersist: () => boolean;
}

/**
 * Open/closed state for ONE alert identified by `key`. Without a key every
 * mode behaves like `hide` — there is nothing to remember it by.
 *
 * Closing is optimistic: the alert hides at once, and a `permanent` close
 * whose `persist` rejects shows it again (the rejection is re-thrown).
 */
export function useKinetixDismissal(
    key: MaybeRefOrGetter<string | null | undefined>,
    options: KinetixDismissalOptions = {},
): KinetixDismissal {
    const store = useKinetixDismissalStore();

    const hidden = ref(false);
    const stored = ref(false);

    function sync(): void {
        const current = toValue(key);
        stored.value = current ? store.isDismissed(current) : false;
    }

    sync();
    watch(
        () => toValue(key),
        () => {
            hidden.value = false;
            sync();
        },
    );

    async function dismiss(mode?: KinetixDismissMode): Promise<void> {
        const resolved = mode ?? toValue(options.mode) ?? 'hide';
        const current = toValue(key);

        hidden.value = true;

        if (!current || resolved === 'hide') {
            return;
        }

        if (resolved === 'session' || resolved === 'device') {
            store.remember(current, resolved, toValue(options.duration));

            return;
        }

        const persist =
            options.persist ??
            (store.server.available()
                ? (key: string) =>
                      store.server.dismiss(key, toValue(options.duration))
                : null);

        if (!persist) {
            store.remember(current, 'device');

            return;
        }

        try {
            await persist(current);
        } catch (error) {
            hidden.value = false;

            throw error;
        }

        // The server is the truth from here on, but a page restored from
        // the browser history still carries the old payload — the tab
        // remembers what it already closed.
        store.remember(current, 'session');
    }

    function restore(): void {
        const current = toValue(key);
        hidden.value = false;

        if (current) {
            store.forget(current);

            if (store.server.available()) {
                // Best effort: the alert is already back on screen, and a
                // failed call only means it is closed again on the next page.
                store.server.restore(current).catch(() => {});
            }
        }

        sync();
    }

    // Closing it on this device closes it in the other open tabs too.
    if (typeof window !== 'undefined' && getCurrentScope()) {
        const onStorage = (event: StorageEvent): void => {
            const current = toValue(key);

            if (current && event.key === store.storageKey(current)) {
                sync();
            }
        };

        window.addEventListener('storage', onStorage);
        onScopeDispose(() => window.removeEventListener('storage', onStorage));
    }

    return {
        dismissed: computed(() => hidden.value || stored.value),
        dismiss,
        restore,
        canPersist: () => !!options.persist || store.server.available(),
    };
}
