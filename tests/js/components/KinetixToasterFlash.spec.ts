import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';

const toastFns = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
}));

vi.mock('vue-sonner', () => ({
    Toaster: { name: 'Toaster', template: '<div data-test="toaster" />' },
    toast: Object.assign(vi.fn(), toastFns),
}));

// A mutable page-props stand-in so tests can push flash toasts in.
const page = reactive<{
    props: Record<string, unknown>;
    flash?: Record<string, unknown>;
}>({ props: {} });
type Handler = (event: { detail: { flash: unknown } }) => void;
const flashHandlers = new Set<Handler>();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: {
        on: (name: string, handler: Handler) => {
            if (name !== 'flash') {
                return () => {};
            }

            flashHandlers.add(handler);

            return () => flashHandlers.delete(handler);
        },
    },
}));

import KinetixToaster from '@/components/KinetixToaster.vue';

// Shown uuids are remembered for the life of the module (the tab), so every
// test uses its own — exactly as the server never reuses one.
describe('KinetixToaster flash → toast', () => {
    beforeEach(() => {
        page.props = {};
        toastFns.success.mockClear();
        toastFns.error.mockClear();
    });

    it('fires a toast when a kinetix_toast flash prop arrives', async () => {
        const wrapper = mount(KinetixToaster);

        page.props.kinetix_toast = {
            type: 'success',
            message: 'Record created successfully.',
            id: 'uuid-1',
        };
        await nextTick();

        expect(toastFns.success).toHaveBeenCalledWith(
            'Record created successfully.',
        );

        wrapper.unmount();
    });

    it('fires the same message twice when the flash id changes, but not on a re-render with the same id', async () => {
        const wrapper = mount(KinetixToaster);

        page.props.kinetix_toast = {
            type: 'success',
            message: 'Saved.',
            id: 'uuid-repeat-1',
        };
        await nextTick();

        // Same id re-shipped (e.g. a partial reload) → no duplicate.
        page.props.kinetix_toast = {
            type: 'success',
            message: 'Saved.',
            id: 'uuid-repeat-1',
        };
        await nextTick();

        expect(toastFns.success).toHaveBeenCalledTimes(1);

        // A NEW flash with the same text → fires again.
        page.props.kinetix_toast = {
            type: 'success',
            message: 'Saved.',
            id: 'uuid-repeat-2',
        };
        await nextTick();

        expect(toastFns.success).toHaveBeenCalledTimes(2);

        wrapper.unmount();
    });

    it('routes the type to the matching sonner variant', async () => {
        const wrapper = mount(KinetixToaster);

        page.props.kinetix_toast = {
            type: 'error',
            message: 'Nope.',
            id: 'uuid-3',
        };
        await nextTick();

        expect(toastFns.error).toHaveBeenCalledWith('Nope.');
        expect(toastFns.success).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it('shows a toast already flashed at mount time (full page load after redirect)', async () => {
        page.props.kinetix_toast = {
            type: 'success',
            message: 'Record deleted successfully.',
            id: 'uuid-4',
        };

        const wrapper = mount(KinetixToaster);
        await nextTick();

        expect(toastFns.success).toHaveBeenCalledWith(
            'Record deleted successfully.',
        );

        wrapper.unmount();
    });

    it('stays silent when Back/Forward restores a page that still carries its old toast', async () => {
        const first = mount(KinetixToaster);

        page.props.kinetix_toast = {
            type: 'success',
            message: 'Invoice sent.',
            id: 'uuid-history',
        };
        await nextTick();
        expect(toastFns.success).toHaveBeenCalledTimes(1);

        // The user navigates on (a fresh layout, no toast)…
        first.unmount();
        page.props = {};
        const second = mount(KinetixToaster);
        await nextTick();

        // …then presses Back: Inertia restores the props from history.
        page.props = {
            kinetix_toast: {
                type: 'success',
                message: 'Invoice sent.',
                id: 'uuid-history',
            },
        };
        await nextTick();

        expect(toastFns.success).toHaveBeenCalledTimes(1);

        second.unmount();
    });

    it('shows toasts from Inertia flash — on arrival and on later flashes', async () => {
        page.flash = {
            kinetix: {
                toasts: [
                    {
                        id: 'flash-1',
                        type: 'success',
                        message: 'Invoice sent.',
                    },
                ],
            },
        };
        const wrapper = mount(KinetixToaster);
        expect(toastFns.success).toHaveBeenCalledWith('Invoice sent.');

        flashHandlers.forEach((h) =>
            h({
                detail: {
                    flash: {
                        kinetix: {
                            toasts: [
                                {
                                    id: 'flash-2',
                                    type: 'error',
                                    message: 'Sync failed',
                                    description: 'Try again in a minute.',
                                    duration: 8000,
                                },
                            ],
                        },
                    },
                },
            }),
        );

        expect(toastFns.error).toHaveBeenCalledWith('Sync failed', {
            description: 'Try again in a minute.',
            duration: 8000,
        });

        // The same flash delivered twice (page + event) toasts once.
        flashHandlers.forEach((h) => h({ detail: { flash: page.flash } }));
        expect(toastFns.success).toHaveBeenCalledTimes(1);

        wrapper.unmount();
        expect(flashHandlers.size).toBe(0);
        page.flash = undefined;
    });
});
