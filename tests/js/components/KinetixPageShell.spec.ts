import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
}));

import KinetixPageShell from '@/components/KinetixPageShell.vue';
import type { KinetixPageData } from '@/types/kinetix';

/**
 * The shell renders a page declared by `Happones\Kinetix\Pages\Page`: header
 * bar, body slot, footer bar. Its whole job is to save unpacking the payload by
 * hand, so these specs pin that it passes each part to the right bar and never
 * paints a bar with nothing in it.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const action = (name: string, label: string) => ({
    name,
    label,
    viewType: 'button',
    shouldOpenInNewTab: false,
    color: null,
    shouldClose: false,
    shouldMarkAsRead: false,
    shouldMarkAsUnread: false,
});

const page = (over: Partial<KinetixPageData> = {}): KinetixPageData =>
    ({
        heading: 'Inventory',
        description: 'Everything you stock.',
        headerActions: [action('import', 'Import')],
        footerActions: [action('save', 'Save')],
        stickyFooter: false,
        ...over,
    }) as KinetixPageData;

const mountShell = (
    p: KinetixPageData,
    slots: Record<string, string> = {
        default: '<div class="kx-body">body</div>',
    },
    props: Record<string, unknown> = {},
) =>
    mount(KinetixPageShell, {
        props: { page: p, ...props },
        slots,
        global: { plugins: [i18n] },
    });

describe('KinetixPageShell', () => {
    it('renders the header, the body and the footer in that order', () => {
        const w = mountShell(page());

        expect(w.find('h1').text()).toBe('Inventory');
        expect(w.text()).toContain('Everything you stock.');
        expect(w.find('.kx-body').exists()).toBe(true);

        const labels = w.findAll('button').map((b) => b.text());
        expect(labels).toEqual(['Import', 'Save']);

        // The body sits between the two bars, not after them.
        const html = w.html();
        expect(html.indexOf('Import')).toBeLessThan(html.indexOf('kx-body'));
        expect(html.indexOf('kx-body')).toBeLessThan(html.indexOf('Save'));
    });

    it('omits a bar that has nothing to show', () => {
        const headerOnly = mountShell(page({ footerActions: [] }));
        expect(headerOnly.findAll('button').map((b) => b.text())).toEqual([
            'Import',
        ]);

        const footerOnly = mountShell(
            page({
                heading: undefined,
                description: undefined,
                headerActions: [],
            }),
        );
        expect(footerOnly.find('h1').exists()).toBe(false);
        expect(footerOnly.findAll('button').map((b) => b.text())).toEqual([
            'Save',
        ]);
    });

    it('renders no chrome at all for a page that declares none', () => {
        const w = mountShell(
            page({
                heading: undefined,
                description: undefined,
                headerActions: [],
                footerActions: [],
            }),
        );

        expect(w.findAll('button')).toHaveLength(0);
        expect(w.find('.kx-body').exists()).toBe(true);
    });

    it('forwards the sticky footer flag from the payload', () => {
        expect(mountShell(page()).html()).not.toContain('sticky');
        expect(mountShell(page({ stickyFooter: true })).html()).toContain(
            'sticky',
        );
    });

    it('keeps a footer that only has slot content, with `alwaysFooter`', () => {
        const w = mountShell(
            page({ footerActions: [] }),
            {
                default: '<div class="kx-body">body</div>',
                'footer-before-actions': 'Draft saved',
            },
            { alwaysFooter: true },
        );

        expect(w.text()).toContain('Draft saved');
    });

    it('passes each slot through to the bar it belongs to', () => {
        const w = mountShell(page(), {
            default: '<div class="kx-body">body</div>',
            'header-before-actions': '<span>hb</span>',
            'header-actions': '<span>ha</span>',
            'footer-before-actions': '<span>fb</span>',
            'footer-actions': '<span>fa</span>',
        });

        for (const marker of ['hb', 'ha', 'fb', 'fa']) {
            expect(w.text()).toContain(marker);
        }
    });
});
