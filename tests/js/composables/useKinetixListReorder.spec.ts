import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';

import { useKinetixListReorder } from '@/composables/useKinetixListReorder';

const harness = (
    source: ReturnType<typeof ref<string[]>>,
    onCommit = vi.fn(),
    enabled = () => true,
) => {
    let api!: ReturnType<typeof useKinetixListReorder<string>>;

    const Harness = defineComponent({
        setup() {
            api = useKinetixListReorder<string>({
                items: () => source.value!,
                enabled,
                onCommit,
            });

            return () => h('div');
        },
    });

    mount(Harness);

    return { api, onCommit };
};

const dragEvent = () => ({ preventDefault: vi.fn() }) as unknown as DragEvent;

describe('useKinetixListReorder', () => {
    it('previews the move on dragover and commits once on drop', async () => {
        const source = ref(['a', 'b', 'c']);
        const { api, onCommit } = harness(source);

        api.onDragStart(0);
        api.onDragOver(2, dragEvent());

        // Live preview, tracked index, nothing committed yet.
        expect(api.localItems.value).toEqual(['b', 'c', 'a']);
        expect(api.draggingIndex.value).toBe(2);
        expect(onCommit).not.toHaveBeenCalled();

        await api.onDrop();
        expect(onCommit).toHaveBeenCalledTimes(1);
        expect(onCommit).toHaveBeenCalledWith(['b', 'c', 'a']);
        expect(api.draggingIndex.value).toBeNull();
    });

    it('reverts the preview when the drag ends without a drop', () => {
        const source = ref(['a', 'b', 'c']);
        const { api, onCommit } = harness(source);

        api.onDragStart(0);
        api.onDragOver(2, dragEvent());
        api.onDragEnd();

        expect(api.localItems.value).toEqual(['a', 'b', 'c']);
        expect(api.draggingIndex.value).toBeNull();
        expect(onCommit).not.toHaveBeenCalled();
    });

    it('dragend after a drop is a no-op (state already cleared)', async () => {
        const source = ref(['a', 'b']);
        const { api, onCommit } = harness(source);

        api.onDragStart(0);
        api.onDragOver(1, dragEvent());
        await api.onDrop();
        api.onDragEnd();

        expect(onCommit).toHaveBeenCalledTimes(1);
        expect(api.localItems.value).toEqual(['b', 'a']);
    });

    it('ignores drags while disabled and drops without a drag', async () => {
        const source = ref(['a', 'b']);
        const { api, onCommit } = harness(source, vi.fn(), () => false);

        api.onDragStart(0);
        expect(api.draggingIndex.value).toBeNull();

        api.onDragOver(1, dragEvent());
        expect(api.localItems.value).toEqual(['a', 'b']);

        // A file drop / stray drop with no reorder in flight commits nothing.
        await api.onDrop();
        expect(onCommit).not.toHaveBeenCalled();
    });

    it('re-syncs the local copy when the source changes', async () => {
        const source = ref(['a', 'b']);
        const { api } = harness(source);

        source.value = ['x', 'y', 'z'];
        await nextTick();

        expect(api.localItems.value).toEqual(['x', 'y', 'z']);
    });
});
