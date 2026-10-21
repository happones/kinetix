import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@laravel/echo-vue', () => ({
    echoIsConfigured: () => false,
    echo: () => ({ private: () => ({ listen: vi.fn() }), leave: vi.fn() }),
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
    isKinetixAbort: () => false,
}));

import KinetixInboxBubble from '@/components/Inbox/KinetixInboxBubble.vue';
import KinetixInboxComposer from '@/components/Inbox/KinetixInboxComposer.vue';
import KinetixInbox from '@/components/KinetixInbox.vue';
import type {
    KinetixConversation,
    KinetixConversationMessage,
} from '@/types/kinetix';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const message = (
    overrides: Partial<KinetixConversationMessage> = {},
): KinetixConversationMessage => ({
    id: 10,
    conversationId: 1,
    direction: 'in',
    type: 'text',
    body: 'Hola, ¿tienen envío?',
    status: 'received',
    error: null,
    hasMedia: false,
    mediaReady: false,
    mediaMime: null,
    mediaName: null,
    mediaSize: null,
    replyTo: null,
    authorName: null,
    sentAt: '2026-10-17T10:00:00Z',
    ...overrides,
});

const conversation = (
    overrides: Partial<KinetixConversation> = {},
): KinetixConversation => ({
    id: 1,
    channel: { id: 1, provider: 'whatsapp', name: 'Sales' },
    contactKey: '5215599999999',
    contactName: 'Ada',
    isGroup: false,
    assignee: null,
    lastMessage: message(),
    lastMessageAt: '2026-10-17T10:00:00Z',
    unread: 1,
    archived: false,
    ...overrides,
});

const index = {
    conversations: [conversation()],
    hasMore: false,
    channels: [
        {
            id: 1,
            provider: 'whatsapp',
            name: 'Sales',
            status: 'connected',
            broadcast: null,
        },
    ],
    me: { id: 2, seesAll: true },
    poll: { list: 0, thread: 0 },
    composer: { maxUploadKb: 16384, accept: '' },
};

/** Answers each endpoint the way the server would, by verb and URL. */
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

async function openInbox() {
    const w = mount(KinetixInbox, {
        attachTo: document.body,
        global: { plugins: [i18n] },
    });
    await flushPromises();
    await w.get('[data-test="inbox-conversation-1"]').trigger('click');
    await flushPromises();

    return w;
}

beforeEach(() => {
    fetchMock.mockReset();
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixInbox', () => {
    it('opens a conversation, reads it and clears its unread count', async () => {
        serve({
            'GET /_kinetix/inbox?': () => index,
            'GET /_kinetix/inbox/1/messages': () => ({
                messages: [message()],
                hasMore: false,
            }),
            'POST /_kinetix/inbox/1/read': () => ({ status: 'ok' }),
        });

        const w = await openInbox();

        expect(w.text()).toContain('Hola, ¿tienen envío?');
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/inbox/1/read', {
            method: 'POST',
            body: { message_id: 10 },
        });
        expect(w.get('[data-test="inbox-conversation-1"]').text()).not.toMatch(
            /\b1$/,
        );
        w.unmount();
    });

    it('shows a sent message at once, then the server copy', async () => {
        let resolveSend: (value: unknown) => void = () => {};
        serve({
            'GET /_kinetix/inbox?': () => index,
            'GET /_kinetix/inbox/1/messages': () => ({
                messages: [message()],
                hasMore: false,
            }),
            'POST /_kinetix/inbox/1/read': () => ({ status: 'ok' }),
            'POST /_kinetix/inbox/1/messages': () =>
                new Promise((resolve) => {
                    resolveSend = resolve;
                }),
        });

        const w = await openInbox();
        await w.get('[data-test="inbox-input"]').setValue('Sí, a todo el país');
        await w.get('[data-test="inbox-send"]').trigger('click');
        await flushPromises();

        // On its way: shown with the clock.
        expect(w.text()).toContain('Sí, a todo el país');
        expect(w.findAll('[data-test="inbox-message-out"]')).toHaveLength(1);
        expect(w.html()).toContain('kinetix.inbox_status_pending');

        resolveSend({
            message: message({
                id: 11,
                direction: 'out',
                body: 'Sí, a todo el país',
                status: 'sent',
            }),
        });
        await flushPromises();

        expect(w.findAll('[data-test="inbox-message-out"]')).toHaveLength(1);
        expect(w.html()).toContain('kinetix.inbox_status_sent');
        w.unmount();
    });

    it('answers a specific message, and keeps a refused one to retry', async () => {
        let sent: Record<string, unknown> | null = null;
        serve({
            'GET /_kinetix/inbox?': () => index,
            'GET /_kinetix/inbox/1/messages': () => ({
                messages: [message()],
                hasMore: false,
            }),
            'POST /_kinetix/inbox/1/read': () => ({ status: 'ok' }),
            'POST /_kinetix/inbox/1/messages': (body) => {
                sent = body as Record<string, unknown>;

                return {
                    message: message({
                        id: 12,
                        direction: 'out',
                        body: 'Claro',
                        status: 'failed',
                        error: 'The provider is down.',
                        replyTo: {
                            id: 10,
                            body: 'Hola, ¿tienen envío?',
                            type: 'text',
                            direction: 'in',
                        },
                    }),
                };
            },
            'POST /_kinetix/inbox/messages/12/retry': () => ({
                message: message({
                    id: 12,
                    direction: 'out',
                    body: 'Claro',
                    status: 'sent',
                }),
            }),
        });

        const w = await openInbox();
        await w.get('[data-test="inbox-reply"]').trigger('click');
        expect(w.find('[data-test="inbox-replying"]').exists()).toBe(true);

        await w.get('[data-test="inbox-input"]').setValue('Claro');
        await w.get('[data-test="inbox-input"]').trigger('keydown', {
            key: 'Enter',
        });
        await flushPromises();

        expect((sent as Record<string, unknown> | null)?.reply_to).toBe(10);
        expect(w.find('[data-test="inbox-replying"]').exists()).toBe(false);
        expect(w.text()).toContain('The provider is down.');

        await w.get('[data-test="inbox-retry"]').trigger('click');
        await flushPromises();

        expect(w.text()).not.toContain('The provider is down.');
        w.unmount();
    });

    it('filters the list on the server', async () => {
        serve({ 'GET /_kinetix/inbox?': () => index });

        const w = mount(KinetixInbox, { global: { plugins: [i18n] } });
        await flushPromises();
        await w.get('[data-test="inbox-filter-unread"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.at(-1)?.[0]).toContain('filter=unread');
        w.unmount();
    });
});

