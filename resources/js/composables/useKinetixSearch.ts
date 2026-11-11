import { router, usePage } from '@inertiajs/vue3';
import { computed, getCurrentScope, onScopeDispose, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    KINETIX_SUPERSEDED,
    createKinetixLatest,
    kinetixLookupErrorMessage,
} from '@/composables/kinetixLatest';
import {
    clearKinetixSearchCache,
    getKinetixSearchCache,
    getKinetixSearchIdentity,
    setKinetixSearchCache,
    setKinetixSearchIdentity,
} from '@/composables/kinetixSearchCache';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import type { KinetixFetchError } from '@/composables/useKinetixHttp';
import { useKinetixSearchLimits } from '@/composables/useKinetixSearchLimits';
import type { KinetixLookupMeta, KinetixSharedProps } from '@/types/kinetix';

export type KinetixSearchStatus =
    | 'idle'
    | 'debouncing'
    | 'loading'
    | 'success'
    | 'tooShort'
    | 'throttled'
    | 'timedOut'
    | 'expired'
    | 'error';

/** What an `onSettled` callback receives when a search reaches a final state. */
export interface KinetixSearchSettled<T> {
    status: KinetixSearchStatus;
    results: T | null;
    meta: KinetixLookupMeta | null;
    message: string | null;
    /** A throttled search that will be retried once on its own. */
    retrying: boolean;
    /** The search was asked for without the user (an initial load). */
    silent: boolean;
}

export interface UseKinetixSearchOptions<T> {
    /** Runs one lookup. `meta` is the response's `meta`, when it has one. */
    fetcher: (
        query: string,
        ctx: { signal: AbortSignal },
    ) => Promise<{ value: T; meta?: KinetixLookupMeta | null }>;
    /** Quiet typing time in ms. Default `kinetix_config.search.debounce` (250). */
    debounce?: number;
    /**
     * Shortest non-empty term to search (a function is read on every search).
     * Default 0. An empty term is the initial list and is never "too short".
     */
    minChars?: number | (() => number);
    /** Longest term sent. Default `kinetix_config.search.max_length`. */
    maxLength?: number;
    /**
     * Where answers are filed in the client cache: the endpoint and the signed
     * token. Who is asking (user, impersonation, team, locale) is added for
     * you and a change of it empties the cache. Null (or no function) = no
     * cache; a custom source the cache cannot key safely should return null.
     */
    cacheKey?: () => string | null;
    /** Cache lifetime in ms. Default `kinetix_config.search.cache_ttl` (30s). */
    cacheTtl?: number;
    /** Retry a 429 once, after its `retry_after` (up to 10s). Default true. */
    retryOnThrottle?: boolean;
    /** Called when a search reaches a final state (never while typing). */
    onSettled?: (state: KinetixSearchSettled<T>) => void;
}

export interface UseKinetixSearch<T> {
    /** The latest answer; a throttled search keeps the previous one. */
    results: Ref<T | null>;
    status: Ref<KinetixSearchStatus>;
    /** True while a request is in flight. */
    loading: ComputedRef<boolean>;
    /** The server's own (localized) message for a failed or throttled search. */
    message: Ref<string | null>;
    /** Seconds until a throttled search is retried. */
    retryAfter: Ref<number | null>;
    /** A throttled search waits for its one automatic retry. */
    retrying: Ref<boolean>;
    /** The `meta` of the latest answer (`below_min`, `min_chars`, `timed_out`). */
    meta: Ref<KinetixLookupMeta | null>;
    /**
     * Searches for `term`. Never rejects: a failure lands in `status` and
     * `message`. Resolves to the answer, or null when it was superseded,
     * skipped (too short) or failed.
     */
    search(
        term: string,
        opts?: { immediate?: boolean; force?: boolean; silent?: boolean },
    ): Promise<T | null>;
    /** Aborts the pending request, debounce and retry; keeps the results. */
    cancel(): void;
    /** `cancel()` plus forgetting the results and the status. */
    reset(): void;
    /** Drops this source's cached answers and any in-flight join. */
    invalidate(): void;
}

