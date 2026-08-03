import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { createI18n } from 'vue-i18n';

const visit = vi.fn((_url: string, opts: any) => opts?.onFinish?.());
vi.mock('@inertiajs/vue3', () => ({
    router: { visit: (url: string, opts: any) => visit(url, opts) },
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
}));

const success = vi.fn();
const error = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: {
        success: (...a: unknown[]) => success(...a),
        error: (...a: unknown[]) => error(...a),
    },
}));

import {
    executeAction,
    useActionConfirmation,
} from '@/composables/useKinetixActions';

describe('executeAction', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        fetchMock.mockResolvedValue({});
    });

    it('awaits a background httpRequest and toasts the success message', async () => {
        await executeAction({
            httpRequest: { method: 'post', toast: 'Done' },
            url: '/x',
        } as any);

        expect(fetchMock).toHaveBeenCalledWith(
            '/x',
            expect.objectContaining({ method: 'post' }),
        );
        expect(success).toHaveBeenCalledWith('Done');
    });

    it('rejects when the httpRequest fails (caller surfaces it)', async () => {
        fetchMock.mockRejectedValueOnce(new Error('boom'));

        await expect(
            executeAction({ httpRequest: {}, url: '/x' } as any),
        ).rejects.toThrow('boom');
    });

    it('resolves an inertiaVisit only when Inertia finishes', async () => {
        await executeAction({
            inertiaVisit: { method: 'delete' },
            url: '/y',
        } as any);

        expect(visit).toHaveBeenCalledWith(
            '/y',
            expect.objectContaining({ method: 'delete' }),
        );
    });

    it('opens external new-tab links with noopener,noreferrer', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);

        await executeAction({
            url: 'https://ext.test',
            shouldOpenInNewTab: true,
        } as any);

        expect(open).toHaveBeenCalledWith(
            'https://ext.test',
            '_blank',
            'noopener,noreferrer',
        );
        open.mockRestore();
    });
});

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en: { kinetix: { action_failed: 'Failed' } } },
});

const mountConfirm = () => {
    let api: ReturnType<typeof useActionConfirmation>;

    const Harness = defineComponent({
        setup() {
            api = useActionConfirmation();

            return () => h('div');
        },
    });

    mount(Harness, { global: { plugins: [i18n] } });

    return api!;
};

describe('useActionConfirmation', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        fetchMock.mockResolvedValue({});
    });

    it('opens the modal for confirm-required actions, closing only after it resolves', async () => {
        const api = mountConfirm();

        api.requestAction({
            requiresConfirmation: true,
            httpRequest: {},
            url: '/x',
        } as any);
        expect(api.isConfirmOpen.value).toBe(true);

        await api.confirm();
        expect(api.isConfirmOpen.value).toBe(false);
        expect(api.processing.value).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('ignores a repeat confirm while processing (no double submit)', async () => {
        fetchMock.mockImplementationOnce(
            () => new Promise((r) => setTimeout(() => r({}), 20)),
        );
        const api = mountConfirm();

        api.requestAction({
            requiresConfirmation: true,
            httpRequest: {},
            url: '/x',
        } as any);

        const first = api.confirm(); // in flight (processing = true)
        await api.confirm(); // must no-op while processing
        await first;

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('surfaces the server error message on failure', async () => {
        fetchMock.mockRejectedValueOnce(new Error('nope'));
        const api = mountConfirm();

        api.requestAction({
            requiresConfirmation: true,
            httpRequest: {},
            url: '/x',
        } as any);
        await api.confirm();

        expect(error).toHaveBeenCalledWith('nope');
        expect(api.isConfirmOpen.value).toBe(false);
    });
});
