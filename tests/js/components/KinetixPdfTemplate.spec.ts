import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

const kinetixFetch = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => kinetixFetch(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixPdfTemplate from '@/components/KinetixPdfTemplate.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key.replace('kinetix.', ''),
    messages: { en: { kinetix: {} } },
});

const DESCRIPTOR = {
    key: 'quote',
    label: 'Quotation',
    hasLogo: false,
    fields: [
        {
            name: 'accent',
            type: 'color',
            label: 'Accent color',
            default: '#6366f1',
            help: null,
            palette: ['#6366f1', '#ef4444'],
            options: {},
            maxLength: null,
        },
        {
            name: 'doc_title',
            type: 'text',
            label: 'Document title',
            default: 'Quotation',
            help: null,
            palette: [],
            options: {},
            maxLength: 60,
        },
        {
            name: 'striped',
            type: 'toggle',
            label: 'Striped rows',
            default: true,
            help: null,
            palette: [],
            options: {},
            maxLength: null,
        },
    ],
    settings: { accent: '#6366f1', doc_title: 'Quotation', striped: true },
    defaults: { accent: '#6366f1', doc_title: 'Quotation', striped: true },
};

const mountPdf = () =>
    mount(KinetixPdfTemplate, {
        props: { template: 'quote' },
        global: { plugins: [i18n] },
    });

describe('KinetixPdfTemplate', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        kinetixFetch.mockReset();
        kinetixFetch.mockResolvedValue(JSON.parse(JSON.stringify(DESCRIPTOR)));
    });

    it('renders one control per declared field and the preview iframe', async () => {
        const w = mountPdf();
        await flushPromises();
        vi.runAllTimers();
        await flushPromises();

        expect(w.text()).toContain('Accent color');
        expect(w.text()).toContain('Document title');
        expect(w.text()).toContain('Striped rows');
        // Two palette swatches + preview iframe carrying the settings.
        expect(w.findAll('button[style*="background-color"]')).toHaveLength(2);
        expect(w.get('iframe').attributes('src')).toContain(
            '/_kinetix/pdf-templates/quote/preview?',
        );
        expect(w.get('iframe').attributes('src')).toContain('striped=1');
        w.unmount();
    });

    it('debounces unsaved changes into the preview url and download link', async () => {
        const w = mountPdf();
        await flushPromises();
        vi.runAllTimers();
        await flushPromises();

        await w
            .findAll('button[style*="background-color"]')[1]
            .trigger('click');
        vi.advanceTimersByTime(500);
        await flushPromises();

        expect(w.get('iframe').attributes('src')).toContain('accent=%23ef4444');
        expect(w.get('a[href*="download"]').attributes('href')).toContain(
            'accent=%23ef4444',
        );
        w.unmount();
    });

    it('saves the settings with a PATCH to the template endpoint', async () => {
        const w = mountPdf();
        await flushPromises();
        vi.runAllTimers();
        await flushPromises();

        await w.get('input[maxlength="60"]').setValue('Proposal');
        const saveButton = w
            .findAll('button')
            .find((b) => b.text() === 'save')!;
        await saveButton.trigger('click');
        await flushPromises();

        expect(kinetixFetch).toHaveBeenCalledWith(
            '/_kinetix/pdf-templates/quote',
            expect.objectContaining({
                method: 'PATCH',
                body: expect.objectContaining({ doc_title: 'Proposal' }),
            }),
        );
        w.unmount();
    });
});
