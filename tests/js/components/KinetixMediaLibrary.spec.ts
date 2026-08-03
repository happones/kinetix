import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...a: unknown[]) => fetchMock(...a),
}));

import KinetixMediaLibrary from '@/components/KinetixMediaLibrary.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                remove: 'Remove',
                media_add: 'Add files',
                media_uploading: 'Uploading…',
                media_upload_failed: 'Upload failed.',
            },
        },
    },
});

const imageItem = {
    id: 1,
    url: '/storage/a.jpg',
    name: 'a.jpg',
    size: 2048,
    mime: 'image/jpeg',
};
const fileItem = {
    id: 2,
    url: '/storage/doc.pdf',
    name: 'doc.pdf',
    size: 4096,
    mime: 'application/pdf',
};

const mountIt = (value: any[] = []) =>
    mount(KinetixMediaLibrary, {
        props: { value, uploadToken: 'tok' },
        global: { plugins: [i18n] },
    });

beforeEach(() => fetchMock.mockReset());

describe('KinetixMediaLibrary', () => {
    it('renders a grid item per media (image thumb + file icon)', () => {
        const w = mountIt([imageItem, fileItem]);
        expect(w.findAll('img')).toHaveLength(1); // only the image
        expect(w.text()).toContain('a.jpg');
        expect(w.text()).toContain('doc.pdf');
    });

    it('uploads files and appends items', async () => {
        fetchMock.mockResolvedValueOnce({
            path: 'uploads/x.png',
            url: '/storage/uploads/x.png',
        });
        const w = mountIt([]);

        const input = w.find('input[type="file"]').element as HTMLInputElement;
        const file = new File(['x'], 'x.png', { type: 'image/png' });
        Object.defineProperty(input, 'files', {
            value: [file],
            configurable: true,
        });
        await w.find('input[type="file"]').trigger('change');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/uploads/store',
            expect.objectContaining({ method: 'POST' }),
        );
        const emitted = w.emitted('update:value')!.at(-1)![0] as any[];
        expect(emitted).toHaveLength(1);
        expect(emitted[0].name).toBe('x.png');
    });

    it('removes an item', async () => {
        const w = mountIt([imageItem, fileItem]);
        await w.find('button[aria-label="Remove"]').trigger('click');

        const emitted = w.emitted('update:value')!.at(-1)![0] as any[];
        expect(emitted).toHaveLength(1);
        expect(emitted[0].id).toBe(2);
    });

    it('renders one plain CSS grid below the virtualization threshold', () => {
        const w = mountIt([imageItem, fileItem]);

        expect(w.find('.sm\\:grid-cols-3').exists()).toBe(true);
        expect(w.findAll('[draggable="true"]')).toHaveLength(2);
    });

    it('windows the grid into rows once the library grows past the threshold', () => {
        const many = Array.from({ length: 60 }, (_, i) => ({
            ...imageItem,
            id: i + 1,
            name: `img-${i}.jpg`,
        }));
        const w = mountIt(many);

        // Virtualized: a scroll container with a sized spacer, and only a window
        // of tiles in the DOM rather than all 60.
        const scroller = w.find('.overflow-y-auto');
        expect(scroller.exists()).toBe(true);
        expect(scroller.find('.relative').attributes('style')).toContain(
            'height:',
        );
        expect(w.findAll('[draggable="true"]').length).toBeLessThan(60);
    });

    it('reorders items via drag and drop', async () => {
        const w = mountIt([imageItem, fileItem]);
        const cards = w.findAll('[draggable="true"]');

        await cards[0].trigger('dragstart');
        await cards[1].trigger('drop');

        const emitted = w.emitted('update:value')!.at(-1)![0] as any[];
        expect(emitted[0].id).toBe(2);
        expect(emitted[1].id).toBe(1);
    });
});
