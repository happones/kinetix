import { config, flushPromises, mount as baseMount } from '@vue/test-utils';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import KinetixFilePreview from '@/components/KinetixFilePreview.vue';
import { executeAction } from '@/composables/useKinetixActions';
import { i18n } from './i18n';

config.global.plugins = [i18n];

const mounted: { unmount(): void }[] = [];

const mount: typeof baseMount = ((...args: Parameters<typeof baseMount>) => {
    const wrapper = baseMount(...args);
    mounted.push(wrapper);

    return wrapper;
}) as typeof baseMount;

function firePreview(detail: Record<string, unknown>) {
    window.dispatchEvent(new CustomEvent('kinetix:preview', { detail }));
}

// First in the file: the viewer's lazy entry is module-wide, and the openings
// below load it for good.
describe('KinetixFilePreview before the viewer has loaded', () => {
    afterEach(() => {
        mounted.splice(0).forEach((wrapper) => wrapper.unmount());
        document.body.innerHTML = '';
    });

    it('prefetches on the first pointer over a trigger, then stops listening', () => {
        const remove = vi.spyOn(document, 'removeEventListener');
        mount(KinetixFilePreview, { attachTo: document.body });
        const link = document.createElement('a');
        link.setAttribute('data-kinetix-lightbox', '');
        document.body.appendChild(link);

        document.body.dispatchEvent(
            new Event('pointerover', { bubbles: true }),
        );
        expect(remove).not.toHaveBeenCalledWith(
            'pointerover',
            expect.any(Function),
        );

        link.dispatchEvent(new Event('pointerover', { bubbles: true }));
        expect(remove).toHaveBeenCalledWith(
            'pointerover',
            expect.any(Function),
        );
        expect(remove).toHaveBeenCalledWith('focusin', expect.any(Function));
        remove.mockRestore();
    });
});

describe('KinetixFilePreview', () => {
    // The viewer is a lazy chunk: warm the module so a flush opens it.
    beforeAll(async () => {
        await import('@/components/KinetixLightbox.vue');
    });

    afterEach(() => {
        mounted.splice(0).forEach((wrapper) => wrapper.unmount());
        document.body.innerHTML = '';
    });

    it('opens with an <img> when an image url is dispatched', async () => {
        mount(KinetixFilePreview, { attachTo: document.body });

        firePreview({ url: 'https://x/photo.png', label: 'Photo' });
        await flushPromises();

        const img = document.body.querySelector('img');
        expect(img).not.toBeNull();
        expect(img?.getAttribute('src')).toBe('https://x/photo.png');
    });

    it('renders an <iframe> for a PDF url', async () => {
        mount(KinetixFilePreview, { attachTo: document.body });

        firePreview({ url: 'https://x/invoice.pdf' });
        await flushPromises();

        expect(document.body.querySelector('iframe')).not.toBeNull();
    });

    it('honours an explicit type over the extension', async () => {
        mount(KinetixFilePreview, { attachTo: document.body });

        firePreview({ url: 'https://x/file?id=9', type: 'image' });
        await flushPromises();

        expect(document.body.querySelector('img')).not.toBeNull();
    });

    it('mounts no viewer (and none of its document listeners) before the first opening', () => {
        const add = vi.spyOn(document, 'addEventListener');

        mount(KinetixFilePreview, { attachTo: document.body });

        expect(
            document.body.querySelector('[data-kinetix-lightbox-viewer]'),
        ).toBeNull();
        expect(add).not.toHaveBeenCalledWith(
            'fullscreenchange',
            expect.any(Function),
        );
        expect(add).not.toHaveBeenCalledWith(
            'visibilitychange',
            expect.any(Function),
        );
        add.mockRestore();
    });

    it('claims the event synchronously and opens once the viewer has loaded', async () => {
        mount(KinetixFilePreview, { attachTo: document.body });

        const event = new CustomEvent('kinetix:preview', {
            detail: { url: 'https://x/photo.png' },
            cancelable: true,
        });
        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);

        await flushPromises();
        expect(document.body.querySelector('img')).not.toBeNull();
    });

    it('claims a [data-kinetix-lightbox] click synchronously', async () => {
        mount(KinetixFilePreview, { attachTo: document.body });
        const link = document.createElement('a');
        link.href = '/1.jpg';
        link.setAttribute('data-kinetix-lightbox', '');
        link.innerHTML = '<img src="/1.jpg" alt="">';
        document.body.appendChild(link);

        const click = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
            button: 0,
        });
        link.querySelector('img')!.dispatchEvent(click);

        expect(click.defaultPrevented).toBe(true);
        await flushPromises();
        expect(
            document.body.querySelector('[data-kinetix-lightbox-viewer]'),
        ).not.toBeNull();
    });

    it('removes the window listener on unmount (no leak)', () => {
        const remove = vi.spyOn(window, 'removeEventListener');
        const wrapper = mount(KinetixFilePreview);

        wrapper.unmount();

        expect(remove).toHaveBeenCalledWith(
            'kinetix:preview',
            expect.any(Function),
        );
        remove.mockRestore();
    });
});

describe('executeAction preview/download', () => {
    it('dispatches kinetix:preview for a preview action', () => {
        const handler = vi.fn();
        window.addEventListener('kinetix:preview', handler);

        executeAction({
            name: 'preview',
            label: 'Preview',
            url: '/files/1',
            isPreview: true,
            previewType: 'pdf',
        } as any);

        expect(handler).toHaveBeenCalledOnce();
        const detail = (handler.mock.calls[0][0] as CustomEvent).detail;
        expect(detail).toMatchObject({ url: '/files/1', type: 'pdf' });

        window.removeEventListener('kinetix:preview', handler);
    });

    it('triggers an anchor download for a download action', () => {
        const click = vi.fn();
        const create = vi
            .spyOn(document, 'createElement')
            .mockImplementation(
                () =>
                    ({ click, remove: vi.fn(), setAttribute: vi.fn() }) as any,
            );
        const append = vi
            .spyOn(document.body, 'appendChild')
            .mockImplementation((n) => n);

        executeAction({
            name: 'download',
            label: 'Download',
            url: '/files/1/download',
            isDownload: true,
        } as any);

        expect(click).toHaveBeenCalledOnce();

        create.mockRestore();
        append.mockRestore();
    });
});
