import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const listenMock = vi.fn();
const echoState = { configured: false };
vi.mock('@laravel/echo-vue', () => ({
    echoIsConfigured: () => echoState.configured,
    echo: () => ({
        private: () => ({ listen: listenMock }),
        leave: vi.fn(),
    }),
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixChannelCard from '@/components/Channels/KinetixChannelCard.vue';
import KinetixChannelLinker from '@/components/Channels/KinetixChannelLinker.vue';
import KinetixChannelManager from '@/components/KinetixChannelManager.vue';
import type { KinetixChannel } from '@/types/kinetix';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                channel_qr_refresh_in: 'New code in {seconds} s',
                channel_linked_description: '{handle} is ready.',
            },
        },
    },
});

const providers = [
    { key: 'whatsapp', label: 'WhatsApp', methods: ['qr', 'pairing_code'] },
    { key: 'telegram', label: 'Telegram', methods: ['token'] },
];

const line = (overrides: Partial<KinetixChannel> = {}): KinetixChannel => ({
    id: 1,
    provider: 'whatsapp',
    name: 'Sales',
    status: 'connecting',
    handle: null,
    lastError: null,
    connectedAt: null,
    lastEventAt: null,
    createdAt: null,
    ...overrides,
});

/** Answers each endpoint the way the server would, by URL and verb. */
function serve(routes: Record<string, (body?: unknown) => unknown>): void {
    fetchMock.mockImplementation(
        async (
            url: string,
            options: { method?: string; body?: unknown } = {},
        ) => {
            const key = `${options.method ?? 'GET'} ${url}`;
            const match = Object.keys(routes).find((route) =>
                key.startsWith(route),
            );

            if (!match) {
                throw new Error(`Unexpected ${key}`);
            }

            return routes[match](options.body);
        },
    );
}

const index = (channels: KinetixChannel[] = [], extra = {}) => ({
    channels,
    providers,
    limit: null,
    broadcast: null,
    ...extra,
});

