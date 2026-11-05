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
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: state.props }),
    router: {
        reload: (options: { onFinish?: () => void }) => options.onFinish?.(),
    },
}));

import { configureEcho, echo } from '@laravel/echo-vue';
import KinetixNotifications from '@/components/KinetixNotifications.vue';
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
});
