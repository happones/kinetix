import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import type * as VirtualRowsModule from '@/composables/useKinetixVirtualRows';

type Composable = typeof VirtualRowsModule.useKinetixVirtualRows;

/** Counts how often the engine module is actually evaluated (downloaded). */
const engineLoads = vi.fn();

let useKinetixVirtualRows: Composable;

beforeEach(async () => {
    // A fresh composable module per case: the engine is cached module-wide.
    vi.resetModules();
    engineLoads.mockClear();
    vi.doMock('@tanstack/vue-virtual', async (importOriginal) => {
        engineLoads();

        return importOriginal();
    });
    ({ useKinetixVirtualRows } =
        await import('@/composables/useKinetixVirtualRows'));
});

afterEach(() => {
    vi.doUnmock('@tanstack/vue-virtual');
});

const mountRows = (initialCount: number, threshold: number) => {
    let api: ReturnType<Composable>;
    const count = ref(initialCount);

    const Harness = defineComponent({
        setup() {
            const scrollEl = ref<HTMLElement | null>(null);
            api = useKinetixVirtualRows({
                count: () => count.value,
                getScrollElement: () => scrollEl.value,
                estimateSize: 40,
                threshold,
            });

            return () => h('div', { ref: scrollEl });
        },
    });

    const wrapper = mount(Harness);

    return { wrapper, count, api: api! };
};

describe('useKinetixVirtualRows threshold gate', () => {
    it('stays disabled for lists at or below the threshold, and never loads the engine', async () => {
        const { api } = mountRows(10, 40);
        await flushPromises();

        expect(api.enabled.value).toBe(false);
        // Disabled → the component renders every row itself, so no window.
        expect(api.virtualRows.value).toEqual([]);
        expect(api.totalSize.value).toBe(0);
        expect(engineLoads).not.toHaveBeenCalled();
    });

    it('enables virtualization once the engine has loaded for a list over the threshold', async () => {
        const { api } = mountRows(500, 40);

        // Over the threshold, but the engine is still downloading: every row
        // renders as usual until it arrives.
        expect(api.enabled.value).toBe(false);
        expect(api.totalSize.value).toBe(0);

        await vi.waitFor(() => expect(api.enabled.value).toBe(true));

        expect(engineLoads).toHaveBeenCalledTimes(1);
        // Total scroll size reflects all rows (count × estimate), independent
        // of layout — the visible window itself needs a real layout engine,
        // which jsdom/happy-dom lacks, so it isn't asserted here.
        expect(api.totalSize.value).toBe(500 * 40);
    });

    it('loads the engine when a small list grows past the threshold', async () => {
        const { api, count } = mountRows(10, 40);
        await flushPromises();
        expect(engineLoads).not.toHaveBeenCalled();

        count.value = 100;

        await vi.waitFor(() => expect(api.enabled.value).toBe(true));
        expect(api.totalSize.value).toBe(100 * 40);

        count.value = 5;
        await flushPromises();
        expect(api.enabled.value).toBe(false);
        expect(api.totalSize.value).toBe(0);
    });

    it('drops a download that lands after unmount', async () => {
        const { api, wrapper } = mountRows(500, 40);

        wrapper.unmount();
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(api.enabled.value).toBe(false);
    });
});
