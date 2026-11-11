/**
 * A small LRU + TTL cache for lookup answers (typed searches). Module-level so
 * reopening a picker or retyping a term answers from memory, and so a write
 * (attach, detach) can drop what it made stale.
 *
 * Entries are filed under a namespace that callers build from the route
 * prefix (team segment), the endpoint and the signed token, so an answer never
 * crosses teams, records or fields. The cache is off during SSR: a module
 * shared by every request must never hold one user's answers for another.
 */
interface Entry {
    namespace: string;
    expires: number;
    value: unknown;
}

const entries = new Map<string, Entry>();

const SEPARATOR = '\u0000';

// Who the cached answers were fetched for (user, impersonation, team, locale).
// Part of every key, and a change drops everything: a second user in the same
// tab never sees the first one's Gate-filtered answers.
let identity = '';

/** Who the cache currently serves. */
export const getKinetixSearchIdentity = (): string => identity;

/** Sets who the cache serves; a different identity empties it. */
export function setKinetixSearchIdentity(next: string): void {
    if (next !== identity) {
        entries.clear();
        identity = next;
    }
}

const enabled = (): boolean => typeof window !== 'undefined';

const keyOf = (namespace: string, query: string): string =>
    `${identity}${SEPARATOR}${namespace}${SEPARATOR}${query}`;

/** The cached answer for `query`, or undefined when absent or stale. */
export function getKinetixSearchCache<T>(
    namespace: string,
    query: string,
): T | undefined {
    if (!enabled()) {
        return undefined;
    }

    const key = keyOf(namespace, query);
    const entry = entries.get(key);

    if (!entry) {
        return undefined;
    }

    if (entry.expires <= Date.now()) {
        entries.delete(key);

        return undefined;
    }

    // Re-insert: the Map's order is the LRU order.
    entries.delete(key);
    entries.set(key, entry);

    return entry.value as T;
}

/**
 * Stores an answer for `ttlMs`, evicting the least recently used entries past
 * `maxEntries`. A zero TTL or capacity stores nothing.
 */
export function setKinetixSearchCache(
    namespace: string,
    query: string,
    value: unknown,
    ttlMs: number,
    maxEntries: number,
): void {
    if (!enabled() || ttlMs <= 0 || maxEntries <= 0) {
        return;
    }

    const key = keyOf(namespace, query);

    entries.delete(key);
    entries.set(key, { namespace, expires: Date.now() + ttlMs, value });

    while (entries.size > maxEntries) {
        const oldest = entries.keys().next().value;

        if (oldest === undefined) {
            break;
        }

        entries.delete(oldest);
    }
}

/**
 * Drops the answers filed under `namespace` and under any namespace nested
 * below it (`namespace:…`), or everything when called without one.
 */
export function clearKinetixSearchCache(namespace?: string): void {
    if (namespace === undefined) {
        entries.clear();

        return;
    }

    for (const [key, entry] of entries) {
        if (
            entry.namespace === namespace ||
            entry.namespace.startsWith(`${namespace}:`)
        ) {
            entries.delete(key);
        }
    }
}

/** How many answers are held (for specs). */
export function kinetixSearchCacheSize(): number {
    return entries.size;
}
