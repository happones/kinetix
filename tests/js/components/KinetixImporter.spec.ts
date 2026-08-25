import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import KinetixImporter from '@/components/KinetixImporter.vue';
import type { KinetixImportPreview } from '@/types/kinetix';

/**
 * The wizard's contract. The regression it guards: a file with twenty-plus
 * columns used to stack a select per column, the parse options and a full-width
 * preview table into one unbounded dialog, which grew past the viewport and
 * stranded its own actions. Each step is now bounded, and the preview is capped
 * on both axes.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const settings = (over: Record<string, unknown> = {}) => ({
    hasPreview: true,
    previewRows: 10,
    previewColumns: 8,
    layout: 'auto',
    fullscreenThreshold: 12,
    maxUploadSize: 102400,
    template: null,
    ...over,
});

/** A preview payload with `count` source columns, two of them mapped. */
const preview = (
    count: number,
    over: Partial<KinetixImportPreview> = {},
): KinetixImportPreview => {
    const headers = Array.from({ length: count }, (_, i) => `header_${i + 1}`);

    return {
        headers,
        rows: [headers.map((_, i) => `v${i}`)],
        columns: [
            { name: 'name', label: 'Name', isRequired: true, guesses: [] },
            { name: 'email', label: 'Email', isRequired: false, guesses: [] },
        ],
        options: {
            delimiter: ',',
            enclosure: '"',
            skipLines: 0,
            hasHeader: true,
        },
        settings: settings() as KinetixImportPreview['settings'],
        autoMapping: { name: 0, email: 1 },
        isExactMatch: false,
        fileToken: 'token',
        totalRows: 1204882,
        ...over,
    } as KinetixImportPreview;
};

const mountWith = (props: Record<string, unknown> = {}) =>
    mount(KinetixImporter, {
        props: { importer: 'token-123', ...props },
        global: { plugins: [i18n] },
    });

/** Choose a file without a real file dialog. */
const chooseFile = async (w: ReturnType<typeof mountWith>) => {
    const file = new File(['name,email\nA,a@x.com\n'], 'people.csv', {
        type: 'text/csv',
    });
    w.findComponent({ name: 'ImporterDropzone' }).vm.$emit('update:file', file);
    await flushPromises();
};

const clickPrimary = async (w: ReturnType<typeof mountWith>) => {
    const buttons = w.findAll('button');
    await buttons[buttons.length - 1].trigger('click');
    await flushPromises();
};

describe('KinetixImporter — the wizard', () => {
    beforeEach(() => {
        fetchMock.mockReset();
    });

    it('starts on the file step and cannot continue without a file', () => {
        const w = mountWith();

        expect(w.findComponent({ name: 'ImporterDropzone' }).exists()).toBe(
            true,
        );
        expect(w.findComponent({ name: 'ImporterMapping' }).exists()).toBe(
            false,
        );
        expect(w.findComponent({ name: 'ImporterPreview' }).exists()).toBe(
            false,
        );

        const buttons = w.findAll('button');
        expect(
            buttons[buttons.length - 1].attributes('disabled'),
        ).toBeDefined();
    });

    it('shows a download-template link pointing at the template endpoint', () => {
        const w = mountWith({ template: 'ProductImporter.csv' });

        const link = w.find('a[download]');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe(
            '/_kinetix/imports/template?importer=token-123',
        );
        expect(link.attributes('download')).toBe('ProductImporter.csv');
    });

    it('parses the file, then asks for the mapping', async () => {
        fetchMock.mockResolvedValue(preview(4));
        const w = mountWith();

        await chooseFile(w);
        await clickPrimary(w);

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/imports/upload',
            expect.objectContaining({ method: 'POST' }),
        );
        expect(w.findComponent({ name: 'ImporterMapping' }).exists()).toBe(
            true,
        );
    });

    it('skips the mapping step when the file lines up one-for-one', async () => {
        fetchMock.mockResolvedValue(preview(2, { isExactMatch: true }));
        const w = mountWith();

        await chooseFile(w);
        await clickPrimary(w);

        // Straight to review: there is nothing left for the user to decide.
        expect(w.findComponent({ name: 'ImporterMapping' }).exists()).toBe(
            false,
        );
        expect(w.findComponent({ name: 'ImporterPreview' }).exists()).toBe(
            true,
        );
    });

    it('reports the parsed column count so the shell can resize itself', async () => {
        fetchMock.mockResolvedValue(preview(24));
        const w = mountWith();

        await chooseFile(w);
        await clickPrimary(w);

        const emitted = w.emitted('update:columns') ?? [];
        expect(emitted[emitted.length - 1]).toEqual([24]);
    });

    it('blocks the mapping step while a required column is unmapped', async () => {
        fetchMock.mockResolvedValue(
            preview(4, { autoMapping: { name: null, email: 1 } }),
        );
        const w = mountWith();

        await chooseFile(w);
        await clickPrimary(w);

        const buttons = w.findAll('button');
        expect(
            buttons[buttons.length - 1].attributes('disabled'),
        ).toBeDefined();
    });

    it('surfaces a failed parse as an announced alert, staying on the file step', async () => {
        fetchMock.mockRejectedValue({ message: 'Unreadable file' });
        const w = mountWith();

        await chooseFile(w);
        await clickPrimary(w);

        const alert = w.find('[role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('Unreadable file');
        expect(w.findComponent({ name: 'ImporterMapping' }).exists()).toBe(
            false,
        );
    });

    it('queues the import from the review step', async () => {
        fetchMock.mockResolvedValueOnce(preview(2, { isExactMatch: true }));
        fetchMock.mockResolvedValueOnce({
            status: 'queued',
            message: 'Queued',
        });
        const w = mountWith();

        await chooseFile(w);
        await clickPrimary(w);
        await clickPrimary(w);

        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/imports/start',
            expect.objectContaining({ method: 'POST' }),
        );
        expect(w.emitted('started')).toEqual([['Queued']]);
    });

    it('omits the preview table for an importer that disables it', async () => {
        fetchMock.mockResolvedValue(
            preview(2, {
                isExactMatch: true,
                settings: settings({
                    hasPreview: false,
                }) as KinetixImportPreview['settings'],
            }),
        );
        const w = mountWith();

        await chooseFile(w);
        await clickPrimary(w);

        expect(w.findComponent({ name: 'ImporterPreview' }).exists()).toBe(
            false,
        );
    });

    it('caps the step body in a modal so a wide file cannot grow the dialog', async () => {
        fetchMock.mockResolvedValue(preview(24));
        const w = mountWith({ surface: 'modal' });

        await chooseFile(w);
        await clickPrimary(w);

        // The wizard owns a bounded scroller; the dialog itself stays put.
        expect(w.html()).toContain('max-h-[min(55vh,32rem)]');
    });

    it('adds no scroller of its own inside a sheet, which already has one', async () => {
        fetchMock.mockResolvedValue(preview(24));
        const w = mountWith({ surface: 'sheet' });

        await chooseFile(w);
        await clickPrimary(w);

        expect(w.html()).not.toContain('max-h-[min(55vh,32rem)]');
        // …but the actions still stick to the bottom of the sheet's scroller,
        // so a long mapping list never pushes them out of reach.
        expect(w.html()).toContain('sticky');
    });
});
