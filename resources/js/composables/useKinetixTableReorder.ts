import { computed, ref, watch } from 'vue';
import type { ComputedRef } from 'vue';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import type { KinetixTableRecord } from '@/types';

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

export interface UseKinetixTableReorderOptions {
    /** Reactive getter for the server-provided row set. */
    records: () => KinetixTableRecord[];
    /** Whether drag reordering is enabled for this table. */
    reorderable: () => boolean;
    /** The table's model key, sent when persisting a new order. */
    model: () => string;
    /** The Kinetix route prefix (e.g. `_kinetix`). */
    routePrefix: () => string;
}

export interface UseKinetixTableReorder {
    /** Rows to iterate: a local copy while reorderable, the source otherwise. */
    rows: ComputedRef<KinetixTableRecord[]>;
    onDragStart: (index: number) => void;
    onDragOver: (index: number, event: DragEvent) => void;
    onDrop: () => Promise<void>;
}

/**
 * Drag-to-reorder for a Kinetix table. Rows iterate a local copy while dragging
 * so previews are instant, then the new id order is persisted on drop. The
 * local copy re-syncs whenever the server ships a fresh row set.
 */
export function useKinetixTableReorder(
    options: UseKinetixTableReorderOptions,
): UseKinetixTableReorder {
    const localRecords = ref<KinetixTableRecord[]>([...options.records()]);

    watch(
        () => options.records(),
        (next) => {
            localRecords.value = [...next];
        },
    );

    const rows = computed<KinetixTableRecord[]>(() =>
        options.reorderable() ? localRecords.value : options.records(),
    );

    let dragIndex: number | null = null;

    const onDragStart = (index: number): void => {
        dragIndex = index;
    };

    const onDragOver = (index: number, event: DragEvent): void => {
        event.preventDefault();

        if (dragIndex === null || dragIndex === index) {
            return;
        }

        localRecords.value = moveArrayItem(
            localRecords.value,
            dragIndex,
            index,
        );
        dragIndex = index;
    };

    const onDrop = async (): Promise<void> => {
        dragIndex = null;

        try {
            await kinetixFetch(`/${options.routePrefix()}/tables/reorder`, {
                method: 'POST',
                body: {
                    model: options.model(),
                    ids: localRecords.value.map((r) => r.id),
                },
            });
        } catch (e) {
            console.error('Reorder failed:', e);
        }
    };

    return { rows, onDragStart, onDragOver, onDrop };
}
