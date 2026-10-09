import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
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

    it('a drop where the drag started saves nothing', async () => {
        const source = ref(['a', 'b', 'c']);
        const { api, onCommit } = harness(source);

        api.onDragStart(1);
        api.onDragOver(2, dragEvent());
        api.onDragOver(1, dragEvent());
        await api.onDrop();

        expect(onCommit).not.toHaveBeenCalled();
        expect(api.draggingIndex.value).toBeNull();
    });

    it('a refused commit goes back to the last saved order, not the first', async () => {
        const source = ref(['a', 'b', 'c']);
        const onCommit = vi.fn().mockResolvedValueOnce(true);
        const { api } = harness(source, onCommit);

        api.onDragStart(0);
        api.onDragOver(2, dragEvent());
        await api.onDrop();
        expect(api.localItems.value).toEqual(['b', 'c', 'a']);

        onCommit.mockResolvedValueOnce(false);
        api.onDragStart(0);
        api.onDragOver(1, dragEvent());
        await api.onDrop();
        expect(api.localItems.value).toEqual(['b', 'c', 'a']);

        // A cancelled drag goes back there too.
        api.onDragStart(0);
        api.onDragOver(2, dragEvent());
        api.onDragEnd();
        expect(api.localItems.value).toEqual(['b', 'c', 'a']);
    });

    it('re-syncs the local copy when the source changes', async () => {
        const source = ref(['a', 'b']);
        const { api } = harness(source);

        source.value = ['x', 'y', 'z'];
        await nextTick();

        expect(api.localItems.value).toEqual(['x', 'y', 'z']);
    });

    describe('touch, from the grip', () => {
        const elementFromPoint = document.elementFromPoint;

        afterEach(() => {
            document.elementFromPoint = elementFromPoint;
            document.body.innerHTML = '';
        });

        const touch = (type: string, pointerType = 'touch'): PointerEvent =>
            new PointerEvent(type, {
                bubbles: true,
                cancelable: true,
                pointerType,
                isPrimary: true,
            });

        /** Render the list's drop targets and put the finger over one. */
        const items = (api: ReturnType<typeof harness>['api'], count: number) =>
            Array.from({ length: count }, (_, index) => {
                const el = document.createElement('div');
                el.setAttribute(
                    'data-kinetix-reorder',
                    api.reorderTarget(index),
                );
                document.body.append(el);

                return el;
            });

        const fingerOver = (el: Element | null): void => {
            document.elementFromPoint = vi.fn(() => el);
        };

        const grip = (
            index: number,
            api: ReturnType<typeof harness>['api'],
        ) => {
            const button = document.createElement('button');
            document.body.append(button);
            button.addEventListener('pointerdown', (e) =>
                api.onGripPointerDown(index, e as PointerEvent),
            );

            return button;
        };

        it('drags the item through the list under the finger and commits on release', async () => {
            const source = ref(['a', 'b', 'c']);
            const { api, onCommit } = harness(source);
            const targets = items(api, 3);

            fingerOver(targets[0]);
            grip(0, api).dispatchEvent(touch('pointerdown'));
            expect(api.draggingIndex.value).toBe(0);

            fingerOver(targets[2]);
            window.dispatchEvent(touch('pointermove'));
            expect(api.localItems.value).toEqual(['b', 'c', 'a']);
            expect(onCommit).not.toHaveBeenCalled();

            window.dispatchEvent(touch('pointerup'));
            await nextTick();

            expect(onCommit).toHaveBeenCalledWith(['b', 'c', 'a']);
            expect(api.draggingIndex.value).toBeNull();
        });

        it('a tap on the grip saves nothing', async () => {
            const source = ref(['a', 'b', 'c']);
            const { api, onCommit } = harness(source);
            const targets = items(api, 3);

            fingerOver(targets[0]);
            grip(0, api).dispatchEvent(touch('pointerdown'));
            window.dispatchEvent(touch('pointerup'));
            await nextTick();

            expect(onCommit).not.toHaveBeenCalled();
            expect(api.draggingIndex.value).toBeNull();
        });

        it('letting go off the list puts the item back', () => {
            const source = ref(['a', 'b', 'c']);
            const { api, onCommit } = harness(source);
            const targets = items(api, 3);

            fingerOver(targets[0]);
            grip(0, api).dispatchEvent(touch('pointerdown'));
            fingerOver(targets[2]);
            window.dispatchEvent(touch('pointermove'));
            fingerOver(null);
            window.dispatchEvent(touch('pointermove'));
            window.dispatchEvent(touch('pointerup'));

            expect(api.localItems.value).toEqual(['a', 'b', 'c']);
            expect(onCommit).not.toHaveBeenCalled();
        });

        it('a cancelled gesture puts the item back', () => {
            const source = ref(['a', 'b', 'c']);
            const { api, onCommit } = harness(source);
            const targets = items(api, 3);

            fingerOver(targets[0]);
            grip(0, api).dispatchEvent(touch('pointerdown'));
            fingerOver(targets[1]);
            window.dispatchEvent(touch('pointermove'));
            window.dispatchEvent(touch('pointercancel'));

            expect(api.localItems.value).toEqual(['a', 'b', 'c']);
            expect(api.draggingIndex.value).toBeNull();
            expect(onCommit).not.toHaveBeenCalled();
        });

        it("never moves into another list's items", () => {
            const mine = harness(ref(['a', 'b']));
            const other = harness(ref(['x', 'y']));
            const theirs = items(other.api, 2);
            const ours = items(mine.api, 2);

            fingerOver(ours[0]);
            grip(0, mine.api).dispatchEvent(touch('pointerdown'));
            fingerOver(theirs[1]);
            window.dispatchEvent(touch('pointermove'));
            window.dispatchEvent(touch('pointerup'));

            expect(mine.api.localItems.value).toEqual(['a', 'b']);
            expect(mine.onCommit).not.toHaveBeenCalled();
            expect(other.api.localItems.value).toEqual(['x', 'y']);
        });

        it('leaves the mouse to native drag-and-drop, and does nothing while disabled', () => {
            const enabled = harness(ref(['a', 'b']));
            items(enabled.api, 2);
            grip(0, enabled.api).dispatchEvent(touch('pointerdown', 'mouse'));
            expect(enabled.api.draggingIndex.value).toBeNull();

            const disabled = harness(ref(['a', 'b']), vi.fn(), () => false);
            grip(0, disabled.api).dispatchEvent(touch('pointerdown'));
            expect(disabled.api.draggingIndex.value).toBeNull();
        });
    });
});
