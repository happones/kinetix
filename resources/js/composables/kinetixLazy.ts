import {
    defineAsyncComponent,
    defineComponent,
    getCurrentInstance,
    h,
    markRaw,
    shallowRef,
} from 'vue';
import type { VNodeProps } from 'vue';
import type { Component } from 'vue';

/**
 * A component whose code downloads on first use instead of shipping in the
 * chunk of whatever renders it. Heavy, optional bodies (rarely used field
 * types, modal forms, layout features a host may leave off) go through
 * `defineKinetixLazy`, so a page only pays for what it actually shows.
 */
export interface KinetixLazyComponent {
    /** Render this: the real component once loaded, else an async wrapper. */
    readonly component: Component;
    readonly loaded: boolean;
    /** Start or join the download. Idempotent; resets on failure so a later call retries. */
    preload(): Promise<Component>;
}

export interface KinetixLazyOptions {
    /** Shown while the code downloads. Defaults to the field skeleton; `false` shows nothing. */
    loading?: Component | false;
    /** ms before the loading component appears. Defaults to 0. */
    delay?: number;
}

type LazyModule = Component | { default: Component };

const LAZY_BRAND = Symbol('kinetixLazy');

const BAR_CLASS = 'h-9 animate-pulse rounded-md bg-muted';

/** Extra attempts a mounted wrapper makes after a failed download. */
const RETRIES = 2;

/** ms before each retry, times the attempt number (a short linear backoff). */
const RETRY_BACKOFF = 250;

/**
 * Stand-in for a single input while its code loads: the input's height, so
 * nothing shifts when the control arrives. Hidden from assistive tech; the
 * field's label is rendered outside it.
 */
export const KinetixLazyFieldSkeleton: Component = markRaw(
    defineComponent({
        name: 'KinetixLazyFieldSkeleton',
        setup: () => () =>
            h('div', {
                class: `${BAR_CLASS} w-full`,
                'aria-hidden': 'true',
                'data-kinetix-lazy-skeleton': 'field',
            }),
    }),
);

/**
 * Stand-in for a whole body (a modal's form or infolist) while its code
 * loads: three bars on a container that reports itself busy.
 */
export const KinetixLazyBlockSkeleton: Component = markRaw(
    defineComponent({
        name: 'KinetixLazyBlockSkeleton',
        setup: () => () =>
            h(
                'div',
                {
                    class: 'space-y-4',
                    'aria-busy': 'true',
                    'data-kinetix-lazy-skeleton': 'block',
                },
                [
                    h('div', { class: BAR_CLASS, 'aria-hidden': 'true' }),
                    h('div', { class: BAR_CLASS, 'aria-hidden': 'true' }),
                    h('div', {
                        class: `${BAR_CLASS} w-2/3`,
                        'aria-hidden': 'true',
                    }),
                ],
            ),
    }),
);

/** The component a dynamic import resolved to, module namespace or not. */
const unwrapModule = (loaded: LazyModule): Component => {
    const candidate = loaded as Record<PropertyKey, unknown> | null;

    if (
        candidate &&
        typeof candidate === 'object' &&
        'default' in candidate &&
        (candidate.__esModule ||
            candidate[Symbol.toStringTag] === 'Module' ||
            !(
                'setup' in candidate ||
                'render' in candidate ||
                'template' in candidate
            ))
    ) {
        return candidate.default as Component;
    }

    return loaded as Component;
};

/**
 * Wrap a dynamic import as a lazy component.
 *
 * Define it once at module scope: the entry remembers the loaded component, so
 * every later render gets the real one synchronously. Server rendering awaits
 * the import, so the real markup is rendered there, never the skeleton.
 *
 * A failed download (a network blip, or a deploy that replaced the chunk) is
 * retried twice by the mounted wrapper, with a short backoff. If it still
 * fails, the wrapper keeps its skeleton, and the next `preload()` or the next
 * mount downloads again; a wrapper stuck on its skeleton switches to the real
 * component as soon as any later download succeeds. Hosts recover from stale
 * chunks after a deploy with Vite's `vite:preloadError` event.
 */
