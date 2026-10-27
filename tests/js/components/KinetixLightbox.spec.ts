import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import KinetixFilePreview from '@/components/KinetixFilePreview.vue';
import KinetixLightbox from '@/components/KinetixLightbox.vue';
import KinetixLightboxImage from '@/components/KinetixLightboxImage.vue';
import KinetixMarkdownContent from '@/components/KinetixMarkdownContent.vue';
import ImageCell from '@/components/Table/cells/ImageCell.vue';
import {
    collectLightboxGroup,
    normalizePreviewDetail,
    openKinetixLightbox,
    resolveLightboxItemType,
} from '@/composables/useKinetixLightbox';
import type { KinetixLightboxItem } from '@/types/kinetix';
import { i18n } from './i18n';

// Hooks run last-registered-first: unmount (portals included) before the
// body is cleared.
afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});
enableAutoUnmount(afterEach);

const photos: KinetixLightboxItem[] = [
    { url: '/a.jpg', caption: 'Alpha' },
    { url: '/b.jpg', caption: 'Bravo' },
    { url: '/c.jpg', caption: 'Charlie' },
];

const global = { plugins: [i18n] };

const viewer = () =>
    document.body.querySelector<HTMLElement>('[data-kinetix-lightbox-viewer]');

const counter = () =>
    viewer()?.querySelector('p[aria-hidden="true"]')?.textContent?.trim();

const button = (label: string) =>
    document.body.querySelector<HTMLButtonElement>(
        `button[aria-label="${label}"]`,
    );

function mountViewer(props: Record<string, unknown> = {}) {
    return mount(KinetixLightbox, {
        attachTo: document.body,
        global,
        props: {
            open: true,
            items: photos,
            index: 1,
            animation: 'fade',
            'onUpdate:open': (value: boolean) =>
                wrapperRef.current?.setProps({ open: value }),
            'onUpdate:index': (value: number) =>
                wrapperRef.current?.setProps({ index: value }),
            ...props,
        },
    });
}

const wrapperRef: { current: ReturnType<typeof mount> | null } = {
    current: null,
};

async function open(props: Record<string, unknown> = {}) {
    wrapperRef.current = mountViewer(props);
    await flushPromises();

    return wrapperRef.current;
}

function press(key: string) {
    viewer()?.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
    );
}

