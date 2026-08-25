import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import ImporterMapping from '@/components/Importer/ImporterMapping.vue';
import ImporterOptions from '@/components/Importer/ImporterOptions.vue';
import ImporterPreview from '@/components/Importer/ImporterPreview.vue';
import KinetixImportModal from '@/components/KinetixImportModal.vue';
import KinetixModal from '@/components/primitives/KinetixModal.vue';
import type { KinetixImportPreview } from '@/types/kinetix';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                import_unused_columns:
                    '{count} source columns are ignored: {columns}',
                import_skipped_lines_short: '{count} lines omitted',
                import_exact_match: 'The file matches this importer exactly',
            },
        },
    },
});

const mountWith = (component: unknown, props: Record<string, unknown>) =>
    mount(component as never, { props, global: { plugins: [i18n] } });

const columns = (count: number) =>
    Array.from({ length: count }, (_, i) => ({
        name: `field_${i + 1}`,
        label: `Field ${i + 1}`,
        isRequired: i === 0,
        guesses: [],
    }));

describe('ImporterMapping — usable at any column count', () => {
    const base = (over: Record<string, unknown> = {}) => ({
        columns: columns(24),
        headers: Array.from({ length: 24 }, (_, i) => `header_${i + 1}`),
        mapping: Object.fromEntries(
            columns(24).map((c, i) => [c.name, i < 20 ? i : null]),
        ),
        isExactMatch: false,
        unusedHeaders: ['header_21', 'header_22'],
        missingRequired: [],
        ...over,
    });

    it('renders one labelled select per target column', () => {
        const w = mountWith(ImporterMapping, base());

        expect(w.findAll('li')).toHaveLength(24);
        // Every select is associated with a visible label, never placeholder-only.
        expect(w.findAll('label[for^="kinetix-import-map-"]')).toHaveLength(24);
    });

    it('filters the list by search, so a long list stays navigable', async () => {
        const w = mountWith(ImporterMapping, base());

        await w.find('input[type="search"]').setValue('Field 13');

        expect(w.findAll('li')).toHaveLength(1);
    });

    it('names the columns the import would silently drop', () => {
        const w = mountWith(ImporterMapping, base());

        expect(w.text()).toContain('header_21');
    });

    it('states an exact match instead of asking for a mapping it already knows', () => {
        const w = mountWith(ImporterMapping, base({ isExactMatch: true }));

        expect(w.text()).toContain('The file matches this importer exactly');
    });
});

describe('ImporterOptions — folded by default', () => {
    const options = {
        delimiter: ',',
        enclosure: '"',
        skipLines: 2,
        hasHeader: true,
    };

    it('starts collapsed with its settings still stated in the summary', () => {
        const w = mountWith(ImporterOptions, { options });

        const toggle = w.find('button[aria-expanded]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        // Folded, not hidden: the current settings are still legible.
        expect(w.text()).toContain('2 lines omitted');
    });

    it('expands to the parse controls and links every field to a label', async () => {
        const w = mountWith(ImporterOptions, { options });

        await w.find('button[aria-expanded]').trigger('click');

        expect(
            w.find('button[aria-expanded]').attributes('aria-expanded'),
        ).toBe('true');
        expect(w.find('input[type="number"]').attributes('id')).toBeDefined();
        expect(
            w.findAll('label[for^="kinetix-import-"]').length,
        ).toBeGreaterThan(0);
    });
});

describe('ImporterPreview — bounded on both axes', () => {
    const preview = (count: number): KinetixImportPreview =>
        ({
            headers: Array.from({ length: count }, (_, i) => `header_${i + 1}`),
            rows: [Array.from({ length: count }, (_, i) => `v${i}`)],
            columns: [],
            options: {
                delimiter: ',',
                enclosure: '"',
                skipLines: 0,
                hasHeader: true,
            },
            settings: {},
            autoMapping: {},
            isExactMatch: false,
            fileToken: 't',
            totalRows: 1204882,
        }) as unknown as KinetixImportPreview;

    it('caps the columns it renders and folds the rest behind a toggle', async () => {
        const w = mountWith(ImporterPreview, {
            preview: preview(24),
            maxColumns: 8,
            columnForHeader: () => null,
        });

        // 8 source columns + the row-number column.
        expect(w.findAll('thead th')).toHaveLength(9);

        await w.find('button').trigger('click');

        expect(w.findAll('thead th')).toHaveLength(25);
    });

    it('renders no toggle when every column already fits', () => {
        const w = mountWith(ImporterPreview, {
            preview: preview(4),
            maxColumns: 8,
            columnForHeader: () => null,
        });

        expect(w.findAll('button')).toHaveLength(0);
    });

    it('keeps the horizontal scroll inside its own container', () => {
        const w = mountWith(ImporterPreview, {
            preview: preview(24),
            maxColumns: 0,
            columnForHeader: () => null,
        });

        expect(w.find('.overflow-x-auto').exists()).toBe(true);
    });

    it('exposes table semantics: a caption and scoped headers', () => {
        const w = mountWith(ImporterPreview, {
            preview: preview(3),
            maxColumns: 0,
            columnForHeader: () => null,
        });

        expect(w.find('caption').exists()).toBe(true);
        expect(w.find('thead th').attributes('scope')).toBe('col');
        expect(w.find('tbody th').attributes('scope')).toBe('row');
    });
});

describe('KinetixModal — the fullscreen layout', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    const panelClasses = async (props: Record<string, unknown>) => {
        const w = mount(KinetixModal, {
            attachTo: document.body,
            props: { open: true, title: 'Import', ...props },
            global: { plugins: [i18n] },
        });
        await w.vm.$nextTick();

        const panel = document.body.querySelector(
            '[role="dialog"] [tabindex="-1"]',
        ) as HTMLElement;

        return panel.className;
    };

    it('bounds the panel to the viewport and hands the body the leftover height', async () => {
        const classes = await panelClasses({ fullscreen: true });

        expect(classes).toContain('h-[calc(100dvh-2rem)]');
        expect(classes).toContain('grid-rows-[auto_minmax(0,1fr)_auto]');
    });

    it('leaves a normal modal exactly as it was', async () => {
        const classes = await panelClasses({});

        expect(classes).not.toContain('h-[calc(100dvh-2rem)]');
        expect(classes).not.toContain('grid-rows-');
    });
});

