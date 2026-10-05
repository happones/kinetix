import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { createI18n } from 'vue-i18n';

// Reactive like Inertia's page, so a new response's payload can be pushed in.
const pageProps = reactive<Record<string, unknown>>({});
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixAnnouncementBanner from '@/components/KinetixAnnouncementBanner.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                announcements_title: 'What’s new',
                announcements_new: 'New',
                announcements_level_feature: 'Feature',
                announcements_previous: 'Previous announcement',
                announcements_next: 'Next announcement',
                announcements_dismiss: 'Dismiss',
                announcements_pause: 'Pause rotation',
                announcements_play: 'Resume rotation',
                announcements_slide_position: '{current} of {total}',
                announcements_go_to: 'Show: {title}',
                alert_hide: 'Hide for now',
                alert_dont_show_again: 'Don’t show again',
                alert_dismissed: 'Message dismissed.',
                alert_hidden: 'Message hidden for now.',
            },
        },
    },
});

const announcement = (id: number, title: string, level = 'feature') => ({
    id,
    title,
    body: `${title} body`,
    level,
    publishedAt: '2026-06-26T10:00:00Z',
    isNew: true,
});

const mountIt = (props: Record<string, unknown> = {}) =>
    mount(KinetixAnnouncementBanner, {
        props: { autoplay: 0, ...props },
        global: { plugins: [i18n] },
    });

const buttonWithLabel = (wrapper: ReturnType<typeof mountIt>, label: string) =>
    wrapper.findAll('button').find((b) => b.attributes('aria-label') === label);

