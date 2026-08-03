import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

const kinetixFetch = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => kinetixFetch(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixIntegrationLogs from '@/components/KinetixIntegrationLogs.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key.replace('kinetix.', ''),
    messages: { en: { kinetix: {} } },
});

const WEBHOOK_PAGE = {
    data: [
        {
            id: 1,
            event: 'order.created',
            statusCode: 200,
            success: true,
            attempt: 1,
            createdAt: '2026-07-10T10:00:00Z',
            payload: { order_id: 7 },
            response: '{"ok":true}',
            endpointName: 'Billing hook',
            endpointUrl: 'https://example.com/hook',
        },
    ],
    pagination: { current_page: 1, last_page: 3, total: 41 },
};

const API_PAGE = {
    data: [
        {
            id: 9,
            method: 'POST',
            path: '/api/v1/orders',
            status: 422,
            durationMs: 38,
            tokenName: 'CI bot',
            ip: '10.0.0.9',
            requestBody: { sku: 'A-1' },
            responseBody: '{"message":"invalid"}',
            createdAt: '2026-07-10T10:05:00Z',
        },
    ],
    pagination: { current_page: 1, last_page: 1, total: 1 },
};

const mountLogs = (props: Record<string, unknown> = {}) =>
    mount(KinetixIntegrationLogs, {
        props,
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

describe('KinetixIntegrationLogs', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        kinetixFetch.mockReset();
        kinetixFetch.mockImplementation((url: string) =>
            Promise.resolve(
                url.includes('/webhooks/logs') ? WEBHOOK_PAGE : API_PAGE,
            ),
        );
    });

    it('lists webhook deliveries with endpoint, status and pagination', async () => {
        const w = mountLogs();
        await flushPromises();

        expect(kinetixFetch).toHaveBeenCalledWith(
            expect.stringContaining('/_kinetix/webhooks/logs'),
        );
        expect(w.text()).toContain('order.created');
        expect(w.text()).toContain('Billing hook');
        expect(w.text()).toContain('200');
        expect(w.text()).toContain('1 / 3');
        w.unmount();
    });

    it('switches to the API tab and lists requests', async () => {
        const w = mountLogs();
        await flushPromises();

        const apiTab = w
            .findAll('button')
            .find((b) => b.text() === 'logs_api_tab')!;
        await apiTab.trigger('click');
        await flushPromises();

        expect(kinetixFetch).toHaveBeenCalledWith(
            expect.stringContaining('/_kinetix/api-logs'),
        );
        expect(w.text()).toContain('/api/v1/orders');
        expect(w.text()).toContain('CI bot');
        expect(w.text()).toContain('38 ms');
        w.unmount();
    });

    it('opens the detail modal with the pretty-printed payload', async () => {
        const w = mountLogs();
        await flushPromises();

        await w.get('tbody tr').trigger('click');
        await flushPromises();

        const body = document.body.textContent ?? '';
        expect(body).toContain('"order_id": 7');
        expect(body).toContain('https://example.com/hook');
        w.unmount();
    });

    it('drops a pending search fetch when unmounted mid-debounce', async () => {
        vi.useFakeTimers();
        const w = mountLogs();
        await vi.runAllTimersAsync();

        const search = w.find('input[type="search"]');
        (search.element as HTMLInputElement).value = 'order';
        await search.trigger('input');

        w.unmount();
        kinetixFetch.mockClear();
        await vi.runAllTimersAsync();

        expect(kinetixFetch).not.toHaveBeenCalled();
        vi.useRealTimers();
    });

    it('respects the only prop (single feed, no tab switcher)', async () => {
        const w = mountLogs({ only: 'api' });
        await flushPromises();

        expect(kinetixFetch).toHaveBeenCalledWith(
            expect.stringContaining('/_kinetix/api-logs'),
        );
        expect(
            w.findAll('button').filter((b) => b.text() === 'logs_webhooks_tab'),
        ).toHaveLength(0);
        w.unmount();
    });
});