beforeEach(() => {
    fetchMock.mockReset();
    listenMock.mockReset();
    echoState.configured = false;
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('KinetixChannelManager', () => {
    it('offers the providers when there are no lines, and asks for a token for a bot', async () => {
        serve({ 'GET /_kinetix/channels': () => index() });

        const w = mount(KinetixChannelManager, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(w.text()).toContain('kinetix.channels_empty_title');
        await w.get('[data-test="channel-add"]').trigger('click');
        await flushPromises();

        expect(document.querySelector('#kinetix-channel-token')).toBeNull();
        (
            document.querySelector(
                '[data-test="channel-provider-telegram"]',
            ) as HTMLButtonElement
        ).click();
        await flushPromises();

        expect(document.querySelector('#kinetix-channel-token')).not.toBeNull();
        w.unmount();
    });

    it('names only the providers turned on', async () => {
        serve({
            'GET /_kinetix/channels': () => ({
                ...index(),
                providers: [providers[0]],
            }),
        });

        const w = mount(KinetixChannelManager, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(w.text()).toContain('kinetix.channels_description_whatsapp');
        expect(w.text()).toContain(
            'kinetix.channels_empty_description_whatsapp',
        );

        await w.get('[data-test="channel-add"]').trigger('click');
        await flushPromises();
        expect(document.body.textContent).toContain(
            'kinetix.channel_add_description_whatsapp',
        );
        expect(
            document.querySelector('[data-test="channel-provider-whatsapp"]'),
        ).toBeNull();
        w.unmount();
    });

    it('opens a new WhatsApp line straight on its first QR', async () => {
        serve({
            'GET /_kinetix/channels/1/status': () => ({ channel: line() }),
            'GET /_kinetix/channels': () => index(),
            'POST /_kinetix/channels': () => ({
                channel: line(),
                link: {
                    status: 'connecting',
                    qr: 'data:image/png;base64,QR1',
                    pairingCode: null,
                    expiresIn: 30,
                },
            }),
        });

        const w = mount(KinetixChannelManager, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();
        await w.get('[data-test="channel-add"]').trigger('click');
        await flushPromises();

        const name = document.querySelector(
            '#kinetix-channel-name',
        ) as HTMLInputElement;
        name.value = 'Sales';
        name.dispatchEvent(new Event('input'));
        (
            document.querySelector('#kinetix-channel-create') as HTMLFormElement
        ).dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/channels', {
            method: 'POST',
            body: { provider: 'whatsapp', name: 'Sales' },
        });
        const qr = document.querySelector(
            '[data-test="channel-qr"]',
        ) as HTMLImageElement;
        expect(qr.getAttribute('src')).toBe('data:image/png;base64,QR1');
        expect(document.body.textContent).toContain('New code in 30 s');
        w.unmount();
    });

    it('shows a rejected token under the token field', async () => {
        serve({
            'GET /_kinetix/channels': () => index(),
            'POST /_kinetix/channels': () => {
                throw Object.assign(new Error('Invalid'), {
                    errors: { token: ['Telegram rejected this bot token.'] },
                });
            },
        });

        const w = mount(KinetixChannelManager, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();
        await w.get('[data-test="channel-add"]').trigger('click');
        await flushPromises();
        (
            document.querySelector(
                '[data-test="channel-provider-telegram"]',
            ) as HTMLButtonElement
        ).click();
        await flushPromises();
        (
            document.querySelector('#kinetix-channel-create') as HTMLFormElement
        ).dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(document.body.textContent).toContain(
            'Telegram rejected this bot token.',
        );
        w.unmount();
    });

    it('follows broadcast updates when Echo is configured', async () => {
        echoState.configured = true;
        serve({
            'GET /_kinetix/channels': () =>
                index([line()], { broadcast: 'kinetix.channels.1' }),
        });

        const w = mount(KinetixChannelManager, {
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(listenMock).toHaveBeenCalledWith(
            '.channel.updated',
            expect.any(Function),
        );
        listenMock.mock.calls[0][1]({
            id: 1,
            status: 'connected',
            handle: '+5215512345678',
            qr: null,
        });
        await flushPromises();

        expect(w.text()).toContain('+5215512345678');
        w.unmount();
    });
});

describe('KinetixChannelLinker', () => {
    it('refreshes the QR when it expires and celebrates once the line is linked', async () => {
        vi.useFakeTimers();
        let polls = 0;
        serve({
            'GET /_kinetix/channels/1/link': () => ({
                channel: line(),
                link: {
                    status: 'connecting',
                    qr: 'data:image/png;base64,QR2',
                    pairingCode: null,
                    expiresIn: 2,
                },
            }),
            'GET /_kinetix/channels/1/status': () => {
                polls += 1;

                return {
                    channel: line(
                        polls >= 2
                            ? { status: 'connected', handle: '+5215512345678' }
                            : {},
                    ),
                };
            },
        });

        const w = mount(KinetixChannelLinker, {
            props: { open: true, channel: line() },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        // The 2-second code runs out: a new one is fetched on its own.
        await vi.advanceTimersByTimeAsync(2100);
        expect(
            fetchMock.mock.calls.filter(([url]) => url.includes('/link')),
        ).toHaveLength(2);

        await vi.advanceTimersByTimeAsync(6100);
        await flushPromises();

        expect(
            document.querySelector('[data-test="channel-linked"]')?.textContent,
        ).toContain('+5215512345678 is ready.');
        expect(w.emitted('linked')?.[0]?.[0]).toMatchObject({
            status: 'connected',
        });
        w.unmount();
    });

    it('links by pairing code on a phone that cannot scan the screen', async () => {
        serve({
            'GET /_kinetix/channels/1/link?phone': () => ({
                channel: line(),
                link: {
                    status: 'connecting',
                    qr: null,
                    pairingCode: 'ABCD1234',
                    expiresIn: 30,
                },
            }),
            'GET /_kinetix/channels/1/link': () => ({
                channel: line(),
                link: {
                    status: 'connecting',
                    qr: 'data:image/png;base64,QR',
                    pairingCode: null,
                    expiresIn: 30,
                },
            }),
            'GET /_kinetix/channels/1/status': () => ({ channel: line() }),
        });

        const w = mount(KinetixChannelLinker, {
            props: { open: true, channel: line() },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();

        (
            document.querySelector(
                '[data-test="channel-link-mode"]',
            ) as HTMLButtonElement
        ).click();
        await flushPromises();

        const phone = document.querySelector(
            '#kinetix-channel-phone',
        ) as HTMLInputElement;
        phone.value = '+52 55 1234 5678';
        phone.dispatchEvent(new Event('input'));
        phone.form?.dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/channels/1/link?phone=%2B52%2055%201234%205678',
        );
        expect(
            document.querySelector('[data-test="channel-pairing-code"]')
                ?.textContent,
        ).toBe('ABCD-1234');
        w.unmount();
    });
});

describe('KinetixChannelLinker phone', () => {
    it('asks for the number with a country picker and sends it whole', async () => {
        serve({
            'GET /_kinetix/channels/1/link?phone=': () => ({
                channel: line(),
                link: {
                    status: 'connecting',
                    qr: null,
                    pairingCode: 'ABCD1234',
                    expiresIn: 60,
                },
            }),
            'GET /_kinetix/channels/1/link': () => ({
                channel: line(),
                link: {
                    status: 'connecting',
                    qr: 'data:image/png;base64,QR',
                    pairingCode: null,
                    expiresIn: 30,
                },
            }),
            'GET /_kinetix/channels/1/status': () => ({ channel: line() }),
        });

        const w = mount(KinetixChannelLinker, {
            props: {
                open: true,
                channel: line(),
                phoneField: {
                    defaultCountry: 'MX',
                    countries: [
                        { code: 'MX', name: 'Mexico', dial: '52' },
                        { code: 'US', name: 'United States', dial: '1' },
                    ],
                },
            },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();

        (
            document.querySelector(
                '[data-test="channel-link-mode"]',
            ) as HTMLButtonElement
        ).click();
        await flushPromises();

        const phone = document.querySelector(
            '#kinetix-channel-phone',
        ) as HTMLInputElement;
        expect(phone.getAttribute('aria-describedby')).toBe(
            'kinetix-channel-phone-help',
        );
        phone.value = '55 1234 5678';
        phone.dispatchEvent(new Event('input'));
        await flushPromises();
        phone.form?.dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/channels/1/link?phone=%2B525512345678',
        );
        w.unmount();
    });
});

describe('KinetixChannelCard', () => {
    it('offers the next step for where the line stands', () => {
        const scan = mount(KinetixChannelCard, {
            props: {
                channel: line({ status: 'disconnected' }),
                scannable: true,
            },
            global: { plugins: [i18n] },
        });
        expect(scan.find('[data-test="channel-link"]').exists()).toBe(true);

        const bot = mount(KinetixChannelCard, {
            props: {
                channel: line({
                    provider: 'telegram',
                    status: 'connected',
                    handle: '@acme_bot',
                }),
                scannable: false,
            },
            global: { plugins: [i18n] },
        });
        expect(bot.find('a').attributes('href')).toBe('https://t.me/acme_bot');

        const failed = mount(KinetixChannelCard, {
            props: {
                channel: line({
                    status: 'failed',
                    lastError: 'Telegram rejected this bot token.',
                }),
                scannable: false,
            },
            global: { plugins: [i18n] },
        });
        expect(failed.text()).toContain('Telegram rejected this bot token.');
        expect(failed.text()).toContain('kinetix.channel_reconnect');
    });
});