describe('KinetixAnnouncementBanner', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        pageProps.kinetix_announcements = undefined;
        sessionStorage.clear();
        localStorage.clear();
        document.documentElement.classList.remove('kx-reduce-motion');
    });

    it('renders from the page payload without a request of its own', async () => {
        pageProps.kinetix_announcements = {
            unread: 1,
            bannerLimit: 3,
            banner: [announcement(9, 'From the payload')],
        };

        const w = mountIt({ limit: 3 });
        await flushPromises();

        expect(w.text()).toContain('From the payload');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('follows the configured banner_limit when no limit is passed', async () => {
        const entries = Array.from({ length: 7 }, (_, i) =>
            announcement(i + 1, `Entry ${i + 1}`),
        );
        pageProps.kinetix_announcements = {
            unread: 7,
            bannerLimit: 7,
            banner: entries,
        };

        const w = mountIt();
        await flushPromises();

        // A hardcoded default limit would disagree with the config, fetch, and
        // cut the rotation to its own size.
        expect(fetchMock).not.toHaveBeenCalled();
        expect(
            w
                .findAll('button')
                .filter((b) => b.attributes('aria-label')?.startsWith('Show:')),
        ).toHaveLength(7);
    });

    it('asks the server once it is narrowed past what the payload holds', async () => {
        pageProps.kinetix_announcements = {
            unread: 1,
            bannerLimit: 3,
            banner: [announcement(9, 'From the payload')],
        };
        fetchMock.mockResolvedValueOnce({ announcements: [] });

        mountIt({ levels: ['fix'] });
        await flushPromises();

        // No limit of its own: the server applies `banner_limit`.
        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/announcements/banner?levels=fix',
        );
    });

    it('renders nothing while the banner feed is empty', async () => {
        fetchMock.mockResolvedValueOnce({ announcements: [] });
        const w = mountIt();
        await flushPromises();

        expect(w.find('[data-slot="alert"]').exists()).toBe(false);
    });

    it('shows one entry at a time and rotates on the arrows', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [
                announcement(1, 'First'),
                announcement(2, 'Second'),
            ],
        });
        const w = mountIt();
        await flushPromises();

        expect(w.text()).toContain('First');
        expect(w.text()).not.toContain('Second');

        await buttonWithLabel(w, 'Next announcement')?.trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Second');
        expect(w.text()).not.toContain('First');
    });

    it('hides the controls for a single announcement', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [announcement(1, 'Only one')],
        });
        const w = mountIt();
        await flushPromises();

        expect(buttonWithLabel(w, 'Next announcement')).toBeUndefined();
        expect(buttonWithLabel(w, 'Dismiss')).toBeTruthy();
    });

    it('dismisses the entry it is showing and drops it from the rotation', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [
                announcement(1, 'First'),
                announcement(2, 'Second'),
            ],
        });
        const w = mountIt();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ status: 'success' });
        await buttonWithLabel(w, 'Dismiss')?.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/announcements/1/dismiss',
            { method: 'POST' },
        );
        expect(w.text()).toContain('Second');
        expect(w.text()).not.toContain('First');
    });

    it('asks the server only for the levels and limit it was given', async () => {
        fetchMock.mockResolvedValueOnce({ announcements: [] });
        mountIt({ limit: 2, levels: ['feature', 'fix'] });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/announcements/banner?limit=2&levels=feature%2Cfix',
        );
    });

    it('stays in the page flow by default', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [announcement(1, 'Inline')],
        });
        const w = mountIt();
        await flushPromises();

        expect(w.html()).not.toContain('fixed');
        // Only a pinned banner covers anything, so only it claims the variable.
        expect(
            document.documentElement.style.getPropertyValue(
                '--kinetix-announcement-banner-height',
            ),
        ).toBe('');
    });

    it('pins to the top and publishes its height for the layout to reserve', async () => {
        fetchMock.mockResolvedValueOnce({
            announcements: [announcement(1, 'Pinned')],
        });
        const w = mountIt({ position: 'fixed-top' });
        await flushPromises();

        const wrapper = w.find('div.fixed');
        expect(wrapper.exists()).toBe(true);
        expect(wrapper.classes()).toContain('top-0');
        expect(
            document.documentElement.style.getPropertyValue(
                '--kinetix-announcement-banner-height',
            ),
        ).toMatch(/px$/);

        // The reserved space goes away with the banner.
        w.unmount();
        expect(
            document.documentElement.style.getPropertyValue(
                '--kinetix-announcement-banner-height',
            ),
        ).toBe('');
    });

    it('auto-rotates on its own clock and stops on the pause button', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValueOnce({
            announcements: [
                announcement(1, 'First'),
                announcement(2, 'Second'),
            ],
        });
        const w = mountIt({ autoplay: 5000 });
        await flushPromises();

        vi.advanceTimersByTime(5000);
        await w.vm.$nextTick();
        expect(w.text()).toContain('Second');

        await buttonWithLabel(w, 'Pause rotation')?.trigger('click');
        vi.advanceTimersByTime(15000);
        await w.vm.$nextTick();

        // Still on the entry it was showing when the user hit pause.
        expect(w.text()).toContain('Second');
        vi.useRealTimers();
    });

    it('renders on its first render, before any mount-time work', () => {
        pageProps.kinetix_announcements = {
            unread: 1,
            bannerLimit: 3,
            banner: [announcement(9, 'Already there')],
        };

        // Hydrated during setup: no enter transition replayed per page load,
        // and an SSR render already contains it.
        const w = mount(KinetixAnnouncementBanner, {
            props: { autoplay: 0 },
            global: { plugins: [i18n] },
        });

        expect(w.text()).toContain('Already there');
    });

    it('follows the payload of later responses (persistent layouts)', async () => {
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [announcement(1, 'First')],
        };
        const w = mountIt();
        await flushPromises();

        pageProps.kinetix_announcements = {
            unread: 1,
            bannerLimit: 3,
            banner: [announcement(2, 'Published since')],
        };
        await nextTick();

        expect(w.text()).toContain('Published since');
    });

    it('keeps a dismissed entry closed when Back restores the old payload', async () => {
        const payload = {
            unread: 0,
            bannerLimit: 3,
            banner: [announcement(1, 'First'), announcement(2, 'Second')],
        };
        pageProps.kinetix_announcements = payload;
        const before = mountIt();
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ status: 'success' });
        await buttonWithLabel(before, 'Dismiss')?.trigger('click');
        await flushPromises();
        before.unmount();

        // Inertia restores the page from history WITH its old props.
        pageProps.kinetix_announcements = { ...payload };
        const after = mountIt();
        await flushPromises();

        expect(after.text()).not.toContain('First');
        expect(after.text()).toContain('Second');
    });

    it('`session` hides for now without telling the server', async () => {
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [announcement(1, 'Maintenance')],
        };
        const first = mountIt({ dismissMode: 'session' });
        await flushPromises();

        await buttonWithLabel(first, 'Hide for now')?.trigger('click');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(first.find('[data-slot="alert"]').exists()).toBe(false);
        first.unmount();

        // Same tab: still hidden on the next page.
        const second = mountIt({ dismissMode: 'session' });
        expect(second.find('[data-slot="alert"]').exists()).toBe(false);
    });

    it('`hide` is back on the next mount', async () => {
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [announcement(1, 'Maintenance')],
        };
        const first = mountIt({ dismissMode: 'hide' });
        await buttonWithLabel(first, 'Hide for now')?.trigger('click');
        await flushPromises();
        expect(first.find('[data-slot="alert"]').exists()).toBe(false);
        first.unmount();

        const second = mountIt({ dismissMode: 'hide' });
        expect(second.text()).toContain('Maintenance');
    });

    it('`dontShowAgain` adds a link that closes the entry for good', async () => {
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [announcement(4, 'Beta')],
        };
        const w = mountIt({ dismissMode: 'session', dontShowAgain: true });
        await flushPromises();

        fetchMock.mockResolvedValueOnce({ status: 'success' });
        await w
            .findAll('button')
            .find((b) => b.text() === 'Don’t show again')
            ?.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/announcements/4/dismiss',
            { method: 'POST' },
        );
    });

    it('keeps focus on the banner while entries remain after a close', async () => {
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [announcement(1, 'First'), announcement(2, 'Second')],
        };
        const w = mount(KinetixAnnouncementBanner, {
            props: { autoplay: 0, dismissMode: 'session' },
            global: { plugins: [i18n] },
            attachTo: document.body,
        });

        const close = buttonWithLabel(w, 'Hide for now')!;
        (close.element as HTMLButtonElement).focus();
        await close.trigger('click');
        await flushPromises();

        expect(document.activeElement?.getAttribute('data-slot')).toBe('alert');
        expect(w.text()).toContain('Second');
        w.unmount();
    });

    it("stops auto-rotating for a user who turned on Kinetix's reduced motion", async () => {
        document.documentElement.classList.add('kx-reduce-motion');
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [announcement(1, 'First'), announcement(2, 'Second')],
        };

        const w = mountIt({ autoplay: 5000 });
        await flushPromises();

        // No clock to pause; the arrows stay.
        expect(buttonWithLabel(w, 'Pause rotation')).toBeUndefined();
        expect(buttonWithLabel(w, 'Next announcement')).toBeTruthy();
    });

    it('keeps the close button away from a notice marked not closable', async () => {
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [
                {
                    ...announcement(1, 'Rotate your keys', 'fix'),
                    dismissible: false,
                },
            ],
        };
        const w = mountIt({ dontShowAgain: true });
        await flushPromises();

        expect(buttonWithLabel(w, 'Dismiss')).toBeUndefined();
        expect(w.text()).not.toContain('Don’t show again');
    });

    it('renders the entry’s call to action as a link', async () => {
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [
                {
                    ...announcement(1, 'New export'),
                    actionLabel: 'Read the guide',
                    actionUrl: '/docs/export',
                },
            ],
        };
        const w = mountIt();
        await flushPromises();

        const link = w.find('a[href="/docs/export"]');
        expect(link.text()).toBe('Read the guide');
    });

    it('colors the surface with the entry’s level when asked', async () => {
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [
                {
                    ...announcement(1, 'Maintenance tonight', 'maintenance'),
                    color: 'warning',
                    icon: 'wrench',
                },
            ],
        };

        const plain = mountIt();
        await flushPromises();
        expect(plain.find('[data-slot="alert"]').classes()).not.toContain(
            'bg-warning/10',
        );

        const soft = mountIt({ variant: 'soft' });
        await flushPromises();
        expect(soft.find('[data-slot="alert"]').classes()).toContain(
            'bg-warning/10',
        );
        // The level pill takes the server-resolved color too.
        expect(soft.html()).toContain('text-warning');
    });

    it('keeps a pinned bar opaque under a tinted surface', async () => {
        pageProps.kinetix_announcements = {
            unread: 0,
            bannerLimit: 3,
            banner: [{ ...announcement(1, 'Pinned'), color: 'info' }],
        };
        const w = mountIt({ position: 'fixed-top', variant: 'soft' });
        await flushPromises();

        const bar = w.find('div.fixed > div');
        expect(bar.classes()).toEqual(
            expect.arrayContaining(['bg-popover', 'shadow-lg', 'max-w-3xl']),
        );
        w.unmount();
    });
});
