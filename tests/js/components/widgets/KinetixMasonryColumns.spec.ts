import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KinetixMasonryColumns from '@/components/widgets/KinetixMasonryColumns.vue';

const widgets = [
    { id: 'a', columnSpan: 12, sort: 0, type: 'stats' as const, data: {} },
    { id: 'b', columnSpan: 12, sort: 1, type: 'stats' as const, data: {} },
    { id: 'c', columnSpan: 12, sort: 2, type: 'stats' as const, data: {} },
];

describe('KinetixMasonryColumns', () => {
    it('distributes every widget across the requested column count via the #item slot', () => {
        const wrapper = mount(KinetixMasonryColumns, {
            props: { widgets, columns: 2, gap: '1rem' },
            slots: {
                item: `<template #item="{ widget }"><div class="item">{{ widget.id }}</div></template>`,
            },
        });

        const items = wrapper.findAll('.item');

        // Rendered column-major (all of column 0, then column 1, ...), so
        // with a round-robin pack across 2 columns it's [a, c] then [b].
        expect(items).toHaveLength(3);
        expect(items.map((i) => i.text()).sort()).toEqual(['a', 'b', 'c']);
    });

    it('sizes the grid container to the requested column count', () => {
        const wrapper = mount(KinetixMasonryColumns, {
            props: { widgets, columns: 3, gap: 8 },
            slots: {
                item: `<template #item="{ widget }"><div class="item">{{ widget.id }}</div></template>`,
            },
        });

        expect(wrapper.attributes('style')).toContain(
            'grid-template-columns: repeat(3, minmax(0, 1fr))',
        );
        // Every widget renders once, in a single flat list.
        expect(wrapper.findAll('.item')).toHaveLength(3);
    });

    it('applies the resolved gap as inline column-gap style', () => {
        const wrapper = mount(KinetixMasonryColumns, {
            props: { widgets, columns: 2, gap: 12 },
            slots: {
                item: `<template #item="{ widget }"><div>{{ widget.id }}</div></template>`,
            },
        });

        expect(wrapper.attributes('style')).toContain('column-gap: 12px');
    });

    it('does not throw when ResizeObserver is unavailable (jsdom default)', () => {
        expect(() =>
            mount(KinetixMasonryColumns, {
                props: { widgets, columns: 2, gap: '1rem' },
                slots: {
                    item: `<template #item="{ widget }"><div>{{ widget.id }}</div></template>`,
                },
            }),
        ).not.toThrow();
    });

    it('renders an empty, zero-height container for an empty widget list', () => {
        const wrapper = mount(KinetixMasonryColumns, {
            props: { widgets: [], columns: 4, gap: '1rem' },
            slots: {
                item: `<template #item="{ widget }"><div class="item">{{ widget.id }}</div></template>`,
            },
        });

        expect(wrapper.attributes('style')).toContain('height: 0px');
        expect(wrapper.findAll('.item')).toHaveLength(0);
    });

    it('never unmounts a widget node when a re-pack moves it to a different column', async () => {
        const wrapper = mount(KinetixMasonryColumns, {
            props: { widgets, columns: 2, gap: '1rem' },
            slots: {
                item: `<template #item="{ widget }"><div class="item" :data-id="widget.id">{{ widget.id }}</div></template>`,
            },
        });

        const before = wrapper.find('[data-id="a"]').element;

        // Simulate late-arriving height measurements re-triggering the pack.
        await wrapper.setProps({ widgets: [...widgets].reverse() });

        const after = wrapper.find('[data-id="a"]').element;

        expect(after).toBe(before);
    });
});
