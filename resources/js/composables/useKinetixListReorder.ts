import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { useKinetixTouchDrag } from '@/composables/useKinetixTouchDrag';

let reorderListUid = 0;

/**
 * Return a new array with the item at `from` moved to `to`. Pure so the
 * drag-reorder maths stays unit testable; out-of-range indices are clamped by
 * `splice` semantics and simply yield the array unchanged where nonsensical.
 */
export function moveArrayItem<T>(items: T[], from: number, to: number): T[] {
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);

    return next;
}

export interface UseKinetixListReorderOptions<T> {
    /** Reactive getter for the source list (server rows, field value, …). */
    items: () => T[];
    /** Whether drag reordering is currently allowed. Defaults to enabled. */
    enabled?: () => boolean;
    /**
     * Persist the new order — called once per drop that changed it. Resolve
     * `false` when it was refused: the list goes back to the last order that
     * was saved.
     */
    onCommit: (items: T[]) => void | boolean | Promise<void | boolean>;
}

export interface UseKinetixListReorder<T> {
    /**
     * The list to render from: a local copy that live-previews the reorder
     * while dragging and re-syncs whenever the source changes.
     */
    localItems: Ref<T[]>;
    /**
     * Current index of the in-flight item within `localItems` (its would-be
     * landing position), or null when no reorder drag is active. Style the
     * item at this index as a drop preview.
     */
    draggingIndex: ComputedRef<number | null>;
    onDragStart: (index: number) => void;
    /** Call from `dragover` on each item; previews the move immediately. */
    onDragOver: (index: number, event: DragEvent) => void;
    /** Commit the previewed order. No-op when no reorder drag is active. */
    onDrop: () => Promise<void>;
    /**
     * Call from `dragend`: reverts the preview when the drag ended without a
     * drop (Escape, released outside a drop target). Runs after `drop`, where
     * it is a no-op because the drop already cleared the drag state.
     */
    onDragEnd: () => void;
    /** Move an item programmatically (keyboard alternative) — preview only. */
    moveItem: (from: number, to: number) => void;
    /**
     * Persist the order shown (after `moveItem`), going back to the last
     * saved order when it's refused.
     */
    commit: () => Promise<void>;
    /**
     * Value for each item's `data-kinetix-reorder` attribute: marks it as a
     * place a touch drag can move to, in this list only.
     */
    reorderTarget: (index: number) => string;
    /**
     * Wire to pointerdown on an item's grip, styled `touch-action: none`.
     * Touch and pen drag the item through the list from there with the same
     * live preview as a mouse drag; mouse input is left to native drag-and-drop.
     */
    onGripPointerDown: (index: number, event: PointerEvent) => void;
}

/**
 * Generic drag-to-reorder for a flat list: items render from a local copy that
 * reorders live under the pointer (the dragged item travels through the list
 * as a translucent preview), then the final order is committed once on drop —
 * or reverted when the drag is cancelled. Shared by the table row reorder and
 * the media library grid.
 *
 * Native drag-and-drop never fires on touch screens, so touch and pen drag from
 * the item's grip instead: the drag starts on touch (the grip is a dedicated
 * handle, so no long-press), the item under the finger is found by its
 * `data-kinetix-reorder` attribute, and the page or the list's scroller
 * scrolls near its edges. Letting go off the list puts it back.
 */
export function useKinetixListReorder<T>(
    options: UseKinetixListReorderOptions<T>,
): UseKinetixListReorder<T> {
    const localItems = ref([...options.items()]) as Ref<T[]>;

    // The order last saved: the source's, then every commit that went
    // through. A refused commit or a cancelled drag goes back to it, not to
    // the order the page loaded with.
    let saved = [...options.items()];

    watch(
        () => options.items(),
        (next) => {
            saved = [...next];
            localItems.value = [...next];
        },
    );

    const dragIndex = ref<number | null>(null);
    const draggingIndex = computed(() => dragIndex.value);
    let orderAtStart: T[] = [];

    const onDragStart = (index: number): void => {
        if (options.enabled?.() ?? true) {
            dragIndex.value = index;
            orderAtStart = [...localItems.value];
        }
    };

    const commit = async (): Promise<void> => {
        const order = [...localItems.value];

        if ((await options.onCommit(order)) === false) {
            localItems.value = [...saved];
        } else {
            saved = order;
        }
    };

    /** Live preview: the item in flight takes `index`. */
    const previewMove = (index: number): void => {
        if (dragIndex.value === null || dragIndex.value === index) {
            return;
        }

        localItems.value = moveArrayItem(
            localItems.value,
            dragIndex.value,
            index,
        );
        dragIndex.value = index;
    };

    const onDragOver = (index: number, event: DragEvent): void => {
        if (dragIndex.value === null) {
            return;
        }

        event.preventDefault();
        previewMove(index);
    };

    const onDrop = async (): Promise<void> => {
        if (dragIndex.value === null) {
            return;
        }

        dragIndex.value = null;

        // Let go where it started (a tap on the grip): nothing to save.
        if (
            orderAtStart.length === localItems.value.length &&
            orderAtStart.every((item, i) => item === localItems.value[i])
        ) {
            return;
        }

        await commit();
    };

    const onDragEnd = (): void => {
        if (dragIndex.value === null) {
            return;
        }

        dragIndex.value = null;
        localItems.value = [...saved];
    };

    const moveItem = (from: number, to: number): void => {
        localItems.value = moveArrayItem(localItems.value, from, to);
    };

    // --- Touch / pen (from the grip) --------------------------------------------
    const listId = `kx-reorder-${++reorderListUid}`;

    const reorderTarget = (index: number): string => `${listId}:${index}`;

    /** The index a hit-tested target stands for, or null for another list's. */
    const targetIndex = (key: string | null): number | null => {
        if (!key?.startsWith(`${listId}:`)) {
            return null;
        }

        const index = Number(key.slice(listId.length + 1));

        return Number.isInteger(index) ? index : null;
    };

    const touchDrag = useKinetixTouchDrag<number>({
        targetAttr: 'data-kinetix-reorder',
        activation: 'immediate',
        clone: false,
        scrollAxis: 'y',
        onStart: (index) => onDragStart(index),
        onHover: (key) => {
            const index = targetIndex(key);

            if (index !== null) {
                previewMove(index);
            }
        },
        onDrop: (_index, key) => {
            if (targetIndex(key) === null) {
                onDragEnd();
            } else {
                void onDrop();
            }
        },
        onCancel: () => onDragEnd(),
    });

    const onGripPointerDown = (index: number, event: PointerEvent): void => {
        if (options.enabled?.() ?? true) {
            touchDrag.startFromPointerDown(
                event,
                event.currentTarget as HTMLElement,
                index,
            );
        }
    };

    return {
        localItems,
        draggingIndex,
        commit,
        onDragStart,
        onDragOver,
        onDrop,
        onDragEnd,
        moveItem,
        reorderTarget,
        onGripPointerDown,
    };
}
