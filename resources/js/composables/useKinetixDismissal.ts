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
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * How long a closed alert stays closed:
 *
 * - `hide`      — this mounted instance only; it is back on the next page
 *                 (or the next mount of a persistent layout).
 * - `session`   — this browser tab, until it is closed (sessionStorage).
 * - `device`    — this browser, across tabs and restarts (localStorage).
 * - `permanent` — the account, on every device. The server owns that state,
 *                 so the caller supplies `persist`; without one it degrades
 *                 to `device` rather than pretending.
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
 * Who the dismissals belong to: two accounts sharing a browser must not close
 * each other's alerts. Guests share one bucket.
 */
function useOwner(): () => string {
    try {
        const page = usePage<KinetixSharedProps>();

        return () => String(page.props?.auth?.user?.id ?? 'guest');
    } catch {
        return () => 'guest';
    }
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

    function isDismissed(key: string): boolean {
        const scoped = storageKey(key);

        return (
            readEntry('session', scoped) !== null ||
            readEntry('device', scoped) !== null
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

    return { isDismissed, remember, forget, storageKey };
}

export interface KinetixDismissalOptions {
    /** What `dismiss()` does when called without a mode (default `hide`). */
    mode?: MaybeRefOrGetter<KinetixDismissMode | null | undefined>;
    /** `session`/`device` only: ms until the alert comes back. */
    duration?: MaybeRefOrGetter<number | null | undefined>;
    /**
     * `permanent` only: store the dismissal server-side; rejecting undoes it.
     * A plain function, never a getter — `toValue()` would call it.
     */
    persist?: ((key: string) => unknown | Promise<unknown>) | null;
}

export interface KinetixDismissal {
    dismissed: ComputedRef<boolean>;
    dismiss: (mode?: KinetixDismissMode) => Promise<void>;
    restore: () => void;
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

        const persist = options.persist;

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
    };
}
