import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The bell against the REAL @laravel/echo-vue with Echo never configured (the
 * host without broadcasting): setup must not throw, and the database poll
 * must run. Only the socket client, Inertia and the HTTP helper are faked.
 */
vi.mock('pusher-js', () => ({ default: class {} }));

const state = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    reload: null as unknown as ReturnType<typeof import('vitest').vi.fn>,
    fetch: null as unknown as ReturnType<typeof import('vitest').vi.fn>,
}));

vi.mock('@inertiajs/vue3', async () => {
    const { vi: hoistedVi } = await import('vitest');
    state.reload = hoistedVi.fn();

    return {
        usePage: () => ({ props: state.props }),
        router: { reload: (...args: unknown[]) => state.reload(...args) },
    };
});

vi.mock('@/composables/useKinetixHttp', async (importOriginal) => {
    const { vi: hoistedVi } = await import('vitest');
    state.fetch = hoistedVi.fn();

    return {
        ...(await importOriginal<
            typeof import('@/composables/useKinetixHttp')
        >()),
        kinetixFetch: (...args: unknown[]) => state.fetch(...args),
    };
});

vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
    },
}));

import { toast } from 'vue-sonner';
import KinetixNotifications from '@/components/KinetixNotifications.vue';
import { useNotificationsStore } from '@/stores/kinetixNotifications';
import { i18n } from './i18n';

function setHidden(hidden: boolean): void {
    Object.defineProperty(document, 'hidden', {
        configurable: true,
        get: () => hidden,
    });
    document.dispatchEvent(new Event('visibilitychange'));
}

const feedReads = (): unknown[] =>
    state.fetch.mock.calls.filter(
        ([url, options]) =>
            url === '/_kinetix/notifications' &&
            (options === undefined ||
                (options as { method?: string }).method === undefined),
    );

const partialReloads = (): unknown[] =>
    state.reload.mock.calls.filter(([options]) =>
        ((options as { only?: string[] } | undefined)?.only ?? []).includes(
            'kinetix_notifications',
        ),
    );

describe('KinetixNotifications without Echo configured', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        state.props = {
            kinetix_config: {
                database: true,
                poll: 1000,
                realtime: true,
                broadcasting: null,
                route_prefix: '_kinetix',
            },
            kinetix_notifications: [],
            auth: { user: { id: 1 } },
        };
        state.reload.mockReset();
        state.fetch.mockReset();
        state.fetch.mockResolvedValue({ notifications: [] });
        vi.mocked(toast.info).mockClear();
        vi.useFakeTimers();
        setHidden(false);
    });

    afterEach(() => {
        setHidden(false);
        vi.useRealTimers();
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('mounts without throwing or logging an error', async () => {
        const errors = vi.spyOn(console, 'error');

        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(wrapper.find('.kinetix-notifications-container').exists()).toBe(
            true,
        );
        expect(errors).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('polls the JSON feed on the configured interval, never the page', async () => {
        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        // The page props already carry the list: no request at mount.
        expect(feedReads()).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1000);
        expect(feedReads()).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(1000);
        expect(feedReads()).toHaveLength(2);

        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(5000);
        expect(feedReads()).toHaveLength(2);

        // A partial reload would re-run the host page's controller.
        expect(partialReloads()).toHaveLength(0);
        expect(state.reload).not.toHaveBeenCalled();
    });

    it('syncs what the feed returns into the bell', async () => {
        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();
        await vi.advanceTimersByTimeAsync(500);

        state.fetch.mockResolvedValue({
            notifications: [
                {
                    id: 'n1',
                    title: 'Export ready',
                    status: 'info',
                    read: false,
                    created_at: '2026-11-05T00:00:00Z',
                },
            ],
        });
        await vi.advanceTimersByTimeAsync(1000);

        const store = useNotificationsStore();
        expect(store.notifications.map((n) => n.id)).toEqual(['n1']);
        expect(store.unreadCount).toBe(1);
        expect(toast.info).toHaveBeenCalledTimes(1);

        // The same item on the next tick is not toasted again.
        await vi.advanceTimersByTimeAsync(1000);
        expect(toast.info).toHaveBeenCalledTimes(1);

        wrapper.unmount();
    });

    it('pauses while the tab is hidden and refreshes once visible again', async () => {
        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        setHidden(true);
        await vi.advanceTimersByTimeAsync(10_000);
        expect(feedReads()).toHaveLength(0);

        setHidden(false);
        expect(feedReads()).toHaveLength(1);

        wrapper.unmount();
    });

    it('keeps polling when the feed fails (expired session, network)', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        state.fetch
            .mockRejectedValueOnce(
                Object.assign(new Error('Unauthenticated.'), { status: 401 }),
            )
            .mockRejectedValueOnce(new TypeError('Failed to fetch'));

        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        await vi.advanceTimersByTimeAsync(1000);
        expect(feedReads()).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1000);
        expect(feedReads()).toHaveLength(2);
        await vi.advanceTimersByTimeAsync(1000);
        expect(feedReads()).toHaveLength(3);
        expect(warn).toHaveBeenCalledTimes(2);

        wrapper.unmount();
    });

    it('keeps polling when a request never reports back', async () => {
        state.fetch.mockImplementationOnce(() => new Promise(() => {}));

        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        await vi.advanceTimersByTimeAsync(1000);
        expect(feedReads()).toHaveLength(1);

        // The run timeout (30 s minimum) releases the lost request.
        await vi.advanceTimersByTimeAsync(31_000);
        expect(feedReads()).toHaveLength(2);

        wrapper.unmount();
    });

    it('does not overlap a request that has not finished', async () => {
        let finish: () => void = () => {};
        state.fetch.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = () => resolve({ notifications: [] });
                }),
        );

        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        await vi.advanceTimersByTimeAsync(5000);
        expect(feedReads()).toHaveLength(1);

        finish();
        await vi.advanceTimersByTimeAsync(1000);
        expect(feedReads()).toHaveLength(2);

        wrapper.unmount();
    });

    it('does not poll when polling is off', async () => {
        state.props = {
            ...state.props,
            kinetix_config: {
                ...(state.props.kinetix_config as object),
                poll: 0,
            },
        };

        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await vi.advanceTimersByTimeAsync(10_000);

        expect(feedReads()).toHaveLength(0);
        wrapper.unmount();
    });
});
