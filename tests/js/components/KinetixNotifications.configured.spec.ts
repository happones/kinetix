import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The bell against the REAL @laravel/echo-vue configured by the host
 * (`configureEcho` in app.ts): it listens on the user's private channel and,
 * on unmount, stops listening WITHOUT leaving the channel (host code shares it).
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

import { configureEcho, echo } from '@laravel/echo-vue';
import { toast } from 'vue-sonner';
import KinetixNotifications from '@/components/KinetixNotifications.vue';
import { useNotificationsStore } from '@/stores/kinetixNotifications';
import { i18n } from './i18n';

describe('KinetixNotifications with Echo configured', () => {
    const channel = {
        notification: vi.fn(),
        stopListeningForNotification: vi.fn(),
    };
    let privateSpy: ReturnType<typeof vi.spyOn>;
    let leaveSpy: ReturnType<typeof vi.spyOn>;
    let leaveChannelSpy: ReturnType<typeof vi.spyOn>;

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

        configureEcho({ broadcaster: 'null' });
        const instance = echo() as any;
        channel.notification.mockClear();
        channel.stopListeningForNotification.mockClear();
        privateSpy = vi.spyOn(instance, 'private').mockReturnValue(channel);
        leaveSpy = vi.spyOn(instance, 'leave');
        leaveChannelSpy = vi.spyOn(instance, 'leaveChannel');
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('listens on the user channel and releases without leaving it', async () => {
        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(privateSpy).toHaveBeenCalledWith('App.Models.User.1');
        expect(channel.notification).toHaveBeenCalledTimes(1);

        const handler = channel.notification.mock.calls[0][0];

        wrapper.unmount();

        expect(channel.stopListeningForNotification).toHaveBeenCalledWith(
            handler,
        );
        expect(leaveSpy).not.toHaveBeenCalled();
        expect(leaveChannelSpy).not.toHaveBeenCalled();
    });

    it('honours a custom channel model', async () => {
        const wrapper = mount(KinetixNotifications, {
            props: { channelModel: 'App.Models.Admin' },
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(privateSpy).toHaveBeenCalledWith('App.Models.Admin.1');
        wrapper.unmount();
    });

    it('stays off Echo when realtime is off', async () => {
        const wrapper = mount(KinetixNotifications, {
            props: { realtime: false },
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(privateSpy).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('follows the server flag when the prop is unset', async () => {
        state.props = {
            ...state.props,
            kinetix_config: {
                ...(state.props.kinetix_config as object),
                realtime: false,
            },
        };

        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(privateSpy).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('falls back to the broadcasting config on an older server', async () => {
        const { realtime: _realtime, ...config } = state.props
            .kinetix_config as Record<string, unknown>;
        state.props = {
            ...state.props,
            kinetix_config: {
                ...config,
                broadcasting: { broadcaster: 'null' },
            },
        };

        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(privateSpy).toHaveBeenCalledWith('App.Models.User.1');
        wrapper.unmount();
    });

    it('releases a subscription that resolves after unmount', async () => {
        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        wrapper.unmount();
        await flushPromises();

        const registered = channel.notification.mock.calls.length;
        expect(channel.stopListeningForNotification).toHaveBeenCalledTimes(
            registered,
        );
    });

    it('delivers a broadcast to the store as a toast-worthy notification', async () => {
        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        const handler = channel.notification.mock.calls[0][0];

        expect(() =>
            handler({ id: 'n1', title: 'Hello', status: 'info' }),
        ).not.toThrow();
        wrapper.unmount();
    });

    it('refreshes from the JSON feed on a broadcast without toasting twice', async () => {
        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();
        // Past the silent initial sync.
        await new Promise((resolve) => setTimeout(resolve, 550));

        const arrived = {
            id: 'n1',
            title: 'Hello',
            status: 'info',
            read: false,
            created_at: '2026-11-05T00:00:00Z',
        };
        state.fetch.mockResolvedValue({ notifications: [arrived] });

        const handler = channel.notification.mock.calls[0][0];
        handler(arrived);
        await flushPromises();

        expect(state.fetch).toHaveBeenCalledWith('/_kinetix/notifications');
        expect(state.reload).not.toHaveBeenCalled();
        // The broadcast toasted it; the feed sync that follows does not.
        expect(toast.info).toHaveBeenCalledTimes(1);
        expect(useNotificationsStore().notifications.map((n) => n.id)).toEqual([
            'n1',
        ]);

        wrapper.unmount();
    });
});
