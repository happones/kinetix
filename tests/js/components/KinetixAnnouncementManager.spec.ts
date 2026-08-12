import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixAnnouncementManager from '@/components/KinetixAnnouncementManager.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_locale: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                announcements_title: 'What’s new',
                announcements_new_entry: 'New announcement',
                announcements_status_draft: 'Draft',
                announcements_status_scheduled: 'Scheduled',
                announcements_status_published: 'Published',
                announcements_global: 'Platform-wide',
                announcements_global_readonly: 'Platform-wide',
                edit: 'Edit',
                delete: 'Delete',
                save: 'Save',
                cancel: 'Cancel',
            },
        },
    },
});

const entry = (over: Record<string, unknown> = {}) => ({
    id: 1,
    title: 'Shipped',
    body: 'Body',
    level: 'feature',
    publishedAt: '2026-06-26T10:00:00Z',
    isGlobal: false,
    status: 'published',
    ...over,
});

// The edit form lives in a KinetixModal, which teleports to <body> — reach it
// through the document rather than the wrapper.
const mountIt = () =>
    mount(KinetixAnnouncementManager, {
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

const field = (selector: string): HTMLInputElement =>
    document.querySelector(selector) as HTMLInputElement;

const setField = async (selector: string, value: string): Promise<void> => {
    const input = field(selector);
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await flushPromises();
};

const submitForm = async (): Promise<void> => {
    (
        document.querySelector('#kinetix-announcement-form') as HTMLFormElement
    ).dispatchEvent(new Event('submit'));
    await flushPromises();
};

const byLabel = (w: ReturnType<typeof mountIt>, label: string) =>
    w.findAll('button').find((b) => b.attributes('aria-label') === label);

describe('KinetixAnnouncementManager', () => {
    beforeEach(() => fetchMock.mockReset());
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('lists drafts and scheduled entries, which the reader feed hides', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [
                entry({
                    id: 1,
                    title: 'Waiting',
                    status: 'draft',
                    publishedAt: null,
                }),
                entry({ id: 2, title: 'Next week', status: 'scheduled' }),
            ],
            teamScoped: false,
        });
        const w = mountIt();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/announcements/manage',
        );
        expect(w.text()).toContain('Waiting');
        expect(w.text()).toContain('Draft');
        expect(w.text()).toContain('Scheduled');
    });

    it('creates an entry with a POST and reloads the list', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [],
            teamScoped: false,
        });
        const w = mountIt();
        await flushPromises();

        await w.find('button').trigger('click');
        await flushPromises();
        await setField('#kinetix-announcement-title', 'Dark mode');
        await setField('#kinetix-announcement-body', 'Toggle it.');

        fetchMock.mockResolvedValueOnce({ announcement: entry() });
        fetchMock.mockResolvedValueOnce({
            announcements: [entry()],
            teamScoped: false,
        });
        await submitForm();

        const [url, options] = fetchMock.mock.calls[1];
        expect(url).toBe('/_kinetix/announcements');
        expect(options.method).toBe('POST');
        expect(options.body).toMatchObject({
            title: 'Dark mode',
            body: 'Toggle it.',
            level: 'info',
        });
    });

    it('clearing the publish date keeps the entry a draft', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [],
            teamScoped: false,
        });
        const w = mountIt();
        await flushPromises();

        await w.find('button').trigger('click');
        await flushPromises();
        await setField('#kinetix-announcement-title', 'Later');
        await setField('#kinetix-announcement-body', 'Body');
        await setField('#kinetix-announcement-published', '');

        fetchMock.mockResolvedValueOnce({ announcement: entry() });
        fetchMock.mockResolvedValueOnce({
            announcements: [],
            teamScoped: false,
        });
        await submitForm();

        expect(fetchMock.mock.calls[1][1].body.published_at).toBeNull();
    });

    it('sends the expiry alongside the publish date', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [],
            teamScoped: false,
        });
        const w = mountIt();
        await flushPromises();

        await w.find('button').trigger('click');
        await flushPromises();
        await setField('#kinetix-announcement-title', 'Maintenance');
        await setField('#kinetix-announcement-body', 'Sunday 02:00 UTC.');
        await setField('#kinetix-announcement-expires', '2026-09-20T04:00');

        fetchMock.mockResolvedValueOnce({ announcement: entry() });
        fetchMock.mockResolvedValueOnce({
            announcements: [],
            teamScoped: false,
        });
        await submitForm();

        expect(fetchMock.mock.calls[1][1].body.expires_at).toContain(
            '2026-09-20',
        );
    });

    it('a platform-wide entry is read-only inside a team', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [entry({ isGlobal: true })],
            teamScoped: true,
        });
        const w = mountIt();
        await flushPromises();

        expect(byLabel(w, 'Edit')?.attributes('disabled')).toBeDefined();
        expect(byLabel(w, 'Delete')?.attributes('disabled')).toBeDefined();
    });

    it('keeps the form open and shows the reason when the server rejects it', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [],
            teamScoped: false,
        });
        const w = mountIt();
        await flushPromises();

        await w.find('button').trigger('click');
        await flushPromises();
        await setField('#kinetix-announcement-title', 'Bad');
        await setField('#kinetix-announcement-body', 'Body');

        fetchMock.mockRejectedValueOnce(
            new Error('The title has already been taken.'),
        );
        await submitForm();

        expect(document.body.textContent).toContain(
            'The title has already been taken.',
        );
        expect(field('#kinetix-announcement-title')).not.toBeNull();
    });
});
