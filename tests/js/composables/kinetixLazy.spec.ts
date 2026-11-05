import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createSSRApp, defineComponent, h, nextTick, ref } from 'vue';
import type { Component } from 'vue';
import { renderToString } from 'vue/server-renderer';
import {
    defineKinetixLazy,
    isKinetixLazy,
    KinetixLazyBlockSkeleton,
    kinetixComponentOf,
    preloadKinetixLazy,
} from '@/composables/kinetixLazy';

const Inner = defineComponent({
    name: 'Inner',
    props: { label: { type: String, default: '' } },
    setup: (props) => () => h('p', { class: 'inner' }, `inner ${props.label}`),
});

/** A loader whose promise the test settles by hand. */
const deferredLoader = () => {
    let settle!: (value: Component) => void;
    let fail!: (error: unknown) => void;
    const loader = vi.fn(
        () =>
            new Promise<Component>((resolve, reject) => {
                settle = resolve;
                fail = reject;
            }),
    );

    return {
        loader,
        settle: (value: Component) => settle(value),
        fail: (error: unknown) => fail(error),
    };
};

/** Mount inside a host element, so an empty render still has a root. */
const mountIn = (component: Component, props: Record<string, unknown> = {}) =>
    mount({ render: () => h('div', [h(component, props)]) });

afterEach(() => {
    vi.restoreAllMocks();
});