// Inlined (not imported from useKinetixHttp) so specs that mock that module
// partially keep working.
const isFetchError = (error: unknown): error is KinetixFetchError =>
    error instanceof Error &&
    typeof (error as Partial<KinetixFetchError>).status === 'number';

/**
 * Who the page is for. Everything a lookup answer can depend on besides its
 * own endpoint and token: the user, an impersonation, the team, the language.
 */
export function kinetixSearchFingerprint(props: unknown): string {
    const shared = (props ?? {}) as Partial<KinetixSharedProps>;
    const impersonation = shared.kinetix_impersonation;

    return JSON.stringify([
        shared.auth?.user?.id ?? null,
        impersonation?.active ? (impersonation.user?.id ?? 'on') : null,
        shared.kinetix_config?.route_prefix ?? null,
        shared.kinetix_config?.team ?? null,
        shared.kinetix_locale?.current ?? null,
    ]);
}

let watchingNavigation = false;

// One listener for the page's lifetime: a visit that changes who the page is
// for (logout/login, impersonation start/stop, another team) empties the cache.
function watchIdentity(): void {
    if (watchingNavigation || typeof window === 'undefined') {
        return;
    }

    watchingNavigation = true;

    try {
        router.on('navigate', (event) =>
            setKinetixSearchIdentity(
                kinetixSearchFingerprint(event.detail?.page?.props),
            ),
        );
    } catch {
        // No Inertia router (a component test): the key prefix still isolates.
    }
}

/** A throttle longer than this is not worth waiting for. */
const MAX_RETRY_SECONDS = 10;

/**
 * One typed lookup done right: debounced, latest wins (an older answer never
 * paints over a newer one), identical queries joined or answered from a small
 * client cache, a too-short term never sent, and the server's limits (429,
 * time budget, expired token) turned into a status instead of an exception.
 */
