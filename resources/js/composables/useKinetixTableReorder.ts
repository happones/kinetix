import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import { useKinetixListReorder } from '@/composables/useKinetixListReorder';
import type { KinetixTableRecord } from '@/types/kinetix';

export { moveArrayItem } from '@/composables/useKinetixListReorder';

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
    /** Id of the row in flight — style it as the drop preview. */
    draggingId: ComputedRef<string | number | null>;
    onDragStart: (index: number) => void;
    onDragOver: (index: number, event: DragEvent) => void;
    onDrop: () => Promise<void>;
    /** Reverts the preview when the drag ends without a drop (cancelled). */
    onDragEnd: () => void;
    /**
     * Keyboard alternative to dragging: move the row at `index` by `delta`
     * positions. Returns the new index, or null when the move is out of range.
     * Persistence is debounced so arrow-key bursts cost one request.
     */
    moveRowBy: (index: number, delta: number) => number | null;
}

/**
 * Drag-to-reorder for a Kinetix table. Rows iterate a local copy while dragging
 * so previews are instant (the in-flight row travels through the list as a
 * translucent preview), then the new id order is persisted on drop. The local
 * copy re-syncs whenever the server ships a fresh row set, and reverts when
 * the drag is cancelled.
 */
export function useKinetixTableReorder(
    options: UseKinetixTableReorderOptions,
): UseKinetixTableReorder {
    const persistOrder = async (
        records: KinetixTableRecord[],
    ): Promise<void> => {
        try {
            await kinetixFetch(`/${options.routePrefix()}/tables/reorder`, {
                method: 'POST',
                body: {
                    model: options.model(),
                    ids: records.map((r) => r.id),
                },
            });
        } catch (e) {
            console.error('Reorder failed:', e);
        }
    };

    const list = useKinetixListReorder<KinetixTableRecord>({
        items: () => options.records(),
        enabled: () => options.reorderable(),
        onCommit: persistOrder,
    });

    const rows = computed<KinetixTableRecord[]>(() =>
        options.reorderable() ? list.localItems.value : options.records(),
    );

    const draggingId = computed<string | number | null>(() =>
        list.draggingIndex.value !== null
            ? (list.localItems.value[list.draggingIndex.value]?.id ?? null)
            : null,
    );

    let persistTimer: ReturnType<typeof setTimeout> | null = null;

    const moveRowBy = (index: number, delta: number): number | null => {
        const target = index + delta;

        if (
            !options.reorderable() ||
            target < 0 ||
            target >= list.localItems.value.length
        ) {
            return null;
        }

        list.moveItem(index, target);

        if (persistTimer !== null) {
            clearTimeout(persistTimer);
        }

        persistTimer = setTimeout(() => {
            persistTimer = null;
            void persistOrder(list.localItems.value);
        }, 600);

        return target;
    };

    return {
        rows,
        draggingId,
        onDragStart: list.onDragStart,
        onDragOver: list.onDragOver,
        onDrop: list.onDrop,
        onDragEnd: list.onDragEnd,
        moveRowBy,
    };
}