describe('KinetixImportModal — the shell follows the file', () => {
    const openImporter = async (settings: Record<string, unknown> = {}) => {
        const w = mount(KinetixImportModal, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        window.dispatchEvent(
            new CustomEvent('kinetix:open-importer', {
                detail: {
                    importer: 'token-123',
                    settings: {
                        hasPreview: true,
                        previewRows: 10,
                        previewColumns: 8,
                        layout: 'auto',
                        fullscreenThreshold: 12,
                        maxUploadSize: 102400,
                        ...settings,
                    },
                },
            }),
        );
        await flushPromises();

        return w;
    };

    beforeEach(() => {
        fetchMock.mockReset();
        document.body.innerHTML = '';
    });

    it('opens as a normal dialog and stays one for a narrow file', async () => {
        const w = await openImporter();

        const importer = w.findComponent({ name: 'KinetixImporter' });
        expect(importer.props('surface')).toBe('modal');

        importer.vm.$emit('update:columns', 6);
        await flushPromises();

        expect(
            w.findComponent({ name: 'KinetixImporter' }).props('surface'),
        ).toBe('modal');
        w.unmount();
    });

    it('promotes ITSELF to full screen once the file is wide', async () => {
        const w = await openImporter();

        // Same dialog, resized — the wizard is not remounted, so nothing the
        // user has already done is lost.
        w.findComponent({ name: 'KinetixImporter' }).vm.$emit(
            'update:columns',
            24,
        );
        await flushPromises();

        expect(
            w.findComponent({ name: 'KinetixModal' }).props('fullscreen'),
        ).toBe(true);
        expect(
            w.findComponent({ name: 'KinetixImporter' }).props('surface'),
        ).toBe('fullscreen');
        w.unmount();
    });

    it('honours a pinned layout instead of escalating', async () => {
        const w = await openImporter({ layout: 'modal' });

        w.findComponent({ name: 'KinetixImporter' }).vm.$emit(
            'update:columns',
            40,
        );
        await flushPromises();

        expect(
            w.findComponent({ name: 'KinetixModal' }).props('fullscreen'),
        ).toBe(false);
        w.unmount();
    });

    it('uses a sheet only when the importer asks for one, decided up front', async () => {
        const w = await openImporter({ layout: 'sheet' });

        expect(w.findComponent({ name: 'KinetixSheet' }).exists()).toBe(true);
        expect(
            w.findComponent({ name: 'KinetixImporter' }).props('surface'),
        ).toBe('sheet');
        w.unmount();
    });
});