export function useKinetixSearch<T>(
    options: UseKinetixSearchOptions<T>,
): UseKinetixSearch<T> {
    const limits = useKinetixSearchLimits();
    const page = usePage<KinetixSharedProps>();
    const latest = createKinetixLatest();

    watchIdentity();

    const results = ref(null) as Ref<T | null>;
    const status = ref<KinetixSearchStatus>('idle');
    const message = ref<string | null>(null);
    const retryAfter = ref<number | null>(null);
    const meta = ref<KinetixLookupMeta | null>(null);
    const retrying = ref(false);
    const loading = computed(() => latest.pending.value);

    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    let waiting: ((value: T | null) => void) | null = null;
    let retryTimer: ReturnType<typeof setTimeout> | null = null;
    let retried = false;
    let silent = false;
    let epoch = 0;
    let inflight: { key: string; promise: Promise<T | null> } | null = null;

    const minChars = (): number => {
        const min =
            typeof options.minChars === 'function'
                ? options.minChars()
                : (options.minChars ?? 0);

        return Number.isFinite(min) && min > 0 ? min : 0;
    };

    const normalize = (term: string): string => {
        const max = options.maxLength ?? limits.maxLength.value;

        return max !== undefined && max > 0 ? term.slice(0, max) : term;
    };

    const clearDebounce = (): void => {
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }

        // A search replaced before it ran resolves as skipped, never hangs.
        waiting?.(null);
        waiting = null;
    };

    const clearRetry = (): void => {
        if (retryTimer !== null) {
            clearTimeout(retryTimer);
            retryTimer = null;
        }

        retrying.value = false;
    };

    const settle = (retrying = false): void => {
        try {
            options.onSettled?.({
                status: status.value,
                results: results.value,
                meta: meta.value,
                message: message.value,
                retrying,
                silent,
            });
        } catch (error) {
            // The host's callback failing is not the lookup failing: keep the
            // state as it is and surface the error on its own.
            queueMicrotask(() => {
                throw error;
            });
        }
    };

    // The fingerprint of who is asking: keys carry it, a change empties the cache.
    const cacheNamespace = (): string | null => options.cacheKey?.() ?? null;

    // Who is asking right now (synced into the cache on every execute).
    const currentOwner = (): string => {
        const owner = kinetixSearchFingerprint(page?.props);

        setKinetixSearchIdentity(owner);

        return owner;
    };

    // The page moved to someone else while a request was in flight.
    const isStale = (owner: string): boolean =>
        getKinetixSearchIdentity() !== owner ||
        kinetixSearchFingerprint(page?.props) !== owner;

    const apply = (value: T, answerMeta: KinetixLookupMeta | null): void => {
        results.value = value;
        meta.value = answerMeta;
        message.value = null;
        retryAfter.value = null;
        status.value = answerMeta?.below_min
            ? 'tooShort'
            : answerMeta?.timed_out
              ? 'timedOut'
              : 'success';
        settle();
    };

    function fail(error: unknown, query: string): null {
        const server = kinetixLookupErrorMessage(error, '') || null;

        if (isFetchError(error) && error.status === 429) {
            // 'throttled' and 'busy' alike: keep what is on screen, retry once.
            const seconds = error.retryAfter ?? 1;
            const willRetry =
                options.retryOnThrottle !== false &&
                !retried &&
                seconds <= MAX_RETRY_SECONDS;

            status.value = 'throttled';
            message.value = server;
            retryAfter.value = seconds;

            retrying.value = willRetry;

            if (willRetry) {
                retried = true;
                retryTimer = setTimeout(
                    () => {
                        retryTimer = null;
                        retrying.value = false;
                        void execute(query, true);
                    },
                    Math.max(0, seconds) * 1000,
                );
            }

            settle(willRetry);

            return null;
        }

        results.value = null;
        meta.value = null;
        message.value = server;
        retryAfter.value = null;
        status.value =
            isFetchError(error) && error.code === 'descriptor_expired'
                ? 'expired'
                : 'error';
        settle();

        return null;
    }

    function execute(query: string, force: boolean): Promise<T | null> {
        const trimmed = query.trim();
        const owner = currentOwner();
        const namespace = cacheNamespace();
        // The owner is part of the join key: B never joins A's request.
        const key = `${owner}\u0000${namespace ?? ''}\u0000${trimmed}`;

        if (!force && namespace !== null) {
            const hit = getKinetixSearchCache<{
                value: T;
                meta: KinetixLookupMeta | null;
            }>(namespace, trimmed);

            if (hit) {
                latest.cancel();
                inflight = null;
                apply(hit.value, hit.meta);

                return Promise.resolve(hit.value);
            }
        }

        if (!force && inflight?.key === key) {
            status.value = 'loading';

            return inflight.promise;
        }

        status.value = 'loading';
        message.value = null;
        retryAfter.value = null;

        const started = epoch;
        const entry = { key, promise: null as unknown as Promise<T | null> };

        entry.promise = (async () => {
            let outcome: { answer: unknown } | { error: unknown };

            try {
                outcome = {
                    answer: await latest.run((signal) =>
                        options.fetcher(query, { signal }),
                    ),
                };
            } catch (error) {
                outcome = { error };
            }

            if (inflight === entry) {
                inflight = null;
            }

            // An answer for the previous user is neither shown nor cached.
            if (isStale(owner)) {
                if (status.value === 'loading') {
                    status.value = 'idle';
                }

                return null;
            }

            if ('error' in outcome) {
                return fail(outcome.error, query);
            }

            if (outcome.answer === KINETIX_SUPERSEDED) {
                return null;
            }

            const answer = outcome.answer as {
                value: T;
                meta?: KinetixLookupMeta | null;
            };
            const answerMeta = answer.meta ?? null;

            apply(answer.value, answerMeta);

            if (
                namespace !== null &&
                started === epoch &&
                status.value === 'success'
            ) {
                setKinetixSearchCache(
                    namespace,
                    trimmed,
                    { value: answer.value, meta: answerMeta },
                    options.cacheTtl ?? limits.cacheTtl.value * 1000,
                    limits.cacheEntries.value,
                );
            }

            return answer.value;
        })();

        inflight = entry;

        return entry.promise;
    }

    function search(
        term: string,
        opts: { immediate?: boolean; force?: boolean; silent?: boolean } = {},
    ): Promise<T | null> {
        clearDebounce();
        clearRetry();
        retried = false;
        silent = opts.silent ?? false;

        const query = normalize(term);
        const length = query.trim().length;

        if (length > 0 && length < minChars()) {
            latest.cancel();
            inflight = null;
            results.value = null;
            // The number the hint and the announcement should say.
            meta.value = { below_min: true, min_chars: minChars() };
            message.value = null;
            retryAfter.value = null;
            status.value = 'tooShort';
            settle();

            return Promise.resolve(null);
        }

        if (opts.immediate) {
            return execute(query, opts.force ?? false);
        }

        status.value = 'debouncing';

        return new Promise<T | null>((resolve) => {
            waiting = resolve;
            debounceTimer = setTimeout(() => {
                debounceTimer = null;
                waiting = null;
                void execute(query, opts.force ?? false).then(resolve);
            }, options.debounce ?? limits.debounce.value);
        });
    }

    function cancel(): void {
        clearDebounce();
        clearRetry();
        latest.cancel();
        inflight = null;

        if (
            status.value === 'debouncing' ||
            status.value === 'loading' ||
            status.value === 'throttled'
        ) {
            status.value = 'idle';
        }
    }

    function reset(): void {
        cancel();
        results.value = null;
        meta.value = null;
        message.value = null;
        retryAfter.value = null;
        status.value = 'idle';
    }

    function invalidate(): void {
        epoch++;
        inflight = null;

        const namespace = options.cacheKey?.() ?? null;

        if (namespace !== null) {
            clearKinetixSearchCache(namespace);
        }
    }

    if (getCurrentScope()) {
        onScopeDispose(cancel);
    }

    return {
        results,
        status,
        loading,
        message,
        retryAfter,
        retrying,
        meta,
        search,
        cancel,
        reset,
        invalidate,
    };
}