describe('defineKinetixLazy', () => {
    it('downloads once however many callers ask', async () => {
        const loader = vi.fn(async () => Inner);
        const lazy = defineKinetixLazy(loader);

        const [first, second] = await Promise.all([
            lazy.preload(),
            lazy.preload(),
        ]);
        await lazy.preload();

        expect(loader).toHaveBeenCalledTimes(1);
        expect(first).toBe(Inner);
        expect(second).toBe(Inner);
    });

    it('retries after a failed download', async () => {
        const loader = vi
            .fn<() => Promise<Component>>()
            .mockRejectedValueOnce(new Error('chunk gone'))
            .mockResolvedValueOnce(Inner);
        const lazy = defineKinetixLazy(loader);

        await expect(lazy.preload()).rejects.toThrow('chunk gone');
        expect(lazy.loaded).toBe(false);

        await expect(lazy.preload()).resolves.toBe(Inner);
        expect(loader).toHaveBeenCalledTimes(2);
        expect(lazy.loaded).toBe(true);
    });

    it('answers a wrapper until loaded, then the real component', async () => {
        const lazy = defineKinetixLazy(async () => Inner);

        expect(lazy.loaded).toBe(false);
        expect(lazy.component).not.toBe(Inner);

        await lazy.preload();

        expect(lazy.loaded).toBe(true);
        expect(lazy.component).toBe(Inner);
    });

    it('unwraps a module namespace', async () => {
        const lazy = defineKinetixLazy(async () => ({ default: Inner }));

        await expect(lazy.preload()).resolves.toBe(Inner);
    });

    it('shows an aria-hidden field skeleton while loading, then the component', async () => {
        const { loader, settle } = deferredLoader();
        const lazy = defineKinetixLazy(loader);

        const wrapper = mountIn(lazy.component, { label: 'ok' });
        await flushPromises();

        const skeleton = wrapper.find('[data-kinetix-lazy-skeleton="field"]');
        expect(skeleton.exists()).toBe(true);
        expect(skeleton.attributes('aria-hidden')).toBe('true');
        expect(skeleton.classes()).toEqual(
            expect.arrayContaining(['h-9', 'w-full', 'animate-pulse']),
        );

        settle(Inner);
        await flushPromises();

        expect(wrapper.find('[data-kinetix-lazy-skeleton]').exists()).toBe(
            false,
        );
        expect(wrapper.find('.inner').text()).toBe('inner ok');
    });

    it('renders the block skeleton busy, with hidden bars', async () => {
        const { loader } = deferredLoader();
        const lazy = defineKinetixLazy(loader, {
            loading: KinetixLazyBlockSkeleton,
        });

        const wrapper = mountIn(lazy.component);
        await flushPromises();

        const block = wrapper.find('[data-kinetix-lazy-skeleton="block"]');
        expect(block.attributes('aria-busy')).toBe('true');

        const bars = block.findAll('div');
        expect(bars).toHaveLength(3);
        expect(
            bars.every((bar) => bar.attributes('aria-hidden') === 'true'),
        ).toBe(true);
    });

    it('renders nothing while loading when loading is off', async () => {
        const { loader } = deferredLoader();
        const lazy = defineKinetixLazy(loader, { loading: false });

        const wrapper = mountIn(lazy.component);
        await flushPromises();

        expect(wrapper.find('[data-kinetix-lazy-skeleton]').exists()).toBe(
            false,
        );
    });

    it('retries a failed download within the mount, with a short backoff', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout'] });
        const loader = vi
            .fn<() => Promise<Component>>()
            .mockRejectedValueOnce(new Error('blip'))
            .mockRejectedValueOnce(new Error('blip'))
            .mockResolvedValueOnce(Inner);
        const lazy = defineKinetixLazy(loader);

        try {
            const wrapper = mountIn(lazy.component, { label: 'retried' });
            await flushPromises();
            expect(wrapper.find('.inner').exists()).toBe(false);

            await vi.advanceTimersByTimeAsync(250);
            await flushPromises();
            await vi.advanceTimersByTimeAsync(500);
            await flushPromises();

            expect(loader).toHaveBeenCalledTimes(3);
            expect(wrapper.find('.inner').text()).toBe('inner retried');
        } finally {
            vi.useRealTimers();
        }
    });

    it('a wrapper held before a failed download renders the real component once a later download succeeds', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout'] });
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        let calls = 0;
        const lazy = defineKinetixLazy(() => {
            calls++;

            return calls <= 3
                ? Promise.reject(new Error('chunk gone'))
                : Promise.resolve({ default: Inner });
        });
        // What a host or a table holds for its lifetime (read once).
        const held = lazy.component;
        const errorHandler = vi.fn();
        const mountHeld = () =>
            mount(
                { render: () => h('div', [h(held, { label: 'held' })]) },
                { global: { config: { errorHandler } } },
            );

        try {
            const stuck = mountHeld();
            await flushPromises();
            await vi.advanceTimersByTimeAsync(250);
            await flushPromises();
            await vi.advanceTimersByTimeAsync(500);
            await flushPromises();

            // Gave up after the retries: the skeleton stays, nothing crashes.
            expect(calls).toBe(3);
            expect(stuck.find('[data-kinetix-lazy-skeleton]').exists()).toBe(
                true,
            );

            await lazy.preload();
            expect(lazy.loaded).toBe(true);
            await flushPromises();

            // The stuck instance recovers, and so does a later mount of the
            // very same held wrapper.
            expect(stuck.find('.inner').text()).toBe('inner held');
            const later = mountHeld();
            await flushPromises();
            expect(later.find('.inner').text()).toBe('inner held');
            expect(later.find('[data-kinetix-lazy-skeleton]').exists()).toBe(
                false,
            );
        } finally {
            vi.useRealTimers();
        }
    });

    it('never remounts a body that loaded through the held wrapper', async () => {
        const setups = vi.fn();
        const Counted = defineComponent({
            setup: () => {
                setups();

                return () => h('p', { class: 'counted' }, 'counted');
            },
        });
        const { loader, settle } = deferredLoader();
        const lazy = defineKinetixLazy(loader);
        const wrapper = mountIn(lazy.component);
        await flushPromises();

        settle(Counted);
        await flushPromises();
        await lazy.preload();
        await flushPromises();

        expect(wrapper.find('.counted').exists()).toBe(true);
        expect(setups).toHaveBeenCalledTimes(1);
    });

    it('hands vnode hooks on to the real component', async () => {
        const { loader, settle } = deferredLoader();
        const lazy = defineKinetixLazy(loader);
        const mountedEls: string[] = [];
        mount({
            render: () =>
                h('div', [
                    h(lazy.component, {
                        onVnodeMounted: (vnode: { el: Element | null }) =>
                            mountedEls.push(vnode.el?.className ?? ''),
                    }),
                ]),
        });
        await flushPromises();

        settle(Inner);
        await flushPromises();

        expect(mountedEls).toContain('inner');
    });

    it('lets a template ref reach what the real component exposes, once loaded', async () => {
        const Exposing = defineComponent({
            setup: (_, { expose }) => {
                expose({ hello: () => 'hi' });

                return () => h('p', { class: 'exposing' }, 'exposing');
            },
        });
        const { loader, settle } = deferredLoader();
        const lazy = defineKinetixLazy(loader);
        const held = ref<{ hello?: () => string } | null>(null);
        mount({ render: () => h('div', [h(lazy.component, { ref: held })]) });
        await flushPromises();

        // Before the body loads there is nothing to call.
        expect(held.value).not.toBeNull();
        expect(held.value?.hello).toBeUndefined();
        expect('hello' in held.value!).toBe(false);

        settle(Exposing);
        await flushPromises();

        expect('hello' in held.value!).toBe(true);
        expect(held.value?.hello?.()).toBe('hi');
    });

    it('reads vnode hooks as the parent passes them now', async () => {
        const { loader, settle } = deferredLoader();
        const lazy = defineKinetixLazy(loader);
        const which = ref('first');
        const seen: string[] = [];
        mount({
            render: () => {
                // Each render hands a new hook bound to that render's value.
                const tag = which.value;

                return h('div', [
                    h(lazy.component, {
                        label: tag,
                        onVnodeMounted: (vnode: { el: Element | null }) =>
                            seen.push(`${tag}:${vnode.el?.className}`),
                    }),
                ]);
            },
        });
        await flushPromises();

        which.value = 'second';
        await nextTick();
        settle(Inner);
        await flushPromises();

        expect(seen).toContain('second:inner');
        expect(seen).not.toContain('first:inner');
    });

    it('server-renders the real markup, never the skeleton', async () => {
        const lazy = defineKinetixLazy(async () => Inner);

        const html = await renderToString(
            createSSRApp({ render: () => h(lazy.component, { label: 'ssr' }) }),
        );

        expect(html).toContain('inner ssr');
        expect(html).not.toContain('animate-pulse');
    });
});