describe('KinetixInboxComposer', () => {
    it('sends on Enter, breaks the line on Shift+Enter, and writes notes without files', async () => {
        const w = mount(KinetixInboxComposer, {
            global: { plugins: [i18n] },
        });
        const input = w.get('[data-test="inbox-input"]');

        await input.setValue('Hola');
        await input.trigger('keydown', { key: 'Enter', shiftKey: true });
        expect(w.emitted('send')).toBeUndefined();

        await w.get('[data-test="inbox-mode-note"]').trigger('click');
        expect(w.find('[data-test="inbox-attach"]').exists()).toBe(false);

        await input.trigger('keydown', { key: 'Enter' });
        expect(w.emitted('send')?.[0]?.[0]).toMatchObject({
            body: 'Hola',
            note: true,
            file: null,
        });
    });

    it('refuses a file over the size limit before uploading it', async () => {
        const w = mount(KinetixInboxComposer, {
            props: { maxUploadKb: 1 },
            global: { plugins: [i18n] },
        });
        const input = w.get('[data-test="inbox-file"]')
            .element as HTMLInputElement;
        const big = new File(['x'.repeat(2048)], 'big.pdf', {
            type: 'application/pdf',
        });

        Object.defineProperty(input, 'files', { value: [big] });
        input.dispatchEvent(new Event('change'));
        await flushPromises();

        expect(w.text()).toContain('kinetix.inbox_file_too_large');
        expect(w.find('[data-test="inbox-attachment"]').exists()).toBe(false);
    });
});