export interface KinetixSearchAnnouncerOptions<T> {
    /** How many results an answer holds. */
    count: (results: T) => number;
    /**
     * Announce "type at least N characters" too. Off where the control
     * already renders that hint in its own live region.
     */
    tooShort?: boolean;
}

/**
 * An `onSettled` callback that tells screen readers how a search ended: the
 * result count, a throttled retry or a timeout. It runs once per settled
 * search (typing is debounced, a superseded request never settles), so it is
 * not a per-keystroke chatter. Call it in `setup()` (it reads the i18n
 * instance).
 */
export function useKinetixSearchAnnouncer<T>(
    config: KinetixSearchAnnouncerOptions<T>,
): (state: KinetixSearchSettled<T>) => void {
    const { t } = useI18n();
    const { announce } = useKinetixAnnounce();

    return (state) => {
        if (state.silent) {
            return;
        }

        switch (state.status) {
            case 'success': {
                const count = state.results ? config.count(state.results) : 0;

                announce(t('kinetix.search_results_count', { count }, count));

                break;
            }
            case 'tooShort':
                if (config.tooShort !== false) {
                    announce(
                        t('kinetix.search_min_chars', {
                            count: state.meta?.min_chars ?? 1,
                        }),
                    );
                }

                break;
            case 'throttled':
                // A throttle out of retries is a failure: the control's own
                // alert or toast says it, so only the retry is announced.
                if (state.retrying) {
                    announce(t('kinetix.search_throttled_retrying'));
                }

                break;
            case 'timedOut':
                announce(t('kinetix.search_timed_out'));

                break;
        }
    };
}
