import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

const fetchMock = vi.hoisted(() =>
    vi.fn(async () => ({ path: 'uploads/ok.png' })),
);

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

const pick = async (wrapper: any, files: File[]) => {
    const input = wrapper.get('input[type="file"]');
    Object.defineProperty(input.element, 'files', {
        value: files,
        configurable: true,
    });
    await input.trigger('change');
    await flushPromises();
};

describe('KinetixFileUpload checks before uploading', () => {
    it('sends only the files the rules accept, and says why the others stay', async () => {
        fetchMock.mockClear();
        const wrapper = mount(KinetixFileUpload, {
            props: {
                uploadToken: 'token',
                isMultiple: true,
                constraints: {
                    kind: 'file',
                    fileTypes: ['png'],
                    kilobytes: { max: 1 },
                },
            },
            global: { plugins: [i18n] },
        });

        expect(wrapper.get('input[type="file"]').attributes('accept')).toBe(
            '.png',
        );

        await pick(wrapper, [
            new File(['x'], 'ok.png', { type: 'image/png' }),
            new File(['x'], 'cv.pdf', { type: 'application/pdf' }),
            new File(['x'.repeat(4096)], 'huge.png', { type: 'image/png' }),
        ]);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('update:value')?.at(-1)).toEqual([
            ['uploads/ok.png'],
        ]);
        expect(wrapper.get('[role="alert"]').text()).toBe(
            'upload_rejected_type upload_rejected_size',
        );
    });
});
