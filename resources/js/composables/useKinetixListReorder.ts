import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';

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
    /** Persist the new order — called once, on drop. */
    onCommit: (items: T[]) => void | Promise<void>;
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
}

/**
 * Generic drag-to-reorder for a flat list: items render from a local copy that
 * reorders live under the pointer (the dragged item travels through the list
 * as a translucent preview), then the final order is committed once on drop —
 * or reverted when the drag is cancelled. Shared by the table row reorder and
 * the media library grid.
 */
export function useKinetixListReorder<T>(
    options: UseKinetixListReorderOptions<T>,
): UseKinetixListReorder<T> {
    const localItems = ref([...options.items()]) as Ref<T[]>;

    watch(
        () => options.items(),
        (next) => {
            localItems.value = [...next];
        },
    );

    const dragIndex = ref<number | null>(null);
    const draggingIndex = computed(() => dragIndex.value);

    const onDragStart = (index: number): void => {
        if (options.enabled?.() ?? true) {
            dragIndex.value = index;
        }
    };

    const onDragOver = (index: number, event: DragEvent): void => {
        if (dragIndex.value === null) {
            return;
        }

        event.preventDefault();

        if (dragIndex.value === index) {
            return;
        }

        localItems.value = moveArrayItem(
            localItems.value,
            dragIndex.value,
            index,
        );
        dragIndex.value = index;
    };

    const onDrop = async (): Promise<void> => {
        if (dragIndex.value === null) {
            return;
        }

        dragIndex.value = null;
        await options.onCommit([...localItems.value]);
    };

    const onDragEnd = (): void => {
        if (dragIndex.value === null) {
            return;
        }

        dragIndex.value = null;
        localItems.value = [...options.items()];
    };

    const moveItem = (from: number, to: number): void => {
        localItems.value = moveArrayItem(localItems.value, from, to);
    };

    return {
        localItems,
        draggingIndex,
        onDragStart,
        onDragOver,
        onDrop,
        onDragEnd,
        moveItem,
    };
}
