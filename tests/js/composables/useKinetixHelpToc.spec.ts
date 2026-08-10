import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';

import { useKinetixHelpToc } from '@/composables/useKinetixHelpToc';

const buildWith = (html: string) => {
    const el = document.createElement('article');
    el.innerHTML = html;
    document.body.appendChild(el);

    const contentEl = ref<HTMLElement | null>(el);
    let api: ReturnType<typeof useKinetixHelpToc>;

    const Harness = defineComponent({
        setup() {
            api = useKinetixHelpToc(contentEl);

            return () => h('div');
        },
    });

    mount(Harness);
    api!.build();

    return api!;
};

describe('useKinetixHelpToc', () => {
    it('assigns slugified ids to h2/h3 and builds the entries', () => {
        const api = buildWith(
            '<h2>Getting Started</h2><p>x</p><h3>First Steps!</h3><h2>Search &amp; Filters</h2>',
        );

        expect(api.toc.value).toEqual([
            { id: 'getting-started', text: 'Getting Started', level: 2 },
            { id: 'first-steps', text: 'First Steps!', level: 3 },
            { id: 'search-filters', text: 'Search & Filters', level: 2 },
        ]);
        expect(api.activeId.value).toBe('getting-started');
    });

    it('deduplicates repeated headings', () => {
        const api = buildWith('<h2>Usage</h2><h2>Usage</h2><h2>Usage</h2>');

        expect(api.toc.value.map((entry) => entry.id)).toEqual([
            'usage',
            'usage-2',
            'usage-3',
        ]);
    });

    it('keeps non-English headings addressable', () => {
        const api = buildWith(
            '<h2>Configuración</h2><h2>Электронная почта</h2><h2>設定</h2><h2>الإعدادات</h2>',
        );

        // Accents fold away; other scripts survive instead of collapsing to
        // one meaningless id (what an ASCII-only `\w` rule would produce).
        expect(api.toc.value.map((entry) => entry.id)).toEqual([
            'configuracion',
            'электронная-почта',
            '設定',
            'الإعدادات',
        ]);
    });

    it('keeps an existing id and clears when no content element', () => {
        const api = buildWith('<h2 id="custom">Custom</h2>');

        expect(api.toc.value[0].id).toBe('custom');
    });

    it('scrollTo activates the entry and scrolls the heading', () => {
        const api = buildWith('<h2>One</h2><h2>Two</h2>');
        const spy = vi.fn();
        Element.prototype.scrollIntoView = spy;

        api.scrollTo('two');

        expect(api.activeId.value).toBe('two');
        expect(spy).toHaveBeenCalled();
    });
});
