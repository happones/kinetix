import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

/**
 * Webhook test, webhook redelivery and spotlight search answer a localized
 * 429 when throttled. The client shows that message, never an unhandled
 * rejection, and never results for a query the server refused.
 */
const THROTTLED = 'Too many attempts. Try again in 30 seconds.';

const throttled = () =>
    Object.assign(new Error(THROTTLED), {
        status: 429,
        code: 'throttled',
        retryAfter: 30,
    });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { spotlight: { min_chars: 2 } } },
    }),
    router: { visit: vi.fn(), reload: vi.fn() },
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
    isKinetixAbort: (e: unknown) =>
        e instanceof DOMException && e.name === 'AbortError',
}));

const toastError = vi.fn();
const toastSuccess = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args: unknown[]) => toastSuccess(...args),
        error: (...args: unknown[]) => toastError(...args),
    },
}));

import KinetixIntegrationLogs from '@/components/KinetixIntegrationLogs.vue';
import KinetixWebhookManager from '@/components/KinetixWebhookManager.vue';
import SpotlightBody from '@/components/Spotlight/SpotlightBody.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: {} },
});

const mountIt = (component: unknown, props: Record<string, unknown> = {}) =>
    mount(component as never, {
        props,
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

// Any rejection that escapes a handler fails the spec.
const unhandled = vi.fn();

beforeEach(() => {
    fetchMock.mockReset();
    toastError.mockReset();
    toastSuccess.mockReset();
    unhandled.mockReset();
    process.on('unhandledRejection', unhandled);
});

afterEach(() => {
    process.off('unhandledRejection', unhandled);
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('webhook test', () => {
    const endpoint = {
        id: 5,
        name: 'Orders',
        url: 'https://example.com/hook',
        events: ['order.created'],
        active: true,
        createdAt: '2026-06-10T09:00:00Z',
    };

    const serve = (onTest: () => unknown) =>
        fetchMock.mockImplementation(async (url: string) =>
            url.endsWith('/test')
                ? onTest()
                : { endpoints: [endpoint], events: {} },
        );

    const testButton = (w: ReturnType<typeof mountIt>) =>
        w.findAll('button').find((b) => b.text() === 'kinetix.webhook_test')!;

    it('toasts the server message when throttled', async () => {
        serve(() => {
            throw throttled();
        });
        const w = mountIt(KinetixWebhookManager);
        await flushPromises();

        await testButton(w).trigger('click');
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith(THROTTLED);
        expect(toastSuccess).not.toHaveBeenCalled();
        expect(unhandled).not.toHaveBeenCalled();
        w.unmount();
    });

    it('keeps a generic message for any other failure, and the success toast otherwise', async () => {
        serve(() => {
            throw Object.assign(new Error('Server Error'), { status: 500 });
        });
        const w = mountIt(KinetixWebhookManager);
        await flushPromises();

        await testButton(w).trigger('click');
        await flushPromises();
        expect(toastError).toHaveBeenCalledWith('kinetix.action_failed');

        serve(() => ({ status: 'queued' }));
        await testButton(w).trigger('click');
        await flushPromises();
        expect(toastSuccess).toHaveBeenCalledWith(
            'kinetix.webhook_test_queued',
        );
        w.unmount();
    });
});

describe('webhook redelivery', () => {
    const page = {
        data: [
            {
                id: 1,
                event: 'order.created',
                statusCode: 500,
                success: false,
                attempt: 1,
                createdAt: '2026-07-10T10:00:00Z',
                payload: { order_id: 7 },
                response: 'boom',
                endpointName: 'Billing hook',
                endpointUrl: 'https://example.com/hook',
            },
        ],
        pagination: { current_page: 1, last_page: 1, total: 1 },
    };

    it('toasts the server message when throttled, and ignores a double click', async () => {
        let redeliveries = 0;
        let refuse!: (error: Error) => void;
        fetchMock.mockImplementation((url: string) => {
            if (url.endsWith('/redeliver')) {
                redeliveries++;

                return new Promise((_resolve, reject) => {
                    refuse = reject;
                });
            }

            return Promise.resolve(page);
        });

        const w = mountIt(KinetixIntegrationLogs);
        await flushPromises();
        await w.get('tbody tr').trigger('click');
        await flushPromises();

        const redeliver = [...document.body.querySelectorAll('button')].find(
            (b) => b.textContent?.includes('kinetix.logs_redeliver'),
        )!;
        redeliver.click();
        redeliver.click();
        await flushPromises();
        expect(redeliveries).toBe(1);

        refuse(throttled());
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith(THROTTLED);
        expect(unhandled).not.toHaveBeenCalled();

        // The modal stays open with the log, ready for a later retry.
        expect(document.body.textContent).toContain('"order_id": 7');
        w.unmount();
    });
});

describe('spotlight search', () => {
    const groups = [
        {
            label: 'Orders',
            items: [{ id: 1, title: 'Order #7', subtitle: null, url: '/o/7' }],
        },
    ];

    async function openPalette() {
        const w = mountIt(SpotlightBody, { open: true });
        await flushPromises();

        return w;
    }

    const input = () =>
        document.body.querySelector(
            '[role="dialog"] input',
        ) as HTMLInputElement;

    function type(value: string): void {
        input().value = value;
        input().dispatchEvent(new Event('input', { bubbles: true }));
    }

    it('drops the stale results and says why, in an alert, when throttled', async () => {
        const w = await openPalette();
        vi.useFakeTimers();

        fetchMock.mockResolvedValueOnce({ groups });
        type('ord');
        await vi.advanceTimersByTimeAsync(200);
        await flushPromises();
        expect(document.body.textContent).toContain('Order #7');

        fetchMock.mockRejectedValueOnce(throttled());
        type('orde');
        await vi.advanceTimersByTimeAsync(200);
        await flushPromises();

        const alert = document.body.querySelector(
            '[data-test="spotlight-failure"]',
        );
        expect(alert?.getAttribute('role')).toBe('alert');
        expect(alert?.textContent).toContain(THROTTLED);
        expect(document.body.textContent).not.toContain('Order #7');
        expect(unhandled).not.toHaveBeenCalled();

        // The next keystroke clears the message and searches again.
        fetchMock.mockResolvedValueOnce({ groups });
        type('order');
        await w.vm.$nextTick();
        expect(
            document.body.querySelector('[data-test="spotlight-failure"]'),
        ).toBeNull();
        await vi.advanceTimersByTimeAsync(200);
        await flushPromises();
        expect(document.body.textContent).toContain('Order #7');
        w.unmount();
    });

    it('a generic failure says the action failed, never "No results"', async () => {
        const w = await openPalette();
        vi.useFakeTimers();

        fetchMock.mockRejectedValueOnce(
            Object.assign(new Error('Server Error'), { status: 500 }),
        );
        type('ord');
        await vi.advanceTimersByTimeAsync(200);
        await flushPromises();

        expect(
            document.body.querySelector('[data-test="spotlight-failure"]')
                ?.textContent,
        ).toContain('kinetix.action_failed');
        expect(unhandled).not.toHaveBeenCalled();
        w.unmount();
    });
});
