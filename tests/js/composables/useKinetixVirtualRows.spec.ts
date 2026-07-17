import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { useKinetixVirtualRows } from '@/composables/useKinetixVirtualRows';

const mountRows = (count: number, threshold: number) => {
    let api: ReturnType<typeof useKinetixVirtualRows>;

    const Harness = defineComponent({
        setup() {
            const scrollEl = ref<HTMLElement | null>(null);
            api = useKinetixVirtualRows({
                count: () => count,
                getScrollElement: () => scrollEl.value,
                estimateSize: 40,
                threshold,
            });

            return () => h('div', { ref: scrollEl });
        },
    });

    const wrapper = mount(Harness);

    return { wrapper, api: api! };
};

describe('useKinetixVirtualRows threshold gate', () => {
    it('stays disabled for lists at or below the threshold', () => {
        const { api } = mountRows(10, 40);

        expect(api.enabled.value).toBe(false);
        // Disabled → the component renders every row itself, so no window.
        expect(api.virtualRows.value).toEqual([]);
        expect(api.totalSize.value).toBe(0);
    });

    it('enables virtualization once the list exceeds the threshold', () => {
        const { api } = mountRows(500, 40);

        expect(api.enabled.value).toBe(true);
        // Total scroll size reflects all rows (count × estimate), independent
        // of layout — the visible window itself needs a real layout engine,
        // which jsdom/happy-dom lacks, so it isn't asserted here.
        expect(api.totalSize.value).toBe(500 * 40);
    });
});
