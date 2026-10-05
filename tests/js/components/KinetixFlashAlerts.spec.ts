import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { createI18n } from 'vue-i18n';

type Handler = (event: { detail: Record<string, unknown> }) => void;

const page = reactive<{
    url: string;
    flash: Record<string, unknown>;
    props: Record<string, unknown>;
}>({ url: '/invoices', flash: {}, props: {} });
const handlers: Record<string, Set<Handler>> = {
    flash: new Set(),
    navigate: new Set(),
};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: {
        on: (name: string, handler: Handler) => {
            handlers[name].add(handler);

            return () => handlers[name].delete(handler);
        },
    },
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

const announce = vi.fn();
vi.mock('@/composables/useKinetixAnnounce', () => ({
    useKinetixAnnounce: () => ({ announce }),
}));

import KinetixFlashAlerts from '@/components/KinetixFlashAlerts.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                alert_dismiss: 'Dismiss',
                alert_hide: 'Hide for now',
            },
        },
    },
});

const alert = (id: string, title: string, extra = {}) => ({
    id,
    title,
    description: null,
    color: 'info',
    variant: 'soft',
    icon: null,
    dismissible: true,
    persistent: false,
    ...extra,
});

const fire = (name: string, detail: Record<string, unknown>) =>
    handlers[name].forEach((h) => h({ detail }));

const mountIt = () =>
    mount(KinetixFlashAlerts, {
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

describe('KinetixFlashAlerts', () => {
    beforeEach(() => {
        page.url = '/invoices';
        page.flash = {};
        page.props = {};
        handlers.flash.clear();
        handlers.navigate.clear();
        fetchMock.mockReset();
        announce.mockClear();
        sessionStorage.clear();
        document.body.innerHTML = '';
    });

    it('renders nothing without alerts', () => {
        const w = mountIt();

        expect(w.find('[data-slot="kinetix-flash-alerts"]').exists()).toBe(
            false,
        );
    });

    it('shows the alerts the page arrived with', () => {
        page.flash = { kinetix: { alerts: [alert('a', 'Invoice sent')] } };

        const w = mountIt();

        expect(w.text()).toContain('Invoice sent');
    });

    it('collects alerts from later flashes, announcing the polite ones', async () => {
        const w = mountIt();

        fire('flash', {
            flash: {
                kinetix: {
                    alerts: [
                        alert('a', 'Import finished'),
                        alert('b', 'Payment failed', { color: 'danger' }),
                    ],
                },
            },
        });
        await flushPromises();

        expect(w.text()).toContain('Import finished');
        expect(w.text()).toContain('Payment failed');
        expect(announce).toHaveBeenCalledWith('Import finished');
        // role="alert" already interrupts — not announced twice.
        expect(announce).not.toHaveBeenCalledWith('Payment failed');
    });

    it('keeps them through a poll on the same page, drops them on the next page', async () => {
        page.flash = { kinetix: { alerts: [alert('a', 'Invoice sent')] } };
        const w = mountIt();

        // A partial reload of the same URL: flash comes back empty.
        page.flash = {};
        fire('navigate', { page: { url: '/invoices?page=2' } });
        await flushPromises();
        expect(w.text()).toContain('Invoice sent');

        fire('navigate', { page: { url: '/customers' } });
        await flushPromises();
        expect(w.text()).not.toContain('Invoice sent');
    });

    it('does not stack the same alert twice', async () => {
        page.flash = { kinetix: { alerts: [alert('a', 'Once')] } };
        const w = mountIt();

        fire('flash', { flash: page.flash });
        await flushPromises();

        expect(w.findAll('[data-slot="kinetix-alert"]')).toHaveLength(1);
    });

    it('closes a session alert through its endpoint', async () => {
        page.props = {
            kinetix_alerts: [
                alert('verify-email', 'Verify your email', {
                    persistent: true,
                    dismissUrl: '/_kinetix/flash/verify-email/dismiss',
                }),
            ],
        };
        fetchMock.mockResolvedValueOnce({ dismissed: true });
        const w = mountIt();

        await w.find('button[aria-label="Dismiss"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/flash/verify-email/dismiss',
            { method: 'POST' },
        );
        expect(w.find('[data-slot="kinetix-alert"]').exists()).toBe(false);
    });

    it('a one-shot alert only hides, without asking the server', async () => {
        page.flash = { kinetix: { alerts: [alert('a', 'Saved')] } };
        const w = mountIt();

        await w.find('button[aria-label="Hide for now"]').trigger('click');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(w.find('[data-slot="kinetix-alert"]').exists()).toBe(false);
    });

    it('releases its listeners on unmount', () => {
        const w = mountIt();
        w.unmount();

        expect(handlers.flash.size).toBe(0);
        expect(handlers.navigate.size).toBe(0);
    });

    it('takes the kinetix_flash prop of an older server, clearing the old page first', async () => {
        page.props = {
            kinetix_flash: { alerts: [alert('legacy-a', 'Old page alert')] },
        };
        const w = mountIt();
        expect(w.text()).toContain('Old page alert');

        // A visit to another page whose props carry its own alert.
        fire('navigate', {
            page: {
                url: '/customers',
                props: {
                    kinetix_flash: {
                        alerts: [alert('legacy-b', 'New page alert')],
                    },
                },
            },
        });
        await flushPromises();

        expect(w.text()).not.toContain('Old page alert');
        expect(w.text()).toContain('New page alert');
        w.unmount();
    });
});