describe('KinetixLightbox', () => {
    it('shows the item at index with a counter and an accessible title', async () => {
        await open();

        expect(counter()).toBe('2 / 3');
        expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
        expect(document.body.textContent).toContain('Bravo');
        expect(
            document.body
                .querySelector('img[data-lightbox-media]')
                ?.getAttribute('src'),
        ).toBe('/b.jpg');
    });

    it('pages with the arrow keys and Home/End, and announces the position', async () => {
        const wrapper = await open();

        press('ArrowRight');
        await nextTick();
        expect(counter()).toBe('3 / 3');
        expect(wrapper.emitted('update:index')?.at(-1)).toEqual([2]);
        await new Promise((resolve) => requestAnimationFrame(resolve));
        expect(
            document.getElementById('kinetix-live-region')?.textContent,
        ).toBe('3 of 3: Charlie');

        press('Home');
        await nextTick();
        expect(counter()).toBe('1 / 3');

        press('End');
        await nextTick();
        expect(counter()).toBe('3 / 3');
    });

    it('stops at the ends unless it loops', async () => {
        await open({ index: 2 });

        expect(button('Next')?.disabled).toBe(true);
        expect(button('Previous')?.disabled).toBe(false);

        press('ArrowRight');
        await nextTick();
        expect(counter()).toBe('3 / 3');

        wrapperRef.current?.unmount();
        document.body.innerHTML = '';
        await open({ index: 2, loop: true });

        press('ArrowRight');
        await nextTick();
        expect(counter()).toBe('1 / 3');
    });

    it('names every icon-only control', async () => {
        await open();

        const unnamed = Array.from(
            viewer()?.querySelectorAll('button, a') ?? [],
        ).filter(
            (control) =>
                !control.getAttribute('aria-label') &&
                !control.textContent?.trim(),
        );

        expect(unnamed).toHaveLength(0);
        expect(button('Close')).not.toBeNull();
        expect(button('Zoom in')).not.toBeNull();
    });

    it('marks the current thumbnail and jumps to a clicked one', async () => {
        await open();

        const thumbnails = Array.from(
            document.body.querySelectorAll<HTMLButtonElement>(
                'button[aria-label^="Go to item"]',
            ),
        );

        expect(thumbnails).toHaveLength(3);
        expect(thumbnails[1].getAttribute('aria-current')).toBe('true');
        expect(thumbnails[0].hasAttribute('aria-current')).toBe(false);

        thumbnails[0].click();
        await nextTick();
        expect(counter()).toBe('1 / 3');
    });

    it('hides the thumbnail strip and the arrows for a single item', async () => {
        await open({ items: [photos[0]], index: 0 });

        expect(counter()).toBe('');
        expect(button('Next')).toBeNull();
        expect(
            document.body.querySelector('button[aria-label^="Go to item"]'),
        ).toBeNull();
    });

    it('zooms with the toolbar and resets with 0', async () => {
        await open();

        expect(button('Zoom out')?.disabled).toBe(true);

        button('Zoom in')?.click();
        await nextTick();
        expect(button('Zoom out')?.disabled).toBe(false);
        expect(
            document.body.querySelector<HTMLElement>('img[data-lightbox-media]')
                ?.style.transform,
        ).toContain('scale(1.5)');

        press('0');
        await nextTick();
        expect(button('Zoom out')?.disabled).toBe(true);
    });

    it('closes on a tap on the empty stage, not on the image', async () => {
        const wrapper = await open();
        const stage = document.body.querySelector<HTMLElement>(
            '.touch-none',
        ) as HTMLElement;
        const tap = (target: Element) => {
            target.dispatchEvent(
                new PointerEvent('pointerdown', {
                    bubbles: true,
                    pointerId: 1,
                    button: 0,
                    clientX: 5,
                    clientY: 5,
                }),
            );
            target.dispatchEvent(
                new PointerEvent('pointerup', {
                    bubbles: true,
                    pointerId: 1,
                    button: 0,
                    clientX: 5,
                    clientY: 5,
                }),
            );
        };

        tap(document.body.querySelector('img[data-lightbox-media]') as Element);
        expect(wrapper.emitted('update:open')).toBeUndefined();

        tap(stage);
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('shows a download card for files it cannot render', async () => {
        await open({
            items: [{ url: '/report.zip', downloadLabel: 'Get the zip' }],
            index: 0,
        });

        expect(document.body.textContent).toContain('Get the zip');
        expect(button('Zoom in')).toBeNull();
    });
});

describe('lightbox helpers', () => {
    it('resolves the item type from the extension unless given', () => {
        expect(resolveLightboxItemType({ url: '/x.webp' })).toBe('image');
        expect(resolveLightboxItemType({ url: '/x.pdf?v=2' })).toBe('pdf');
        expect(resolveLightboxItemType({ url: '/file?id=1' })).toBe('other');
        expect(
            resolveLightboxItemType({ url: '/file?id=1', type: 'image' }),
        ).toBe('image');
    });

    it('accepts both the single-file and the gallery event shapes', () => {
        expect(
            normalizePreviewDetail({ url: '/a.png', label: 'A' })?.items[0],
        ).toMatchObject({ url: '/a.png', caption: 'A' });
        expect(normalizePreviewDetail({ items: photos, index: 9 })?.index).toBe(
            2,
        );
        expect(normalizePreviewDetail({})).toBeNull();
    });

    it('collects a group in document order within its scope', () => {
        document.body.innerHTML = `
            <section data-kinetix-lightbox-scope>
                <a href="/1.jpg" data-kinetix-lightbox="g"><img src="/1-sm.jpg" alt="One"></a>
                <a href="/2.jpg" data-kinetix-lightbox="g" data-caption="Two"><img src="/2-sm.jpg" alt=""></a>
            </section>
            <a href="/3.jpg" data-kinetix-lightbox="g"><img src="/3-sm.jpg" alt=""></a>`;

        const second = document.querySelectorAll('a')[1];
        const gallery = collectLightboxGroup(second);

        expect(gallery.index).toBe(1);
        expect(gallery.items.map((item) => item.url)).toEqual([
            '/1.jpg',
            '/2.jpg',
        ]);
        expect(gallery.items[0]).toMatchObject({
            alt: 'One',
            thumbnail: expect.stringContaining('/1-sm.jpg'),
        });
        expect(gallery.items[1].caption).toBe('Two');
    });

    it('falls back to a new tab when no host is mounted', () => {
        const opened = vi.spyOn(window, 'open').mockReturnValue(null);

        expect(openKinetixLightbox([{ url: '/a.jpg' }])).toBe(false);
        expect(opened).toHaveBeenCalledWith(
            '/a.jpg',
            '_blank',
            'noopener,noreferrer',
        );
    });
});

describe('KinetixFilePreview host', () => {
    it('opens a gallery from openKinetixLightbox()', async () => {
        mount(KinetixFilePreview, { attachTo: document.body, global });

        expect(openKinetixLightbox(photos, { index: 2 })).toBe(true);
        await flushPromises();

        expect(counter()).toBe('3 / 3');
    });

    it('opens [data-kinetix-lightbox] triggers as a gallery', async () => {
        const wrapper = mount(KinetixFilePreview, {
            attachTo: document.body,
            global,
        });
        const host = document.createElement('div');

        host.innerHTML = `
            <a href="/1.jpg" data-kinetix-lightbox="trip"><img src="/1.jpg" alt=""></a>
            <a href="/2.jpg" data-kinetix-lightbox="trip"><img src="/2.jpg" alt=""></a>`;
        document.body.appendChild(host);

        const click = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
            button: 0,
        });

        host.querySelectorAll('img')[1].dispatchEvent(click);
        await flushPromises();

        expect(click.defaultPrevented).toBe(true);
        expect(counter()).toBe('2 / 2');

        wrapper.unmount();
    });

    it('leaves triggers alone with bind=false and on modified clicks', async () => {
        mount(KinetixFilePreview, {
            attachTo: document.body,
            global,
            props: { bind: false },
        });
        const link = document.createElement('a');

        link.href = '/1.jpg';
        link.setAttribute('data-kinetix-lightbox', '');
        document.body.appendChild(link);

        const click = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        });

        link.dispatchEvent(click);
        await flushPromises();

        expect(click.defaultPrevented).toBe(false);
        expect(viewer()).toBeNull();
    });
});

