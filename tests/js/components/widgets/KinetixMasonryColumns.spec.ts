import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
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

    describe('measuring', () => {
        let instances: FakeObserver[] = [];

        class FakeObserver {
            callback: ResizeObserverCallback;
            observed = new Set<Element>();

            constructor(callback: ResizeObserverCallback) {
                this.callback = callback;
                instances.push(this);
            }

            observe(el: Element): void {
                this.observed.add(el);
            }

            unobserve(el: Element): void {
                this.observed.delete(el);
            }

            disconnect(): void {
                this.observed.clear();
            }

            report(el: Element, height: number): void {
                this.callback(
                    [{ target: el, contentRect: { height } } as any],
                    this as any,
                );
            }
        }

        afterEach(() => {
            instances = [];
            vi.unstubAllGlobals();
        });

        const mountMeasured = () => {
            vi.stubGlobal('ResizeObserver', FakeObserver);

            return mount(KinetixMasonryColumns, {
                props: { widgets, columns: 2, gap: 0 },
                slots: {
                    item: `<template #item="{ widget }"><div class="item" :data-id="widget.id">{{ widget.id }}</div></template>`,
                },
            });
        };

        it('watches every item with one observer, kept across re-renders', async () => {
            const wrapper = mountMeasured();

            await wrapper.setProps({ gap: 4 });

            expect(instances).toHaveLength(1);
            expect(instances[0].observed.size).toBe(3);
        });

        it('applies a measured height on the next frame, not inside the observer callback', async () => {
            const frames: FrameRequestCallback[] = [];
            vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) =>
                frames.push(cb),
            );

            const wrapper = mountMeasured();
            const first = wrapper.find('[data-id="a"]').element.parentElement!;

            instances[0].report(first, 300);
            await wrapper.vm.$nextTick();

            expect(wrapper.attributes('style')).toContain('height: 0px');

            frames.forEach((cb) => cb(0));
            await wrapper.vm.$nextTick();

            expect(wrapper.attributes('style')).toContain('height: 300px');
        });
    });
});