export function defineKinetixLazy(
    loader: () => Promise<LazyModule>,
    options: KinetixLazyOptions = {},
): KinetixLazyComponent {
    let resolved: Component | null = null;
    let pending: Promise<Component> | null = null;

    // Bumped when the real component arrives or a wrapper gives up, so a
    // wrapper stuck on its skeleton re-renders and can pick the real one.
    const settled = shallowRef(0);

    const preload = (): Promise<Component> => {
        if (resolved) {
            return Promise.resolve(resolved);
        }

        pending ??= loader().then(
            (loaded) => {
                resolved = markRaw(unwrapModule(loaded) as object) as Component;
                settled.value++;

                return resolved;
            },
            (error: unknown) => {
                pending = null;

                throw error;
            },
        );

        return pending;
    };

    const loadingComponent =
        options.loading === false
            ? undefined
            : (options.loading ?? KinetixLazyFieldSkeleton);

    // Vue's async component remembers a failed load for good, so the one
    // given up on is retired: later mounts get a fresh one that downloads
    // again.
    const failed = new WeakSet<Component>();

    const createAsync = (): Component => {
        const asyncComponent: Component = defineAsyncComponent({
            loader: preload,
            loadingComponent,
            delay: options.delay ?? 0,
            suspensible: false,
            onError: (error, retry, fail, attempts) => {
                if (attempts <= RETRIES) {
                    setTimeout(retry, RETRY_BACKOFF * attempts);

                    return;
                }

                failed.add(asyncComponent);
                current = createAsync();
                settled.value++;
                fail();
            },
        });

        return asyncComponent;
    };

    let current = createAsync();

    /**
     * The stable component consumers hold. Each instance picks its inner
     * component once (the real one if loaded, else the current async one), so
     * a loaded body is never swapped and remounted mid-edit; only an inner
     * that gave up is replaced, by the real component once it exists.
     *
     * A template `ref` on it reaches the real component's public API (what it
     * exposes) through a delegating proxy: `ref.value.focus()` works once the
     * body has loaded, and reads `undefined` before that.
     */
    const wrapper: Component = markRaw(
        defineComponent({
            name: 'KinetixLazy',
            inheritAttrs: false,
            setup(_, { attrs, slots, expose }) {
                let inner: Component = resolved ?? current;
                const instance = getCurrentInstance();
                const innerRef = shallowRef<Record<
                    PropertyKey,
                    unknown
                > | null>(null);

                expose(
                    new Proxy({} as Record<PropertyKey, unknown>, {
                        get: (_target, key) => innerRef.value?.[key],
                        has: (_target, key) =>
                            innerRef.value !== null && key in innerRef.value,
                    }),
                );

                /**
                 * Vnode hooks (`@vue:mounted`) aren't attrs; hand them on, as
                 * the parent passes them now, so they fire for the real
                 * component too.
                 */
                const vnodeHooks = (): VNodeProps => {
                    const hooks: Record<string, unknown> = {};

                    for (const [key, value] of Object.entries(
                        instance?.vnode.props ?? {},
                    )) {
                        if (key.startsWith('onVnode')) {
                            hooks[key] = value;
                        }
                    }

                    return hooks as VNodeProps;
                };

                return () => {
                    void settled.value;

                    if (resolved && inner !== resolved && failed.has(inner)) {
                        inner = resolved;
                    }

                    return h(
                        inner,
                        { ...attrs, ...vnodeHooks(), ref: innerRef },
                        slots,
                    );
                };
            },
        }),
    );

    return markRaw({
        [LAZY_BRAND]: true,
        get component(): Component {
            return resolved ?? wrapper;
        },
        get loaded(): boolean {
            return resolved !== null;
        },
        preload,
    }) as KinetixLazyComponent;
}

/** Whether a component-map entry is a lazy one. */
export function isKinetixLazy(entry: unknown): entry is KinetixLazyComponent {
    return (
        typeof entry === 'object' &&
        entry !== null &&
        (entry as Record<PropertyKey, unknown>)[LAZY_BRAND] === true
    );
}

/**
 * The component to render for a map entry, static or lazy, so one map can mix
 * both.
 *
 * Read it once per mounted instance (a computed over the field TYPE, or a
 * setup constant), not on every render: a lazy entry answers its wrapper until
 * the code arrives and the real component after, and switching between the
 * two remounts the subtree, dropping whatever the user had typed.
 */
export function kinetixComponentOf(
    entry: Component | KinetixLazyComponent,
): Component {
    return isKinetixLazy(entry) ? entry.component : entry;
}

/**
 * Start the downloads of several lazy entries at once, in parallel, so a page
 * that needs them doesn't fetch them one after another. Fire-and-forget: a
 * failure is only logged; the next preload or mount downloads again, and a
 * wrapper left on its skeleton switches to the component once one succeeds.
 */
export async function preloadKinetixLazy(
    entries: Iterable<KinetixLazyComponent | null | undefined>,
): Promise<void> {
    const unique = new Set<KinetixLazyComponent>();

    for (const entry of entries) {
        if (entry && !entry.loaded) {
            unique.add(entry);
        }
    }

    if (unique.size === 0) {
        return;
    }

    const results = await Promise.allSettled(
        [...unique].map((entry) => entry.preload()),
    );

    for (const result of results) {
        if (result.status === 'rejected') {
            console.warn(
                '[kinetix] A lazy component failed to load.',
                result.reason,
            );
        }
    }
}
