import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The bell against the REAL @laravel/echo-vue with Echo never configured (the
 * host without broadcasting): setup must not throw, and the database poll
 * must run. Only the socket client and Inertia are faked.
 */
vi.mock('pusher-js', () => ({ default: class {} }));

const state = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    reload: null as unknown as ReturnType<typeof import('vitest').vi.fn>,
}));

vi.mock('@inertiajs/vue3', async () => {
    const { vi: hoistedVi } = await import('vitest');
    state.reload = hoistedVi.fn((options: { onFinish?: () => void }) =>
        options.onFinish?.(),
    );

    return {
        usePage: () => ({ props: state.props }),
        router: { reload: (...args: unknown[]) => state.reload(...args) },
    };
});

import KinetixNotifications from '@/components/KinetixNotifications.vue';
import { i18n } from './i18n';

function setHidden(hidden: boolean): void {
    Object.defineProperty(document, 'hidden', {
        configurable: true,
        get: () => hidden,
    });
    document.dispatchEvent(new Event('visibilitychange'));
}

const notificationReloads = (): unknown[] =>
    state.reload.mock.calls.filter(
        ([options]) =>
            JSON.stringify((options as { only?: string[] }).only) ===
            JSON.stringify(['kinetix_notifications']),
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
        state.reload.mockClear();
        state.reload.mockImplementation((options: { onFinish?: () => void }) =>
            options.onFinish?.(),
        );
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

    it('polls the notifications prop on the configured interval', async () => {
        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        // The page props already carry the list: no reload at mount.
        expect(notificationReloads()).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1000);
        expect(notificationReloads()).toHaveLength(1);
        expect(state.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['kinetix_notifications'],
                preserveErrors: true,
            }),
        );

        await vi.advanceTimersByTimeAsync(1000);
        expect(notificationReloads()).toHaveLength(2);

        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(5000);
        expect(notificationReloads()).toHaveLength(2);
    });

    it('pauses while the tab is hidden and reloads once visible again', async () => {
        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        setHidden(true);
        await vi.advanceTimersByTimeAsync(10_000);
        expect(notificationReloads()).toHaveLength(0);

        setHidden(false);
        expect(notificationReloads()).toHaveLength(1);

        wrapper.unmount();
    });

    it('keeps polling when a `before` listener cancels the reload', async () => {
        // A cancelled visit returns without calling any of its callbacks.
        state.reload.mockImplementation(() => undefined);

        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        await vi.advanceTimersByTimeAsync(1000);
        expect(notificationReloads()).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1000);
        expect(notificationReloads()).toHaveLength(2);
        await vi.advanceTimersByTimeAsync(1000);
        expect(notificationReloads()).toHaveLength(3);

        wrapper.unmount();
    });

    it('does not overlap a reload that has started but not finished', async () => {
        let finish: () => void = () => {};
        state.reload.mockImplementation(
            (options: { onStart?: () => void; onFinish?: () => void }) => {
                options.onStart?.();
                finish = () => options.onFinish?.();
            },
        );

        const wrapper = mount(KinetixNotifications, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        await vi.advanceTimersByTimeAsync(5000);
        expect(notificationReloads()).toHaveLength(1);

        finish();
        await vi.advanceTimersByTimeAsync(1000);
        expect(notificationReloads()).toHaveLength(2);

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

        expect(notificationReloads()).toHaveLength(0);
        wrapper.unmount();
    });
});