describe('kinetixComponentOf', () => {
    it('passes a plain component through and resolves a lazy one', async () => {
        const lazy = defineKinetixLazy(async () => Inner);

        expect(kinetixComponentOf(Inner)).toBe(Inner);
        expect(kinetixComponentOf(lazy)).toBe(lazy.component);
        expect(isKinetixLazy(lazy)).toBe(true);
        expect(isKinetixLazy(Inner)).toBe(false);

        await lazy.preload();
        expect(kinetixComponentOf(lazy)).toBe(Inner);
    });
});

describe('preloadKinetixLazy', () => {
    it('starts every pending download at once, skipping loaded and empty entries', async () => {
        const first = deferredLoader();
        const second = deferredLoader();
        const a = defineKinetixLazy(first.loader);
        const b = defineKinetixLazy(second.loader);
        const done = defineKinetixLazy(async () => Inner);
        await done.preload();
        const doneSpy = vi.spyOn(done, 'preload');

        const all = preloadKinetixLazy([a, b, a, null, undefined, done]);

        expect(first.loader).toHaveBeenCalledTimes(1);
        expect(second.loader).toHaveBeenCalledTimes(1);
        expect(doneSpy).not.toHaveBeenCalled();

        first.settle(Inner);
        second.settle(Inner);
        await all;

        expect(a.loaded && b.loaded).toBe(true);
    });

    it('swallows a failure with a warning', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const broken = defineKinetixLazy(async () => {
            throw new Error('chunk gone');
        });

        await expect(preloadKinetixLazy([broken])).resolves.toBeUndefined();
        expect(warn).toHaveBeenCalledTimes(1);
    });
});