describe('lightbox triggers', () => {
    it('KinetixLightboxImage is a named button that opens its group', async () => {
        const listener = vi.fn((event: Event) => event.preventDefault());

        window.addEventListener('kinetix:preview', listener);

        const wrapper = mount(
            {
                components: { KinetixLightboxImage },
                template: `<div>
                    <KinetixLightboxImage src="/1.jpg" alt="Front" group="house" />
                    <KinetixLightboxImage src="/2.jpg" caption="Back" group="house" />
                </div>`,
            },
            { attachTo: document.body, global },
        );

        const triggers = wrapper.findAll('button');

        expect(triggers[0].attributes('aria-label')).toBe('View Front');
        expect(triggers[1].attributes('aria-haspopup')).toBe('dialog');

        await triggers[1].trigger('click');

        const detail = (listener.mock.calls[0][0] as CustomEvent).detail;

        expect(detail.index).toBe(1);
        expect(
            detail.items.map((item: KinetixLightboxItem) => item.url),
        ).toEqual(['/1.jpg', '/2.jpg']);
        expect(detail.items[0].origin).toBeInstanceOf(HTMLImageElement);

        window.removeEventListener('kinetix:preview', listener);
    });

    it('Markdown images become keyboard buttons that open one gallery', async () => {
        const listener = vi.fn((event: Event) => event.preventDefault());

        window.addEventListener('kinetix:preview', listener);

        const wrapper = mount(KinetixMarkdownContent, {
            attachTo: document.body,
            global,
            props: {
                html: `<p><img src="/one.png" alt="Settings"></p>
                    <a href="/elsewhere"><img src="/linked.png" alt="Linked"></a>
                    <figure><img src="/two.png" alt=""><figcaption>The editor</figcaption></figure>`,
            },
        });
        await flushPromises();

        const images = wrapper.findAll('img');

        expect(images[0].attributes('role')).toBe('button');
        expect(images[0].attributes('aria-label')).toBe('View Settings');
        expect(images[0].attributes('tabindex')).toBe('0');
        expect(images[1].attributes('role')).toBeUndefined();

        await images[2].trigger('keydown', { key: 'Enter' });

        const detail = (listener.mock.calls[0][0] as CustomEvent).detail;

        expect(detail.index).toBe(1);
        expect(
            detail.items.map((item: KinetixLightboxItem) => item.caption),
        ).toEqual(['Settings', 'The editor']);

        window.removeEventListener('kinetix:preview', listener);
    });

    it('Markdown images stay inert with :lightbox="false"', async () => {
        const wrapper = mount(KinetixMarkdownContent, {
            global,
            props: { html: '<img src="/one.png" alt="A">', lightbox: false },
        });
        await flushPromises();

        expect(wrapper.find('img').attributes('role')).toBeUndefined();
    });

    it('a previewable image column renders a lightbox button', () => {
        const record = { id: 1, values: { avatar: '/u.png' } } as never;
        const previewable = mount(ImageCell, {
            global,
            props: {
                col: {
                    name: 'avatar',
                    label: 'Avatar',
                    type: 'image',
                    isPreviewable: true,
                },
                record,
            },
        });
        const plain = mount(ImageCell, {
            global,
            props: {
                col: { name: 'avatar', label: 'Avatar', type: 'image' },
                record,
            },
        });

        expect(
            previewable.find('button').attributes('data-kinetix-lightbox'),
        ).toBe('kinetix-column:avatar');
        expect(plain.find('button').exists()).toBe(false);
    });
});
