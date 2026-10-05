import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const pageProps: Record<string, unknown> = {};
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
});
