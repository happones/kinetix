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
const page = reactive<{ props: Record<string, unknown> }>({ props: {} });
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

import KinetixToaster from '@/components/KinetixToaster.vue';

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
            id: 'uuid-1',
        };
        await nextTick();

        // Same id re-shipped (e.g. a partial reload) → no duplicate.
        page.props.kinetix_toast = {
            type: 'success',
            message: 'Saved.',
            id: 'uuid-1',
        };
        await nextTick();

        expect(toastFns.success).toHaveBeenCalledTimes(1);

        // A NEW flash with the same text → fires again.
        page.props.kinetix_toast = {
            type: 'success',
            message: 'Saved.',
            id: 'uuid-2',
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
});
