import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';

import KinetixInfolist from '@/components/KinetixInfolist.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: { en: { kinetix: { copy: 'Copy' } } },
});

const mountInfolist = (
    schema: Array<Record<string, unknown>>,
    props: Record<string, unknown> = {},
) =>
    mount(KinetixInfolist, {
        props: { infolist: { columns: 2, schema } as any, ...props },
        global: { plugins: [i18n] },
    });

describe('KinetixInfolist surface', () => {
    it('wraps a BARE schema in a card so detail pages never render floating entries', () => {
        const w = mountInfolist([
            { type: 'text', name: 'title', label: 'Title', state: 'Hello' },
        ]);

        expect(w.find('.kinetix-grid-host').classes()).toContain('bg-card');
        expect(w.text()).toContain('Hello');
    });

    it('never wraps a schema that brings its own layout (Section owns the surface)', () => {
        const w = mountInfolist([
            {
                type: 'section',
                heading: 'Details',
                schema: [{ type: 'text', name: 't', label: 'T', state: 'x' }],
            },
        ]);

        expect(w.find('.kinetix-grid-host').classes()).not.toContain('bg-card');
        // The section itself renders the card.
        expect(w.find('.bg-card').exists()).toBe(true);
    });

    it('surface=false renders bare entries with no card (modal hosts own the surface)', () => {
        const w = mountInfolist(
            [{ type: 'text', name: 'title', label: 'Title', state: 'Hello' }],
            { surface: false },
        );

        expect(w.find('.bg-card').exists()).toBe(false);
    });
});

describe('KinetixInfolist flat mode (view modals)', () => {
    it('flat drops Section card chrome while keeping heading and entries', () => {
        const w = mountInfolist(
            [
                {
                    type: 'section',
                    heading: 'Details',
                    schema: [
                        { type: 'text', name: 't', label: 'T', state: 'x' },
                    ],
                },
            ],
            { surface: false, flat: true },
        );

        expect(w.find('.bg-card').exists()).toBe(false);
        expect(w.find('.shadow-sm').exists()).toBe(false);
        expect(w.get('h3').text()).toBe('Details');
        expect(w.text()).toContain('x');
    });

    it('flat implies no bare-schema wrap even with surface left on', () => {
        const w = mountInfolist(
            [{ type: 'text', name: 'title', label: 'Title', state: 'Hello' }],
            { flat: true },
        );

        expect(w.find('.bg-card').exists()).toBe(false);
    });
});

describe('KinetixInfolist key-value & repeatable entries', () => {
    it('renders a key-value entry as key → value rows with optional headers', () => {
        const w = mountInfolist([
            {
                type: 'key-value',
                name: 'meta',
                label: 'Meta',
                keyLabel: 'Property',
                valueLabel: 'Value',
                state: { color: 'red', size: 'L' },
            },
        ]);

        const text = w.text();
        expect(text).toContain('Property');
        expect(text).toContain('Value');
        expect(text).toContain('color');
        expect(text).toContain('red');
        expect(text).toContain('size');
        expect(text).toContain('L');
    });

    it('renders one block per item for a repeatable entry', () => {
        const w = mountInfolist([
            {
                type: 'repeatable',
                name: 'lines',
                label: 'Lines',
                gridColumns: 2,
                repeatableItems: [
                    [
                        {
                            type: 'text',
                            name: 'name',
                            label: 'Name',
                            state: 'Widget',
                        },
                        { type: 'text', name: 'qty', label: 'Qty', state: 2 },
                    ],
                    [
                        {
                            type: 'text',
                            name: 'name',
                            label: 'Name',
                            state: 'Gadget',
                        },
                        { type: 'text', name: 'qty', label: 'Qty', state: 5 },
                    ],
                ],
            },
        ]);

        const text = w.text();
        expect(text).toContain('Widget');
        expect(text).toContain('Gadget');
        expect(text).toContain('2');
        expect(text).toContain('5');
    });

    it('shows a placeholder for an empty repeatable entry', () => {
        const w = mountInfolist([
            {
                type: 'repeatable',
                name: 'lines',
                label: 'Lines',
                placeholder: 'No lines',
                repeatableItems: [],
            },
        ]);

        expect(w.text()).toContain('No lines');
    });
});