describe('KinetixInboxComposer quick replies', () => {
    const replies = [
        {
            id: 1,
            shortcut: 'precios',
            body: 'Hola {{contact.name}}, soy {{agent.name}}. Lista: acme.mx',
        },
        { id: 2, shortcut: 'horario', body: 'Abrimos de 9 a 18' },
    ];

    const mountComposer = () =>
        mount(KinetixInboxComposer, {
            props: {
                replies,
                replyVariables: {
                    'contact.name': 'Ada',
                    'agent.name': 'Grace',
                },
                canManageReplies: true,
            },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

    it('suggests replies for "/shortcut" and inserts one where it was typed, variables filled in', async () => {
        const w = mountComposer();
        const input = w.get('[data-test="inbox-input"]');
        const el = input.element as HTMLTextAreaElement;

        el.value = 'Claro. /pre';
        el.setSelectionRange(11, 11);
        await input.trigger('input');

        const options = w.findAll('[role="option"]');
        expect(options).toHaveLength(1);
        expect(input.attributes('aria-expanded')).toBe('true');

        await input.trigger('keydown', { key: 'Enter' });

        expect(el.value).toBe('Claro. Hola Ada, soy Grace. Lista: acme.mx');
        expect(w.emitted('send')).toBeUndefined();
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });

    it('moves through suggestions with the arrows and closes them with Escape', async () => {
        const w = mountComposer();
        const input = w.get('[data-test="inbox-input"]');
        const el = input.element as HTMLTextAreaElement;

        el.value = '/';
        el.setSelectionRange(1, 1);
        await input.trigger('input');
        expect(w.findAll('[role="option"]')).toHaveLength(2);

        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(
            w.findAll('[role="option"]')[1].attributes('aria-selected'),
        ).toBe('true');

        await input.trigger('keydown', { key: 'Escape' });
        expect(w.find('[role="listbox"]').exists()).toBe(false);

        // A "/" inside a word (a URL) is not a shortcut.
        el.value = 'https://acme.mx/pre';
        el.setSelectionRange(19, 19);
        await input.trigger('input');
        expect(w.find('[role="listbox"]').exists()).toBe(false);
        w.unmount();
    });

    it('offers the picker, with a way to manage the replies', async () => {
        const w = mountComposer();

        await w.get('[data-test="inbox-quick-replies"]').trigger('click');
        await flushPromises();

        (
            document.querySelector(
                '[data-test="inbox-quick-reply-horario"]',
            ) as HTMLButtonElement
        ).click();
        await flushPromises();
        expect(
            (w.get('[data-test="inbox-input"]').element as HTMLTextAreaElement)
                .value,
        ).toBe('Abrimos de 9 a 18');

        await w.get('[data-test="inbox-quick-replies"]').trigger('click');
        await flushPromises();
        (
            document.querySelector(
                '[data-test="inbox-manage-replies"]',
            ) as HTMLButtonElement
        ).click();
        expect(w.emitted('manage-replies')).toHaveLength(1);
        w.unmount();
    });
});

describe('KinetixInboxBubble', () => {
    const bubble = (overrides: Partial<KinetixConversationMessage>) =>
        mount(KinetixInboxBubble, {
            props: {
                message: message(overrides),
                mediaUrl: '/media/1',
                downloadUrl: '/media/1?download=1',
            },
            global: { plugins: [i18n] },
        });

    it('shows how far a sent message got', () => {
        expect(bubble({ direction: 'out', status: 'read' }).html()).toContain(
            'kinetix.inbox_status_read',
        );
        expect(bubble({ direction: 'in' }).html()).not.toContain(
            'kinetix.inbox_status_',
        );
    });

    it('offers a document to download, and opens an image in the lightbox when it is mounted', () => {
        const doc = bubble({
            type: 'document',
            body: null,
            hasMedia: true,
            mediaReady: true,
            mediaName: 'quote.pdf',
            mediaSize: 2048,
        });
        expect(doc.get('a').attributes('href')).toBe('/media/1?download=1');
        expect(doc.text()).toContain('quote.pdf');
        expect(doc.text()).toContain('2.0 KB');

        const listener = vi.fn((event: Event) => event.preventDefault());
        window.addEventListener('kinetix:preview', listener);
        const image = bubble({
            type: 'image',
            body: null,
            hasMedia: true,
            mediaReady: true,
        });
        const click = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        });
        image.get('a').element.dispatchEvent(click);
        window.removeEventListener('kinetix:preview', listener);

        expect(listener).toHaveBeenCalled();
        expect(click.defaultPrevented).toBe(true);
    });

    it('says when the line answered from its phone or through its bot', () => {
        const phone = bubble({
            direction: 'out',
            status: 'sent',
            fromPhone: true,
        });
        expect(phone.get('[data-test="inbox-from-phone"]').text()).toBe(
            'kinetix.inbox_sent_from_phone',
        );

        const bot = bubble({
            direction: 'out',
            status: 'sent',
            bot: true,
            authorName: 'Asistente',
        });
        expect(bot.text()).toContain('Asistente');
        expect(bot.find('[data-test="inbox-from-phone"]').exists()).toBe(false);
    });

    it('sets an internal note apart and offers no reply to it', () => {
        const note = bubble({
            direction: 'note',
            status: 'sent',
            authorName: 'Grace',
        });

        expect(note.text()).toContain('kinetix.inbox_note');
        expect(note.text()).toContain('Grace');
        expect(note.find('[data-test="inbox-reply"]').exists()).toBe(false);
    });
});
