import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

const fetchMock = vi.hoisted(() => vi.fn());

vi.mock('@/composables/useKinetixHttp', () => ({ kinetixFetch: fetchMock }));

import KinetixFileUpload from '@/components/KinetixFileUpload.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key.replace('kinetix.', ''),
    messages: { en: {} },
});

const mountIt = (props: Record<string, unknown> = {}) =>
    mount(KinetixFileUpload, {
        props: { uploadToken: 'token', isImage: true, ...props },
        global: { plugins: [i18n] },
    });

const pick = async (wrapper: any, files: File[]) => {
    const input = wrapper.get('input[type="file"]');
    Object.defineProperty(input.element, 'files', {
        value: files,
        configurable: true,
    });
    await input.trigger('change');
    await flushPromises();
};

beforeEach(() => fetchMock.mockReset());

describe('KinetixFileUpload storage', () => {
    it('previews a stored file through the URL the server resolved on its disk', () => {
        const wrapper = mountIt({
            value: 'products/a.jpg',
            previewUrls: {
                'products/a.jpg':
                    'https://bucket.s3.example/products/a.jpg?sig=1',
            },
        });

        const img = wrapper.get('img');
        expect(img.attributes('src')).toBe(
            'https://bucket.s3.example/products/a.jpg?sig=1',
        );
        expect(img.attributes('loading')).toBe('lazy');
        expect(img.attributes('decoding')).toBe('async');
    });

    it('never guesses a public URL when the server resolved none for a path', () => {
        const wrapper = mountIt({ value: 'products/a.jpg', previewUrls: {} });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('a.jpg');
    });

    it('keeps the public-disk convention when rendered without server URLs', () => {
        const wrapper = mountIt({ value: 'products/a.jpg' });

        expect(wrapper.get('img').attributes('src')).toBe(
            '/storage/products/a.jpg',
        );
    });

    it('previews a new upload through the URL the upload answered', async () => {
        fetchMock.mockResolvedValueOnce({
            path: 'uploads/new.png',
            url: 'https://cdn.example/uploads/new.png',
            receipt: 'receipt-1',
        });
        const wrapper = mountIt({ previewUrls: null });

        await pick(wrapper, [
            new File(['x'], 'new.png', { type: 'image/png' }),
        ]);
        expect(wrapper.emitted('update:value')?.at(-1)).toEqual([
            'uploads/new.png',
        ]);

        await wrapper.setProps({ value: 'uploads/new.png' });
        expect(wrapper.get('img').attributes('src')).toBe(
            'https://cdn.example/uploads/new.png',
        );
    });

    it('deletes only files uploaded in this session, with their receipt', async () => {
        fetchMock.mockResolvedValueOnce({
            path: 'uploads/new.png',
            url: '/storage/uploads/new.png',
            receipt: 'receipt-1',
        });
        const wrapper = mountIt({
            isMultiple: true,
            value: ['products/stored.jpg'],
            previewUrls: {
                'products/stored.jpg': '/storage/products/stored.jpg',
            },
        });

        await pick(wrapper, [
            new File(['x'], 'new.png', { type: 'image/png' }),
        ]);
        await wrapper.setProps({
            value: ['products/stored.jpg', 'uploads/new.png'],
        });
        fetchMock.mockReset();
        fetchMock.mockResolvedValue({ status: 'deleted' });

        // The record's stored file only leaves the value: cancelling the
        // edit must keep it on disk.
        await wrapper
            .findAll('button[aria-label="remove"]')[0]
            .trigger('click');
        await flushPromises();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.emitted('update:value')?.at(-1)).toEqual([
            ['uploads/new.png'],
        ]);

        await wrapper.setProps({ value: ['uploads/new.png'] });
        await wrapper.get('button[aria-label="remove"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/uploads/delete', {
            method: 'POST',
            body: { receipt: 'receipt-1' },
        });
        expect(wrapper.emitted('update:value')?.at(-1)).toEqual([[]]);
    });
});
